<?php

namespace Bitrix\Mail\Helper;

use Bitrix\Mail\Integration\Calendar\ICal\ICalMailManager;
use Bitrix\Mail\Internal\Service\Message\Conversation\ConversationBackfill;
use Bitrix\Mail\Internal\Service\SourceGeneration\GenerationScope;
use Bitrix\Mail\Internals\MailMessageAttachmentTable;
use Bitrix\Mail\Internals\MessageAccessTable;
use Bitrix\Mail\MailboxTable;
use Bitrix\Mail\MailMessageTable;
use Bitrix\Mail\Public\Service\Access\MessageAccessService;
use Bitrix\Main;
use Bitrix\Mail\Internals;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Config\Ini;
use Bitrix\Main\Mail\Converter;
use Bitrix\Mail\Helper\Message\Parsers;
use Bitrix\Main\Engine\UrlManager;
use Bitrix\Main\Web\Uri;

class Message
{
	private const ORIGINAL_RECIPIENT_SEARCH_TOKEN_PREFIX = 'xrcpt';

	// address columns are varchar(255), a value of exactly this length is a truncated one
	private const ADDRESS_FIELD_MAX_LENGTH = 255;

	private const ADDRESS_FIELDS_MAP = [
		'__from' => 'FIELD_FROM',
		'__reply_to' => 'FIELD_REPLY_TO',
		'__to' => 'FIELD_TO',
		'__cc' => 'FIELD_CC',
		'__bcc' => 'FIELD_BCC',
		'__rcpt' => 'FIELD_RCPT',
	];

	// entity types with special access rules (group tokens)
	public const ENTITY_TYPE_USER_MESSAGE = MessageAccessTable::ENTITY_TYPE_USER_MESSAGE;
	public const ENTITY_TYPE_CHAT_MESSAGE = MessageAccessTable::ENTITY_TYPE_CHAT_MESSAGE;
	public const ENTITY_TYPE_IM_CHAT = MessageAccessTable::ENTITY_TYPE_IM_CHAT;
	public const ENTITY_TYPE_CALENDAR_EVENT = MessageAccessTable::ENTITY_TYPE_CALENDAR_EVENT;
	private const MAX_FILE_SIZE_MAIL_ATTACHMENT = 20000000;
	private const DISPLAY_SUBJECT_MAX_LENGTH = 60;

	private static array $countersForUsersCache = [];

	public static function getMaxAttachedFilesSize()
	{
		// @TODO: Until the 'Main' module update of is released with the reset of the option for the cloud
		if (IsModuleInstalled('bitrix24'))
		{
			return self::MAX_FILE_SIZE_MAIL_ATTACHMENT;
		}
		else
		{
			return (int)Option::get('main', 'max_file_size');
		}
	}

	public static function getMaxAttachedFilesSizeAfterEncoding()
	{
		return floor(static::getMaxAttachedFilesSize()/4)*3;
	}

	/**
	 * Returns a whitelist of attributes for the html sanitizer
	 * (\CBXSanitizer::SanitizeHtml)
	 *
	 * @return array
	 */
	public static function getWhitelistTagAttributes()
	{
		$validTagAttributes = [
			'colspan',
			'border',
			'bgcolor',
			'width',
			'background',
			'style',
			'align',
			'height',
			'background-color',
			'border',
			'ltr',
			'rtl',
			'class',
		];

		$tableAttributes = array_merge(
			$validTagAttributes,
			[
				'cellpadding',
				'cellspacing',
			]
		);

		$tdAttributes = array_merge(
			$validTagAttributes,
			[
				'rowspan',
			]
		);

		return [
			'style' => [],
			'colgroup' => [],
			'col' => ['width'],
			'img' => array_merge(
				$validTagAttributes,
				[
					'src',
					'alt',
					'title',
					'data-bx-mail-signature-image',
					'data-bx-mail-signature-image-size',
				],
			),
			'table' => $tableAttributes,
			'center' => $validTagAttributes,
			'div' => $validTagAttributes,
			'td' =>$tdAttributes,
		];
	}

	private static function prepareField($value)
	{
		if (trim($value))
		{
			$address = new Main\Mail\Address($value);

			if ($address->validate())
			{
				return [
					'name' => $address->getName(),
					'email' => $address->getEmail(),
					'formated' => ($address->getName() ? $address->get() : $address->getEmail()),
				];
			}
			else
			{
				return [
					'name'  => $value,
				];
			}
		}

		return false;
	}

	private static function checkMessageIsOutcomeByField($message,$keyField,$value)
	{
		if (trim($value))
		{
			$isFromField = in_array($keyField, ['__from', '__reply_to']);
			$address = new Main\Mail\Address($value);

			if ($address->validate())
			{
				if ($isFromField && $address->getEmail() == $message['__email'])
				{
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Returns Bcc recipients from a parsed message header.
	 */
	public static function getBccFromParsedHeader(\CMailHeader $parsedHeader): string
	{
		return join(', ', (array)$parsedHeader->getHeader('BCC'));
	}

	/**
	 * Returns envelope recipients from a parsed message header.
	 */
	public static function getOriginalRecipientsFromParsedHeader(\CMailHeader $parsedHeader): string
	{
		return join(', ', (array)$parsedHeader->getHeader('X-Original-Rcpt-to'));
	}

	/**
	 * Returns envelope recipients from a raw message header.
	 */
	public static function getOriginalRecipientsFromHeader(string $header): string
	{
		if ($header === '')
		{
			return '';
		}

		$parsedHeader = \CMailMessage::parseHeader($header, LANG_CHARSET);

		return self::getOriginalRecipientsFromParsedHeader($parsedHeader);
	}

	/**
	 * Restores address fields that were truncated on save and the legacy Bcc, reading the raw header.
	 * Returns the row unchanged when there is no header or nothing to restore.
	 *
	 * @param array $row row of Mail\MailMessageTable with HEADER and FIELD_* columns.
	 *
	 * @return array row with restored address fields.
	 */
	public static function restoreAddressFieldsFromHeader(array $row): array
	{
		$header = (string)($row['HEADER'] ?? '');
		if ($header === '')
		{
			return $row;
		}

		$restoreAddressFields = false;
		foreach (self::ADDRESS_FIELDS_MAP as $field)
		{
			if (mb_strlen((string)($row[$field] ?? '')) === self::ADDRESS_FIELD_MAX_LENGTH)
			{
				$restoreAddressFields = true;

				break;
			}
		}

		$legacyOriginalRecipientInBcc = false;
		if (!$restoreAddressFields && (string)($row['FIELD_BCC'] ?? '') !== '')
		{
			$originalRecipientHeaders = [];
			preg_match_all(
				'/(?:^|\r?\n)X-Original-Rcpt-to\s*:\s*([^\r\n]+)/i',
				$header,
				$originalRecipientHeaders,
			);
			foreach ($originalRecipientHeaders[1] ?? [] as $originalRecipient)
			{
				$originalRecipient = trim($originalRecipient);
				if (
					$originalRecipient !== ''
					&& mb_stripos((string)$row['FIELD_BCC'], $originalRecipient) !== false
				)
				{
					$legacyOriginalRecipientInBcc = true;

					break;
				}
			}
		}

		if (!$restoreAddressFields && !$legacyOriginalRecipientInBcc)
		{
			return $row;
		}

		$parsedHeader = \CMailMessage::parseHeader($header, LANG_CHARSET);

		if ($restoreAddressFields)
		{
			$row['FIELD_FROM'] = $parsedHeader->getHeader('FROM');
			$row['FIELD_REPLY_TO'] = $parsedHeader->getHeader('REPLY-TO');
			$row['FIELD_TO'] = $parsedHeader->getHeader('TO');
			$row['FIELD_CC'] = $parsedHeader->getHeader('CC');
		}

		$row['FIELD_BCC'] = self::getBccFromParsedHeader($parsedHeader);

		return $row;
	}

	/**
	 * Adapts a message(result of Mail\MailMessageTable::getList) for output in the public interface.
	 *
	 * @param array &$message(result of Mail\MailMessageTable::getList. Changes the data in a variable!).
	 *
	 * @return array(modified $message).
	 */
	public static function prepare(&$message)
	{
		$message['__email'] = null;
		foreach (array($message['MAILBOX_EMAIL'], $message['MAILBOX_NAME'], $message['MAILBOX_LOGIN']) as $item)
		{
			$address = new Main\Mail\Address($item);
			if ($address->validate())
			{
				$message['__email'] = $address->getEmail();
				break;
			}
		}

		$message = self::restoreAddressFieldsFromHeader($message);

		foreach (self::ADDRESS_FIELDS_MAP as $fieldKey => $fieldName)
		{
			$message[$fieldKey] = [];

			if($fieldName === 'FIELD_FROM')
			{
				if(static::checkMessageIsOutcomeByField($message,$fieldKey,$message[$fieldName]))
				{
					$message['__is_outcome'] = true;
				}

				$filed = static::prepareField($message[$fieldName]);

				if($filed !== false)
				{
					$message[$fieldKey][] = $filed;
				}

				continue;
			}

			foreach (explode(',', $message[$fieldName]) as $item)
			{
				if(static::checkMessageIsOutcomeByField($message,$fieldKey,$item))
				{
					$message['__is_outcome'] = true;
				}

				$filed = static::prepareField($item);

				if($filed !== false)
				{
					$message[$fieldKey][] = $filed;
				}
			}
		}

		if (empty($message['__reply_to']))
		{
			$message['__reply_to'] = $message['__from'];
		}

		// @TODO: path
		$message['__href'] = sprintf('/mail/message/%u', $message['ID']);

		$urlManager = Attachment\Storage::getUrlManager();

		if (!empty($message['__files']) && is_array($message['__files']))
		{
			$urlParams = array();

			if (isset($_REQUEST['mail_uf_message_token']) && is_string($_REQUEST['mail_uf_message_token']))
			{
				$urlParams['mail_uf_message_token'] = $_REQUEST['mail_uf_message_token'];
			}

			foreach ($message['__files'] as $k => $item)
			{
				if ($diskFile = Attachment\Storage::getObjectByAttachment($item, true))
				{
					$message['__files'][$k] = array(
						'id' => sprintf('n%u', $diskFile->getId()),
						'name' => $item['FILE_NAME'],
						'url' => $urlManager->getUrlForShowFile($diskFile, $urlParams),
						'size' => \CFile::formatSize($diskFile->getSize()),
						'fileId' => $diskFile->getFileId(),
						'objectId' => $diskFile->getId(),
						'bytes' => $diskFile->getSize(),
					);

					if (\Bitrix\Disk\TypeFile::isImage($diskFile))
					{
						$message['__files'][$k]['preview'] = $urlManager->getUrlForShowFile(
							$diskFile,
							array_merge(
								array('width' => 80, 'height' => 80, 'exact' => 'Y'),
								$urlParams
							)
						);
					}

					$fileInfo = $diskFile->getFile();

					if (isset($fileInfo['SRC']))
					{
						$message['BODY_HTML'] = Parsers\ReplacingImagesLinks::parse(
							(string)$message['BODY_HTML'],
							$item['ID'],
							(new Uri($fileInfo['SRC']))->toAbsolute()->getLocator(),
						);
					}
				}
				else
				{
					$file = \CFile::getFileArray($item['FILE_ID']);
					if (!empty($file) && is_array($file))
					{
						$message['__files'][$k] = array(
							'id' => $file['ID'],
							'name' => $item['FILE_NAME'],
							'url' => $file['SRC'],
							'size' => \CFile::formatSize($file['FILE_SIZE']),
							'fileId' => $file['ID'],
							'bytes' => $file['FILE_SIZE'],
						);

						if (\CFile::isImage($item['FILE_NAME'], $item['CONTENT_TYPE']))
						{
							$preview = \CFile::resizeImageGet(
								$file, array('width' => 80, 'height' => 80),
								BX_RESIZE_IMAGE_EXACT, false
							);

							if (!empty($preview['src']))
							{
								$message['__files'][$k]['preview'] = $preview['src'];
							}
						}

						if (isset($file['SRC']))
						{
							$message['BODY_HTML'] = Parsers\ReplacingImagesLinks::parse(
								(string)$message['BODY_HTML'],
								$item['ID'],
								(new Uri($file['SRC']))->toAbsolute()->getLocator(),
							);
						}
					}
					else
					{
						unset($message['__files'][$k]);
					}
				}
			}
		}

		return $message;
	}

	public static function hasAccess(&$message, $userId = null, ?MessageAccessService $accessService = null)
	{
		global $USER;

		if (
			!($userId > 0 || is_object($USER) && $USER->isAuthorized())
			//If message id = 0 , the message is deleted or not loaded:
			|| is_null($message['ID']) || $message['ID'] === 0 || $message['ID'] === '0'
		)
		{
			return false;
		}

		if (!($userId > 0))
		{
			$userId = $USER->getId();
		}

		$token = isset($_REQUEST['mail_uf_message_token']) && is_string($_REQUEST['mail_uf_message_token'])
			? $_REQUEST['mail_uf_message_token']
			: '';
		$accessResult = ($accessService ?? new MessageAccessService())->resolve(
			(int)$message['MAILBOX_ID'],
			(int)$message['ID'],
			(int)$userId,
			$token,
		);

		if ($accessResult->isSuccess())
		{
			$message['__access_level']
				= $accessResult->getData()[MessageAccessService::RESULT_ACCESS_LEVEL];

			return true;
		}

		// bindings to a chat, a calendar event, a task or a post: the only branch left needing the message
		$messageAccess = \Bitrix\Mail\MessageAccess::createByMessageId($message['ID'], $userId);
		$isOwner = $messageAccess->isOwner();
		$access = $isOwner || $messageAccess->canViewMessage();
		$message['__access_level'] = $isOwner
			? MessageAccessService::ACCESS_LEVEL_FULL
			: ($access ? MessageAccessService::ACCESS_LEVEL_READ : false);

		return $access;
	}

	public static function prepareSearchContent(&$fields, string $originalRecipients = '')
	{
		// @TODO: filter short words, filter duplicates, str_rot13?
		return str_rot13(join(
			' ',
			array(
				$fields['FIELD_FROM'],
				$fields['FIELD_REPLY_TO'],
				$fields['FIELD_TO'],
				$fields['FIELD_CC'],
				$fields['FIELD_BCC'],
				self::prepareOriginalRecipientsSearchContent($originalRecipients),
				$fields['SUBJECT'],
				self::isolateBase64Files((string)$fields['BODY']),
			)
		));
	}

	public static function prepareSearchString($string)
	{
		return str_rot13($string);
	}

	public static function prepareOriginalRecipientsSearchString(string $string): string
	{
		$searchToken = self::prepareOriginalRecipientSearchToken($string);

		return $searchToken === '' ? '' : self::prepareSearchString($searchToken);
	}

	private static function prepareOriginalRecipientsSearchContent(string $originalRecipients): string
	{
		if ($originalRecipients === '')
		{
			return '';
		}

		$searchContent = [];
		foreach (\CMailUtil::extractAllMailAddresses($originalRecipients) as $originalRecipient)
		{
			$searchToken = self::prepareOriginalRecipientSearchToken($originalRecipient);
			if ($searchToken !== '')
			{
				$searchContent[] = $searchToken;
			}
		}

		return join(' ', array_unique($searchContent));
	}

	private static function prepareOriginalRecipientSearchToken(string $originalRecipient): string
	{
		$address = new Main\Mail\Address($originalRecipient);
		if (!$address->validate())
		{
			return '';
		}

		return self::ORIGINAL_RECIPIENT_SEARCH_TOKEN_PREFIX
			. hash('sha256', mb_strtolower($address->getEmail()));
	}

	/**
	 * @param $userId
	 * @param $onlyGeneralCounter
	 * @return array|int
	 */
	public static function getCountersForUserMailboxes($userId, $onlyGeneralCounter = false)
	{
		if (!isset(self::$countersForUsersCache[$userId]))
		{
			$mailboxes = MailboxTable::getUserMailboxes($userId, true);

			if (empty($mailboxes))
			{
				return array();
			}

			$mailboxIds = array_column($mailboxes, 'ID');

			$totalUnseen = Internals\MailCounterTable::getList([
				'select' => [
					new \Bitrix\Main\Entity\ExpressionField(
						'UNSEEN',
						'SUM(VALUE)'
					),
					'VALUE',
					'ENTITY_ID',
				],
				'filter' => [
					'=ENTITY_TYPE' => 'MAILBOX',
					'@ENTITY_ID' => $mailboxIds,
				],
			])->fetchAll();

			$counters = [];

			$totalCounter = 0;

			foreach ($totalUnseen as $index => $item)
			{
				$totalCounter += $item['VALUE'];

				$counters[$item['ENTITY_ID']] = [
					'UNSEEN' => $item['VALUE'],
				];
			}

			self::$countersForUsersCache[$userId] = [
				'mailboxesWithCounters' => $counters,
				'totalCounter' => $totalCounter,
			];
		}

		if($onlyGeneralCounter)
		{
			return self::$countersForUsersCache[$userId]['totalCounter'];
		}
		else
		{
			return self::$countersForUsersCache[$userId]['mailboxesWithCounters'];
		}
	}

	public static function resetCountersCache(int $userId): void
	{
		unset(self::$countersForUsersCache[$userId]);
	}

	public static function setUserUnseenCounter(int $userId, string $siteId): int
	{
		$unseen = max((int)static::getCountersForUserMailboxes($userId, true), 0);
		$unseen += (new Mailbox\MailboxConnectionRequestService($userId))->getPendingCount();
		$unseen += Mailbox\PasswordlessConnectHelper::getUserPendingCount($userId, $siteId);

		\CUserCounter::set($userId, 'mail_unseen', $unseen, $siteId);

		return $unseen;
	}

	public static function ensureAttachments(&$message)
	{
		if ($message['ATTACHMENTS'] > 0 || !($message['OPTIONS']['attachments'] > 0))
		{
			return false;
		}

		if (Option::get('mail', 'save_attachments', B_MAIL_SAVE_ATTACHMENTS) !== 'Y')
		{
			return false;
		}

		$mailboxHelper = Mailbox::createInstance($message['MAILBOX_ID'], false);

		$attachments = empty($mailboxHelper) ? false : $mailboxHelper->downloadAttachments($message);

		if (false === $attachments)
		{
			$logEntry = sprintf(
				'Helper\Message: Attachments downloading failed (%u:%s:%u)',
				$message['MAILBOX_ID'],
				$message['DIR_MD5'],
				$message['MSG_UID']
			);

			if (!empty($mailboxHelper) && !$mailboxHelper->getErrors()->isEmpty())
			{
				$logEntry .= PHP_EOL . join(PHP_EOL, $mailboxHelper->getErrors()->toArray());
			}

			addMessage2Log($logEntry, 'mail', 2);

			return false;
		}

		$originalBody = $message['BODY_HTML'] ?? null;
		foreach ($attachments as $i => $item)
		{
			$attachFields = array(
				'MESSAGE_ID'   => $message['ID'],
				'FILE_NAME'    => $item['FILENAME'],
				'CONTENT_TYPE' => $item['CONTENT-TYPE'],
				'FILE_DATA'    => $item['BODY'],
				'CONTENT_ID'   => $item['CONTENT-ID'],
			);

			$attachmentId = \CMailMessage::addAttachment($attachFields);

			if ($attachmentId > 0)
			{
				$message['ATTACHMENTS']++;

				if (isset($message['BODY_HTML']) && mb_strlen($message['BODY_HTML']) > 0)
				{
					$bodyWithReplaced = self::replaceBodyInlineImgContentId(
						(string)$message['BODY_HTML'],
						(string)$item['CONTENT-ID'],
						$attachmentId
					);
					if ($bodyWithReplaced)
					{
						$message['BODY_HTML'] = $bodyWithReplaced;
					}
				}
			}
		}

		if ($message['ATTACHMENTS'] > 0)
		{
			if ($originalBody !== $message['BODY_HTML'])
			{
				\CMailMessage::update($message['ID'], ['BODY_HTML' => $message['BODY_HTML']], $message['MAILBOX_ID']);
			}

			return $message['ID'];
		}
	}

	public static function parseAddressList($column)
	{
		return self::parseAddressListInternal(trim($column));
	}

	private static function parseAddressListInternal(
		string $column,
		bool $ignoreQuotes = false,
		bool $recoverUnclosedComment = true,
		bool $forceCommaSeparators = false
	): array
	{
		$columns = [];
		$currentColumn = '';
		$isQuoted = false;
		$isEscaped = false;
		$isAngleAddress = false;
		$isAngleAddressClosed = false;
		$angleAddressEnd = null;
		$commentDepth = 0;
		$commentStartIndex = null;
		$isCommentEscaped = false;
		$isGroup = false;
		$isAfterAddressTerminator = false;
		$groupColumns = [];
		$groupPrefix = '';
		$canBeBareAddress = true;
		$canStartBareAddressComment = true;
		$columnLength = strlen($column);

		for ($index = 0; $index < $columnLength; $index++)
		{
			$character = $column[$index];
			if ($isAfterAddressTerminator)
			{
				if (
					$character === ','
					|| ($character === ';' && !$isGroup)
					|| $character === '>'
					|| strpos(" \t\r\n", $character) !== false
				)
				{
					continue;
				}

				$isAfterAddressTerminator = false;
			}

			if (
				$isGroup
				&& empty($groupColumns)
				&& $currentColumn === ''
				&& strpos(" \t\r\n", $character) !== false
			)
			{
				$groupPrefix .= $character;

				continue;
			}

			if ($commentDepth > 0)
			{
				if ($isCommentEscaped)
				{
					$isCommentEscaped = false;
				}
				elseif ($character === '\\')
				{
					$isCommentEscaped = true;
				}
				elseif ($character === '(')
				{
					$commentDepth++;
				}
				elseif ($character === ')')
				{
					$commentDepth--;
					if ($commentDepth === 0)
					{
						$commentStartIndex = null;
					}
				}

				continue;
			}

			$isCommentStart = false;
			if ($character === '(' && !$isQuoted)
			{
				$isCommentStart = $isAngleAddressClosed;
				if (!$isCommentStart && !$isAngleAddress && $canStartBareAddressComment)
				{
					$isCommentStart = self::isCompleteAddress($currentColumn);
					$canStartBareAddressComment = $isCommentStart;
				}
			}
			if ($isCommentStart)
			{
				$commentDepth = 1;
				$commentStartIndex = $index;

				continue;
			}

			if (!$ignoreQuotes && $character === '"' && !$isEscaped)
			{
				$isQuoted = !$isQuoted;
			}

			if (
				$character === ':'
				&& !$isQuoted
				&& !$isGroup
				&& !$isAngleAddress
			)
			{
				$groupPrefix = $currentColumn . ':';
				$groupColumns = [];
				$currentColumn = '';
				$isEscaped = false;
				$angleAddressEnd = null;
				$isGroup = true;
				$canBeBareAddress = true;
				$canStartBareAddressComment = true;

				continue;
			}

			if (!$isQuoted && $character === '<')
			{
				if ($isAngleAddressClosed)
				{
					$closedAddressParts = self::splitClosedAngleAddress($currentColumn, $angleAddressEnd);
					if ($closedAddressParts !== null)
					{
						[$addressColumn, $currentColumn] = $closedAddressParts;
						self::appendAddressColumn(
							$columns,
							$groupColumns,
							$addressColumn,
							$isGroup
						);
						if ($currentColumn !== '')
						{
							$currentColumn .= ' ';
						}
						$isEscaped = false;
						$isAngleAddress = false;
						$isAngleAddressClosed = false;
						$angleAddressEnd = null;
						$canBeBareAddress = true;
						$canStartBareAddressComment = true;
					}
				}
				$isAngleAddress = true;
			}
			elseif (!$isQuoted && $character === '>')
			{
				if ($isAngleAddress && !$isAngleAddressClosed)
				{
					$isAngleAddressClosed = true;
					$angleAddressEnd = strlen($currentColumn);
				}
				else
				{
					if ($isAngleAddressClosed)
					{
						$closedAddressParts = self::splitClosedAngleAddress($currentColumn, $angleAddressEnd);
						if ($closedAddressParts !== null)
						{
							[$addressColumn, $tailColumn] = $closedAddressParts;
							self::appendAddressColumn($columns, $groupColumns, $addressColumn, $isGroup);
							self::appendAddressColumn(
								$columns,
								$groupColumns,
								self::normalizeAddressColumn($tailColumn, false, false),
								$isGroup
							);
						}
						else
						{
							self::appendAddressColumn(
								$columns,
								$groupColumns,
								self::normalizeAddressColumn($currentColumn, true, true),
								$isGroup
							);
						}
					}
					else
					{
						self::appendAddressColumn(
							$columns,
							$groupColumns,
							self::normalizeAddressColumn($currentColumn, false, false),
							$isGroup
						);
					}

					$currentColumn = '';
					$isEscaped = false;
					$isAngleAddress = false;
					$isAngleAddressClosed = false;
					$angleAddressEnd = null;
					$isAfterAddressTerminator = true;
					$canBeBareAddress = true;
					$canStartBareAddressComment = true;

					continue;
				}
			}

			$hasSplitClosedAngleAddress = false;
			if (
				!$isQuoted
				&& ($character === ',' || $character === ';')
				&& $isAngleAddressClosed
			)
			{
				$closedAddressParts = self::splitClosedAngleAddress($currentColumn, $angleAddressEnd);
				if ($closedAddressParts !== null && $closedAddressParts[1] !== '')
				{
					[$addressColumn, $currentColumn] = $closedAddressParts;
					self::appendAddressColumn(
						$columns,
						$groupColumns,
						$addressColumn,
						$isGroup
					);
					$isEscaped = false;
					$isAngleAddress = false;
					$isAngleAddressClosed = false;
					$angleAddressEnd = null;
					$canBeBareAddress = true;
					$canStartBareAddressComment = true;
					$hasSplitClosedAngleAddress = true;
				}
			}

			if ($character === ',' && !$isQuoted)
			{
				$isSeparator = $isAngleAddressClosed || $forceCommaSeparators;
				if (!$isSeparator && $canBeBareAddress)
				{
					$isSeparator = self::isCompleteAddress($currentColumn);
					$canBeBareAddress = false;
				}

				if ($isSeparator)
				{
					$addressColumn = self::normalizeAddressColumn(
						$currentColumn,
						$isAngleAddress,
						$isAngleAddressClosed
					);
					self::appendAddressColumn($columns, $groupColumns, $addressColumn, $isGroup);
					$currentColumn = '';
					$isEscaped = false;
					$isAngleAddress = false;
					$isAngleAddressClosed = false;
					$angleAddressEnd = null;
					$canBeBareAddress = true;
					$canStartBareAddressComment = true;

					continue;
				}
			}
			elseif (
				$character === ';'
				&& !$isQuoted
				&& ($isGroup || $isAngleAddressClosed || $hasSplitClosedAngleAddress)
			)
			{
				$addressColumn = self::normalizeAddressColumn(
					$currentColumn,
					$isAngleAddress,
					$isAngleAddressClosed
				);
				self::appendAddressColumn($columns, $groupColumns, $addressColumn, $isGroup);
				if ($isGroup)
				{
					self::appendGroupColumns($columns, $groupColumns, $groupPrefix, true);
				}
				$currentColumn = '';
				$isEscaped = false;
				$isAngleAddress = false;
				$isAngleAddressClosed = false;
				$angleAddressEnd = null;
				$isGroup = false;
				$isAfterAddressTerminator = true;
				$groupColumns = [];
				$groupPrefix = '';
				$canBeBareAddress = true;
				$canStartBareAddressComment = true;

				continue;
			}

			$currentColumn .= $character;
			$isEscaped = $character === '\\' && !$isEscaped;
		}

		if ($isQuoted && !$ignoreQuotes)
		{
			return self::parseAddressListInternal(
				$column,
				true,
				$recoverUnclosedComment,
				$forceCommaSeparators
			);
		}

		$unclosedCommentTail = null;
		if ($commentDepth > 0 && $commentStartIndex !== null && $recoverUnclosedComment)
		{
			$unclosedCommentTail = substr($column, $commentStartIndex);
		}

		if ($isAngleAddressClosed)
		{
			$closedAddressParts = self::splitClosedAngleAddress($currentColumn, $angleAddressEnd);
			if ($closedAddressParts !== null && $closedAddressParts[1] !== '')
			{
				[$addressColumn, $currentColumn] = $closedAddressParts;
				self::appendAddressColumn(
					$columns,
					$groupColumns,
					$addressColumn,
					$isGroup
				);
				$isAngleAddress = false;
				$isAngleAddressClosed = false;
				$angleAddressEnd = null;

				$addressColumn = self::normalizeAddressColumn(
					$currentColumn,
					false,
					false
				);
				self::appendAddressColumn($columns, $groupColumns, $addressColumn, $isGroup);
				$currentColumn = '';
			}
		}

		$addressColumn = self::normalizeAddressColumn(
			$currentColumn,
			$isAngleAddress,
			$isAngleAddressClosed
		);
		self::appendAddressColumn($columns, $groupColumns, $addressColumn, $isGroup);

		if ($isGroup)
		{
			self::appendGroupColumns($columns, $groupColumns, $groupPrefix, false);
		}

		if ($unclosedCommentTail !== null)
		{
			$columns = array_merge(
				$columns,
				self::recoverAddressesFromUnclosedComment($unclosedCommentTail)
			);
		}

		return $columns;
	}

	private static function appendAddressColumn(
		array &$columns,
		array &$groupColumns,
		?string $addressColumn,
		bool $isGroup
	): void
	{
		if ($addressColumn === null)
		{
			return;
		}

		if ($isGroup)
		{
			$groupColumns[] = $addressColumn;
		}
		else
		{
			$columns[] = $addressColumn;
		}
	}

	private static function appendGroupColumns(
		array &$columns,
		array $groupColumns,
		string $groupPrefix,
		bool $isClosed
	): void
	{
		if (!empty($groupColumns))
		{
			if (!$isClosed)
			{
				$groupColumns[0] = trim($groupPrefix . $groupColumns[0]);
			}

			foreach ($groupColumns as $groupColumn)
			{
				$columns[] = $groupColumn;
			}

			return;
		}

		$groupPrefix = trim($groupPrefix);
		if ($groupPrefix !== '')
		{
			$columns[] = $groupPrefix . ($isClosed ? ';' : '');
		}
	}

	private static function splitClosedAngleAddress(string $column, ?int $closedAddressEnd): ?array
	{
		if (
			$closedAddressEnd === null
			|| $closedAddressEnd < 0
			|| $closedAddressEnd >= strlen($column)
			|| $column[$closedAddressEnd] !== '>'
		)
		{
			return null;
		}

		$addressColumn = self::normalizeAddressColumn(
			substr($column, 0, $closedAddressEnd + 1),
			true,
			true
		);
		if ($addressColumn === null)
		{
			return null;
		}

		return [
			$addressColumn,
			trim(substr($column, $closedAddressEnd + 1)),
		];
	}

	private static function recoverAddressesFromUnclosedComment(string $commentTail): array
	{
		$columns = self::parseAddressListInternal(
			substr($commentTail, 1),
			false,
			false,
			true
		);
		$recoveredColumns = [];
		foreach ($columns as $column)
		{
			if (strpos($column, '<') !== false && self::isCompleteAddress($column))
			{
				$recoveredColumns[] = $column;
			}
		}

		return $recoveredColumns;
	}

	private static function normalizeAddressColumn(
		string $column,
		bool $isAngleAddress,
		bool $isAngleAddressClosed
	): ?string
	{
		$column = trim($column);
		if ($column === '')
		{
			return null;
		}

		if ($isAngleAddress && !$isAngleAddressClosed)
		{
			$column .= '>';
		}

		return $column;
	}

	private static function isCompleteAddress(string $candidate): bool
	{
		$address = new Main\Mail\Address(trim($candidate));

		return $address->validate();
	}

	public static function isolateSelector($matches): string
	{
		$head = $matches['head'];
		$body = $matches['body'];
		$prefix = 'mail-message-';
		$wrapper = '#mail-message-wrapper ';
		if(substr($head,0,1)==='@') $wrapper ='';
		$closure = $matches['closure'];
		$head = preg_replace('%([\.#])([a-z][-_a-z0-9]+)%mi', '$1'.$prefix.'$2', $head);

		return $wrapper.preg_replace('/,/', ', ' . $wrapper . ' ', $head).$body.$closure;
	}

	public static function isolateStylesInTheTag($matches)
	{
		$wrapper = '#mail-message-wrapper ';
		$openingTag = $matches['openingTag'];
		$closingTag = $matches['closingTag'];
		$styles = $matches['styles'];

		// neutralize viewport-dependent units to prevent infinite iframe resize loop
		$styles = preg_replace('/(\d+(?:\.\d+)?)\s*(vh|vw|vmin|vmax|svh|svw|lvh|lvw|dvh|dvw)\b/i', '0px', $styles);

		// remove all rules that do not start with a class selector
		$styles = preg_replace('/(?:^|})\s*(?!(\.|@|\s*#mail-message-wrapper\s*\.))\s*[^@}{]+\s*\{[^}]*}/is', '', $styles);

		$bodySelectorPattern = '#(.*?)(^|\s)(body)\s*((?:\{)(?:.*?)(?:\}))(.*)#msi';
		$bodySelector = preg_replace($bodySelectorPattern, '$2' . $wrapper . '$4', $styles);
		//cut off body selector
		$styles = preg_replace($bodySelectorPattern, '$1$5', $styles);
		$styles = preg_replace('#(^|\s)(body)\s*({)#iU', '$1mail-msg-view-body$3', $styles);
		$styles = preg_replace_callback('%(?:^|\s)(?<head>[@#\.]?[a-z].*?\{)(?<body>.*?)(?<closure>\})%msi', 'static::isolateSelector', $styles);
		return  $openingTag.$bodySelector.$styles.$closingTag;
	}

	public static function isolateInlineStyles($matches): string
	{
		$styles = $matches['styles'];
		$quote = $matches['quote'];

		//remove position:fixed
		$styles = preg_replace('/position\s*:\s*fixed\s*;?/i', '', $styles);

		// neutralize viewport-dependent units to prevent infinite iframe resize loop
		$styles = preg_replace('/(\d+(?:\.\d+)?)\s*(vh|vw|vmin|vmax|svh|svw|lvh|lvw|dvh|dvw)\b/i', '0px', $styles);

		$styles = preg_replace_callback(
			'/z-index\s*:\s*(?<value>\d+)\s*;?/i',
			function ($matches)
			{
				//cap z-index at 1000
				if ((int)$matches['value'] > 1000)
				{
					return 'z-index: 1000;';
				}

				return $matches[0];
			},
			$styles
		);

		return 'style=' . $quote . $styles . $quote;
	}

	public static function isolateStylesInTheBody($html)
	{
		$prefix = 'mail-message-';
		$html = preg_replace('%((?:^|\s)(?:class|id)(?:^|\s*)(?:=)(?:^|\s*)("|\'))((?:.*?)(?:\2))%', '$1'.$prefix.'$3', $html);
		return $html;
	}

	public static function isolateMessageStyles($messageHtml)
	{
		Ini::adjustPcreBacktrackLimit(strlen($messageHtml)*2);

		//isolates the positioning of the element
		$messageHtml = preg_replace('%((?:^|\s)position(?:^|\s)?:(?:^|\s)?)(absolute|fixed|inherit)%', '$1relative', $messageHtml);

		//remove media queries
		$messageHtmlAfterClearingFromMedia = preg_replace('%@media\b[^{]*({((?:[^{}]+|(?1))*)})%mi', '', $messageHtml);

		/*
			If due to strong nesting it was not possible to delete media,
			then we make them invalid for the browser by deactivating them.
		*/
		if (is_null($messageHtmlAfterClearingFromMedia))
		{
			$messageHtml = preg_replace('/@media/i', '@mail-message-disabled-media', $messageHtml);
		}
		else
		{
			$messageHtml = $messageHtmlAfterClearingFromMedia;
		}

		//remove loading fonts
		$messageHtml = preg_replace('%@font-face\b[^{]*({(?>[^{}]++|(?1))*})%mi', '', $messageHtml);

		$messageHtml = static::isolateStylesInTheBody($messageHtml);

		$messageHtml = preg_replace_callback(
			'/style\s*=\s*(?<quote>["\'])(?<styles>[^"\']*)\k<quote>/i',
			'static::isolateInlineStyles',
			$messageHtml
		);

		return preg_replace_callback('|(?<openingTag><style[^>]*>)(?<styles>.*)(?<closingTag><\/style>)|isU', 'static::isolateStylesInTheTag', $messageHtml);
	}

	private static function neutralizeViewportUnits($messageHtml)
	{
		Ini::adjustPcreBacktrackLimit(strlen($messageHtml) * 2);

		$viewportUnitsPattern = '/(\d+(?:\.\d+)?)\s*(vh|vw|vmin|vmax|svh|svw|lvh|lvw|dvh|dvw)\b/i';

		$messageHtml = static::replaceWithCallbackOrKeep(
			'/style\s*=\s*"(?<styles>[^"]*)"/i',
			static function (array $matches) use ($viewportUnitsPattern): string
			{
				$styles = preg_replace($viewportUnitsPattern, '0px', $matches['styles']);

				return 'style="' . ($styles ?? $matches['styles']) . '"';
			},
			$messageHtml
		);
		$messageHtml = static::replaceWithCallbackOrKeep(
			"/style\\s*=\\s*'(?<styles>[^']*)'/i",
			static function (array $matches) use ($viewportUnitsPattern): string
			{
				$styles = preg_replace($viewportUnitsPattern, '0px', $matches['styles']);

				return "style='" . ($styles ?? $matches['styles']) . "'";
			},
			$messageHtml
		);

		return static::replaceWithCallbackOrKeep(
			'|(?<openingTag><style[^>]*>)(?<styles>.*?)(?<closingTag><\/style>)|is',
			static function (array $matches) use ($viewportUnitsPattern): string
			{
				$styles = preg_replace($viewportUnitsPattern, '0px', $matches['styles']);

				return $matches['openingTag'] . ($styles ?? $matches['styles']) . $matches['closingTag'];
			},
			$messageHtml
		);
	}

	private static function replaceWithCallbackOrKeep(string $pattern, callable $callback, string $subject): string
	{
		return preg_replace_callback($pattern, $callback, $subject) ?? $subject;
	}

	public static function sanitizeHtml($html, $isolateStyles = false)
	{
		$cleared = (string)$html;
		foreach (['/<!--.*?-->/is', '/<script[^>]*>.*?<\/script>/is', '/<title[^>]*>.*?<\/title>/is'] as $pattern)
		{
			$result = preg_replace($pattern, '', $cleared);
			$cleared = $result ?? $cleared;
		}
		$sanitizer = new \CBXSanitizer();
		$sanitizer->setLevel(\CBXSanitizer::SECURE_LEVEL_LOW);
		$sanitizer->applyDoubleEncode(false);
		$sanitizer->addTags(static::getWhitelistTagAttributes());
		$cleared = $sanitizer->sanitizeHtml($cleared);

		if($isolateStyles)
		{
			$cleared = static::isolateMessageStyles($cleared);
		}

		return $cleared;
	}

	/**
	 * Sanitizes HTML for rendering inside the sandboxed mail message iframe.
	 *
	 * @internal
	 */
	public static function sanitizeHtmlForMessageView($html)
	{
		return static::neutralizeViewportUnits(static::sanitizeHtml($html));
	}

	public static function reSyncBody($mailboxId, $messageIds)
	{
		if (empty($messageIds) || !is_array($messageIds))
		{
			return false;
		}

		$messages = self::getPlacementsToDownloadBodyThrough((int)$mailboxId, $messageIds);

		$notProcessed = array_combine($messageIds, $messageIds);

		$mailboxHelper = static::resolveMailbox((int)$mailboxId);

		if(!empty($mailboxHelper))
		{
			$mailbox = $mailboxHelper->getMailbox();

			while ($message = $messages->fetch())
			{
				$bodyArrived = false;
				$technicalTitle = $mailboxHelper->downloadMessage($message);
				if ($technicalTitle)
				{
					$charset = $mailbox['CHARSET'] ?: $mailbox['LANG_CHARSET'];
					[$header, $html, $text, $attachments] = \CMailMessage::parseMessage($technicalTitle, $charset);

					if (\CMailMessage::isLongMessageBody($text))
					{
						[$text, $html] = \CMailMessage::prepareLongMessage($text, $html);
					}

					if (rtrim($text) || $html)
					{
						\CMailMessage::update(
							$message['MESSAGE_ID'],
							[
								'BODY' => rtrim($text),
								'BODY_HTML' => $html,
								MailMessageTable::FIELD_SANITIZE_ON_VIEW => 1,
							]
						);
						$bodyArrived = true;
					}
				}
				self::updateMailEntityOptionsRow($mailboxId, (int)$message['MESSAGE_ID']);
				self::publishBodySynced((int)$mailboxId, (int)$message['MESSAGE_ID'], $bodyArrived);
				unset($notProcessed[$message['MESSAGE_ID']]);
			}
		}

		foreach ($notProcessed as $messageId)
		{
			self::updateMailEntityOptionsRow($mailboxId, (int)$messageId);
			self::publishBodySynced((int)$mailboxId, (int)$messageId, false);
		}

		return true;
	}

	/**
	 * The placements the body of a message can still be downloaded through: those of the
	 * generation the mailbox is served by now.
	 *
	 * A placement of a retained generation names the folder and the uid of a physical source
	 * the mailbox has left, and the same uid on the source it lives on now belongs to
	 * another letter, so a download through such a placement would fill the message with a
	 * foreign body. A message left without any placement of the active generation cannot be
	 * completed at all: it keeps the body it has and is marked as attempted, instead of
	 * being chased through a source that never held it.
	 *
	 * @param int[]|string[] $messageIds
	 */
	private static function getPlacementsToDownloadBodyThrough(int $mailboxId, array $messageIds): Main\ORM\Query\Result
	{
		return \Bitrix\Mail\MailMessageUidTable::getList([
			'select' => [
				'*'
			],
			'filter' => GenerationScope::forMailbox($mailboxId)->apply([
				'=MAILBOX_ID' => $mailboxId,
				'@MESSAGE_ID' => $messageIds,
			]),
		]);
	}

	/**
	 * Kept apart so a test can drive the loop over a mailbox that answers without a socket.
	 */
	protected static function resolveMailbox(int $mailboxId): Mailbox|bool
	{
		return Mailbox::createInstance($mailboxId, false);
	}

	/**
	 * Published for every message of the pass, including the ones whose body never arrived:
	 * a subscriber waiting for the body has no other moment to learn that the wait is over.
	 */
	private static function publishBodySynced(int $mailboxId, int $messageId, bool $bodyArrived): void
	{
		$event = new Main\Event('mail', 'onMailMessageBodySynced', [
			'mailboxId' => $mailboxId,
			'messageId' => $messageId,
			'bodyArrived' => $bodyArrived,
		]);
		$event->send();
	}

	public static function getSaltByEntityType(string $entityType, int $entityId, ?int $userId = null): string
	{
		switch ($entityType)
		{
			case Message::ENTITY_TYPE_IM_CHAT:
			case Message::ENTITY_TYPE_CHAT_MESSAGE:
				return sprintf('chat%u', $entityId);
			case Message::ENTITY_TYPE_CALENDAR_EVENT:
				return sprintf('event'.'%u', $entityId);
			default:
				// per-user tokens for USER_MESSAGE, TASKS_TASK, CRM_ACTIVITY, ...
				return sprintf('user'.'%u', $userId);
		}
	}

	/**
	 * @param int $mailboxId
	 * @param int $userId
	 *
	 * @return bool
	 *
	 * @use MailboxAccess::isMailboxOwner and
	 * @use MailboxAccess::isMailboxSharedWithUser
	 * @deprecated
	 *
	 * checked if the user has access to the mailbox by using getTheOwnersMailboxes and getTheSharedMailboxes from MailboxTable
	 */
	public static function isMailboxOwner(int $mailboxId, int $userId): bool
	{
		return (bool)MailboxTable::getUserMailbox($mailboxId, $userId);
	}

	/**
	 * @param $mailboxId
	 * @param $messageId
	 * @return void
	 */
	public static function updateMailEntityOptionsRow($mailboxId, $messageId): void
	{
		Internals\MailEntityOptionsTable::update([
				'MAILBOX_ID' => $mailboxId,
				'ENTITY_ID' => $messageId,
				'ENTITY_TYPE' => 'MESSAGE',
				'PROPERTY_NAME' => 'UNSYNC_BODY',
			],
			[
				'VALUE' => 'N',
			]
		);
	}

	/**
	 * Brings the conversation projection up to date with the letter whose closure has just been saved,
	 * before the list of the client is told to refresh itself.
	 *
	 * Nothing is reported back and nothing is thrown: the caller is the central write path of the mail
	 * synchronization, and a read model must not be able to break the delivery of a letter. A
	 * projection that did fail takes the readiness of the mailbox back and arms the background pass,
	 * so grouping resumes only from a projection that has been walked and checked again.
	 */
	public static function syncConversationProjection(int $mailboxId, int $messageId): void
	{
		if ($mailboxId <= 0 || $messageId <= 0)
		{
			return;
		}

		if (!(new ConversationBackfill())->indexMessage($mailboxId, $messageId))
		{
			MessageConversationStepper::bind();
		}
	}

	/**
	 * Is message body contains link to attachment
	 *
	 * @param string $body HTML body of message
	 *
	 * @return bool
	 */
	public static function isBodyNeedUpdateAfterLoadAttachments(string $body): bool
	{
		return preg_match('/<img([^>]+)src\s*=\s*([\'"])?\s*((?:http:\/\/)?cid:.+)\s*\2([^>]*)>/is', $body);
	}

	/**
	 * Replace html body inline image content id with attachment id
	 *
	 * @param string $body HTML string
	 * @param string $contentId Content Id in img tag (with or without http://)
	 * @param int $attachmentId Attachment ID in DB
	 *
	 * @return string
	 */
	public static function replaceBodyInlineImgContentId(string $body, string $contentId, int $attachmentId): string
	{
		Ini::adjustPcreBacktrackLimit(strlen($body)*2);

		$replacedBody = preg_replace(
			sprintf('/<img([^>]+)src\s*=\s*(\'|\")?\s*((?:http:\/\/)?cid:%s)\s*\2([^>]*)>/is', preg_quote($contentId, '/')),
			sprintf('<img\1src="aid:%u"\4>', $attachmentId),
			$body
		);

		return $replacedBody ?? $body;
	}

	public static function isolateBase64Files(string $text): string
	{
		$pattern = '/\[\s*data:(?!text\b)[^;]+;base64,\S+ \]/';

		return (string)preg_replace($pattern, '', $text);
	}

	public static function isIcalMessage(\Bitrix\Mail\Item\Message $message)
	{
		$attachments = MailMessageAttachmentTable::getList([
			'select' => [
				'ID',
				'FILE_ID',
				'FILE_NAME',
				'FILE_SIZE',
				'CONTENT-TYPE' => 'CONTENT_TYPE',
			],
			'filter' => [
				'=MESSAGE_ID'   => $message->getId(),
				'@CONTENT_TYPE' => ICalMailManager::CONTENT_TYPES
			],
		])->fetchAll();

		return ICalMailManager::hasICalAttachments($attachments);
	}

	/**
	 * @param string $subject Original compose subject.
	 * @param string $body Compose body (HTML or plain text).
	 * @param string $emptySubjectPlaceholder Placeholder for empty subjects.
	 * @return string
	 */
	public static function getOutgoingSubject(
		string $subject,
		string $body,
		?string $emptySubjectPlaceholder = null,
	): string
	{
		if (trim($subject) !== '')
		{
			return $subject;
		}

		$generated = static::extractSubjectFromBody($body);
		$emptySubjectPlaceholder ??= self::getOutgoingSubjectPlaceholder();

		return $generated !== '' ? $generated : $emptySubjectPlaceholder;
	}

	private static function getOutgoingSubjectPlaceholder(): string
	{
		$messages = \Bitrix\Main\Localization\Loc::loadLanguageFile(
			__DIR__ . '/../../install/components/bitrix/mail.client/class.php',
		);

		return (string)($messages['MAIL_MESSAGE_EMPTY_SUBJECT_PLACEHOLDER'] ?? '(no subject)');
	}

	public static function getWithAccessCheck(int $messageId, ?int $userId): ?array
	{
		$message = \Bitrix\Mail\MailMessageTable::getRow([
			'runtime' => [
				new \Bitrix\Main\Entity\ReferenceField(
					'MESSAGE_UID',
					\Bitrix\Mail\MailMessageUidTable::class,
					[
						'=this.MAILBOX_ID' => 'ref.MAILBOX_ID',
						'=this.ID' => 'ref.MESSAGE_ID',
					],
					['join_type' => 'INNER'],
				),
			],
			'select' => [
				'*',
				'MAILBOX_EMAIL' => 'MAILBOX.EMAIL',
				'INTERNALDATE' => 'MESSAGE_UID.INTERNALDATE',
			],
			'filter' => ['=ID' => $messageId],
		]);

		if ($message === null || !self::hasAccess($message, $userId))
		{
			return null;
		}

		return $message;
	}

	public static function getMessageUrl(int $messageId): string
	{
		$uri = new Uri(
			UrlManager::getInstance()->getHostUrl()
			. \Bitrix\Mail\Integration\Intranet\Secretary::getDirectMessageUrl($messageId)
		);

		return $uri->getLocator();
	}

	/**
	 * @param string $body Message body (plain text or HTML).
	 * @return string Generated subject or empty string.
	 */
	public static function extractSubjectFromBody(string $body): string
	{
		$text = static::normalizeBodyText($body);
		$maxLength = self::DISPLAY_SUBJECT_MAX_LENGTH;

		if ($text === '' || mb_strlen($text, 'UTF-8') <= $maxLength)
		{
			return $text;
		}

		$trimmed = mb_substr($text, 0, $maxLength, 'UTF-8');
		$lastSpace = mb_strrpos($trimmed, ' ', 0, 'UTF-8');
		if ($lastSpace !== false && $lastSpace > 0)
		{
			$trimmed = mb_substr($trimmed, 0, $lastSpace, 'UTF-8');
		}

		return $trimmed . '...';
	}

	public static function getDisplaySnippet(string $body, string $subject): string
	{
		if (trim($subject) === '')
		{
			return $body;
		}

		$subjectForMatch = str_ends_with($subject, '...')
			? trim(mb_substr($subject, 0, -3, 'UTF-8'))
			: trim($subject)
		;

		if ($subjectForMatch === '')
		{
			return $body;
		}

		$normalizedBody = static::normalizeBodyText($body);
		if (!str_starts_with($normalizedBody, $subjectForMatch))
		{
			return $body;
		}

		return trim(mb_substr(
			$normalizedBody,
			mb_strlen($subjectForMatch, 'UTF-8'),
			null,
			'UTF-8',
		));
	}

	public static function normalizeBodyText(string $body): string
	{
		$text = $body;
		if (self::hasMarkup($text))
		{
			$text = preg_replace(
				'#</?(?:br|div|p|li|tr|td|th|h[1-6]|blockquote|hr|ol|ul|pre|section|article|header|footer)\b[^>]*>#i',
				' ',
				$text,
			) ?? $text;
			$text = Converter::htmlToText($text);
		}
		$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML401, SITE_CHARSET);
		$text = trim(preg_replace('/\s+/u', ' ', $text));

		return trim(preg_replace('/^[\p{P}\p{S}\p{Z}\p{C}]+/u', '', $text));
	}

	/**
	 * An opening tag with a name of its own, not a pair of angle brackets: an address in angle brackets is
	 * plain text.
	 */
	private static function hasMarkup(string $text): bool
	{
		return preg_match('#<(?:/?[a-z][a-z0-9]*[\s/>]|!--)#i', $text) === 1;
	}
}
