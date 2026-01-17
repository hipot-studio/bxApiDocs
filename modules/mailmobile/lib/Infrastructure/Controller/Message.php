<?php

namespace Bitrix\MailMobile\Infrastructure\Controller;

use Bitrix\Mail\Helper;
use Bitrix\Bitrix24\MailCounter;
use Bitrix\Mail\Helper\Mailbox;
use Bitrix\Mail\Helper\MailboxAccess;
use Bitrix\Mail\Helper\MailContact;
use Bitrix\Mail\Helper\Dto\MailMessageChain;
use Bitrix\Mail\Internals\MailMessageAttachmentTable;
use Bitrix\Mail\MailMessageTable;
use Bitrix\MailMobile\FileUploader\MailUploaderController;
use Bitrix\MailMobile\Public\Service\MailSenderService;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Db\SqlQueryException;
use Bitrix\Mail\Internals\MailContactTable;
use Bitrix\Main;
use Bitrix\Main\Error;
use Bitrix\Main\FinderDestTable;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Mail\Address;
use Bitrix\Main\Mail\Sender;
use Bitrix\Main\Mail\SenderSendCounter;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Web\Json;
use Bitrix\UI\FileUploader\Uploader;
use CBXSanitizer;
use CFile;
use COption;
use Exception;
use ReflectionException;
use Bitrix\Intranet;

class Message extends Controller
{
	public const MAIL_MESSAGE_TYPE = 0;
	public const CRM_MESSAGE_TYPE = 1;

	protected function getDefaultPreFilters(): array
	{
		return
			[
				new Main\Engine\ActionFilter\ContentType([Main\Engine\ActionFilter\ContentType::JSON, 'application/x-www-form-urlencoded']),
				new Main\Engine\ActionFilter\Authentication(),
				new Main\Engine\ActionFilter\HttpMethod(
					[Main\Engine\ActionFilter\HttpMethod::METHOD_GET, Main\Engine\ActionFilter\HttpMethod::METHOD_POST]
				),
				new Intranet\ActionFilter\IntranetUser(),
			];
	}

	/**
	 * @throws LoaderException
	 */
	private function includeRequiredModules(): bool
	{
		$result = (Loader::includeModule('mobile')
			&& Loader::includeModule('mail')
			&& Loader::includeModule('intranet'))
		;

		if (!$result)
		{
			$this->addError(
				new Error(
					'Modules must be installed: mobile, mail, intranet', 'REQUIRED_MODULES_NOT_INSTALLED'
				)
			);
		}

		return $result;
	}

	//todo: Move to Mail module
	/**
	 * @throws ArgumentException|SqlQueryException|SystemException|LoaderException|ObjectPropertyException|ReflectionException
	 */
	public function sendMessageAction(array $data): array
	{
		if (!$this->includeRequiredModules())
		{
			return [];
		}

		$hostname = $this->getHostname();

		$fromEmail = $data['from'];
		$fromAddress = new Address($fromEmail);

		if ($fromAddress->validate())
		{
			$fromEmail = $fromAddress->getEmail();

			\CBitrixComponent::includeComponentClass('bitrix:main.mail.confirm');
			if (!in_array($fromEmail, array_column(\MainMailConfirmComponent::prepareMailboxes(), 'email'), true))
			{
				$this->addError(new Error('Wrong sender email address'));

				return [];
			}

			if ($fromAddress->getName())
			{
				$fromEncoded = sprintf(
					'%s <%s>',
					sprintf('=?%s?B?%s?=', SITE_CHARSET, base64_encode($fromAddress->getName())),
					$fromEmail
				);
			}
		}
		else
		{
			$this->addError(new Error('Wrong sender email address'));

			return [];
		}

		$to = [];
		$cc = [];
		$bcc = [];
		$toEncoded = [];
		$ccEncoded = [];
		$bccEncoded = [];

		foreach (['to', 'cc', 'bcc'] as $field)
		{
			if (!empty($data[$field]) && is_array($data[$field]))
			{
				if (!isset(${$field}))
				{
					${$field} = [];
				}
				$fieldEncoded = $field.'Encoded';
				if (!isset(${$fieldEncoded}))
				{
					${$fieldEncoded} = [];
				}

				$addressList = [];
				foreach ($data[$field] as $item)
				{
					try
					{
						$item = Json::decode($item);

						$address = new Address();
						$address->setEmail($item['email']);
						$address->setName(htmlspecialcharsBack($item['name']));

						if ($address->validate())
						{
							if ($address->getName())
							{
								${$field}[] = $address->get();
								${$fieldEncoded}[] = $address->getEncoded();
							}
							else
							{
								${$field}[] = $address->getEmail();
								${$fieldEncoded}[] = $address->getEmail();
							}

							$addressList[] = $address;
						}
					}
					catch (Exception)
					{
					}
				}

				if (count($addressList) > 0)
				{
					$this->appendMailContacts($addressList, $field);
				}
			}
		}

		$to = array_unique($to);
		$cc = array_unique($cc);
		$bcc = array_unique($bcc);
		$toEncoded = array_unique($toEncoded);
		$ccEncoded = array_unique($ccEncoded);
		$bccEncoded = array_unique($bccEncoded);

		$emailsLimitToSendMessage = Helper\LicenseManager::getEmailsLimitToSendMessage();

		if ($emailsLimitToSendMessage !== -1 && (count($to) > $emailsLimitToSendMessage || count($cc) > $emailsLimitToSendMessage || count($bcc) > $emailsLimitToSendMessage))
		{
			$this->addError(new Error('Message tariff limit restriction'));

			return [];
		}

		if (count($to) + count($cc) + count($bcc) > 10)
		{
			$this->addError(new Error('To many recipients'));

			return [];
		}

		if (empty($to))
		{
			$this->addError(new Error('Empty recipients list'));

			return [];
		}

		$totalRecipientsCount = count($to) + count($cc) + count($bcc);

		if ($this->isSenderLimitReached((string)$fromEmail, $totalRecipientsCount))
		{
			$this->addError(new Error('Sender daily limit reached'));

			return [];
		}

		if ($this->isDailyPortalLimitReached($totalRecipientsCount))
		{
			$this->addError(new Error('Client daily portal limit reached'));

			return [];
		}

		if ($this->isMonthPortalLimitReached($totalRecipientsCount))
		{
			$this->addError(new Error('Client month portal limit reached'));

			return [];
		}

		$messageBody = (string)$data['message'];
		if (!empty($messageBody))
		{
			$messageBody = preg_replace('/<!--.*?-->/s', '', $messageBody);
			$messageBody = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $messageBody);
			$messageBody = preg_replace('/<title[^>]*>.*?<\/title>/is', '', $messageBody);

			$sanitizer = new CBXSanitizer();
			$sanitizer->setLevel(CBXSanitizer::SECURE_LEVEL_LOW);
			$sanitizer->applyDoubleEncode(false);
			$sanitizer->addTags(Helper\Message::getWhitelistTagAttributes());

			$messageBody = $sanitizer->sanitizeHtml($messageBody);

			$messageBody = sprintf(
				'<html><body>%s</body></html>',
				preg_replace('/[\r\n]+/u', '<br>', htmlspecialcharsbx($messageBody))
			);

			$messageBody = nl2br($messageBody);

			$messageBody = preg_replace('/https?:\/\/bxacid:(n?\d+)/i', 'bxacid:\1', $messageBody);
		}

		$outgoingBody = $messageBody;

		$attachments = [];
		$fileTokens = isset($data['fileTokens']) && is_array($data['fileTokens']) ? $data['fileTokens'] : [];
		$repliedId = (int)($data['REPLIED_ID'] ?? 0);
		$hasAccessToMessage = $repliedId && $this->hasUserAccessToMessage($repliedId);
		if ($repliedId && !$hasAccessToMessage)
		{
			$this->addError(new Error('No access to replied message'));

			return [];
		}

		if (!empty($fileTokens))
		{
			$totalSize = 0;

			$currentFilesIds = array_values(
				array_unique(
					array_filter(
						array_map(static function($item) {
							if (is_numeric($item))
							{
								return (int) $item;
							}
							return null;
						}, $fileTokens)
					)
				)
			);

			$pendingFilesIds = array_values(
				array_unique(
					array_filter(
						array_map(static function($item) {
							if (!is_numeric($item) && is_string($item))
							{
								return $item;
							}
							return null;
						}, $fileTokens)
					)
				)
			);

			$uploader = new Uploader(new MailUploaderController());
			$newCurrentFilesIds = [];

			if (!empty($currentFilesIds))
			{
				if ($hasAccessToMessage)
				{
					$list = MailMessageAttachmentTable::getList([
						'select' => [
							'ID',
							'FILE_ID',
							'FILE_NAME',
							'FILE_SIZE',
							'CONTENT_TYPE',
							'IMAGE_WIDTH',
							'IMAGE_HEIGHT',
						],
						'filter' => [
							'=MESSAGE_ID' => $repliedId,
							'@FILE_ID' => $currentFilesIds,
						],
					]);

					while ($item = $list->fetch())
					{
						$fileData = \CFile::makeFileArray($item['FILE_ID']);
						$fileData['name'] = $item['FILE_NAME'];

						$diskFileId = \CFile::saveFile($fileData, 'mail');
						if ($diskFileId)
						{
							$newCurrentFilesIds[] = $diskFileId;
						}
					}
				}

				foreach($newCurrentFilesIds as $fileId)
				{
					$fileArray = CFile::getFileArray($fileId);

					if ($fileArray)
					{
						$filePath = CFile::GetPath($fileId);
						if ($filePath)
						{
							$absolutePath = $_SERVER['DOCUMENT_ROOT'] . $filePath;
							if (file_exists($absolutePath))
							{
								$fileSize = filesize($absolutePath);
								if ($fileSize > 0)
								{
									$totalSize += $fileSize;

									$contentId = sprintf(
										'bxacid.%s@%s.mail',
										hash('crc32b', $fileId . $fileSize . $fileArray['ORIGINAL_NAME']),
										hash('crc32b', $hostname)
									);

									$attachments[] = [
										'ID' => $contentId,
										'NAME' => $fileArray['ORIGINAL_NAME'] ?? $fileArray['name'],
										'PATH' => $absolutePath,
										'CONTENT_TYPE' => $fileArray['CONTENT_TYPE'],
									];

									$outgoingBody = preg_replace(
										sprintf('/(https?:\/\/)?bxacid:n?%u/i', $fileId),
										sprintf('cid:%s', $contentId),
										$outgoingBody
									);
								}
							}
						}
					}
				}
			}

			$pendingFiles = $uploader->getPendingFiles($pendingFilesIds);

			foreach ($pendingFiles as $pendingFile)
			{
				if (!$pendingFile->isValid())
				{
					continue;
				}

				$fileId = $pendingFile->getFileId();
				$fileArray = CFile::getFileArray($fileId);

				if (!$fileArray)
				{
					$pendingFile->remove();

					continue;
				}

				$filePath = CFile::GetPath($fileId);
				if (!$filePath)
				{
					$pendingFile->remove();

					continue;
				}

				$absolutePath = $_SERVER['DOCUMENT_ROOT'] . $filePath;
				if (!file_exists($absolutePath))
				{
					$pendingFile->remove();

					continue;
				}

				$fileSize = filesize($absolutePath);
				if ($fileSize <= 0)
				{
					$pendingFile->remove();

					continue;
				}

				$totalSize += $fileSize;

				$contentId = sprintf(
					'bxacid.%s@%s.mail',
					hash('crc32b', $fileId . $fileSize . $fileArray['ORIGINAL_NAME']),
					hash('crc32b', $hostname)
				);

				$attachments[] = [
					'ID' => $contentId,
					'NAME' => $fileArray['ORIGINAL_NAME'],
					'PATH' => $absolutePath,
					'CONTENT_TYPE' => $fileArray['CONTENT_TYPE'],
				];

				$outgoingBody = preg_replace(
					sprintf('/(https?:\/\/)?bxacid:n?%u/i', $fileId),
					sprintf('cid:%s', $contentId),
					$outgoingBody
				);

				$pendingFile->makePersistent();
			}

			$maxSize = Helper\Message::getMaxAttachedFilesSize();

			if ($maxSize > 0 && $maxSize <= ceil($totalSize / 3) * 4) // base64 coef.
			{
				$this->addError(new Error('Mail message max size exceed'));

				return [];
			}
		}

		$mailboxHelper = Mailbox::findBy($data['MAILBOX_ID'] ?? null, $fromEmail);

		if ($mailboxHelper && !$mailboxHelper->isAuthenticated())
		{
			$this->addError(new Error('Mailbox authentication error'));

			return [];
		}

		if ($repliedId > 0)
		{
			$repliedMessage = MailMessageTable::getRow([
				'select' => [
					'FIELD_FROM',
					'FIELD_REPLY_TO',
					'FIELD_TO',
					'FIELD_CC',
					'FIELD_BCC',
					'MSG_ID',
					'HEADER',
					'BODY',
					'BODY_HTML',
					'SUBJECT',
					'FIELD_DATE'
				],
				'filter' => [
					'ID' => $data['REPLIED_ID'],
				],
			]);

			if (!empty($repliedMessage) && is_array($repliedMessage))
			{
				\Bitrix\Mail\Helper\Message::prepare($repliedMessage);

				$messageSanitized = true;

				if (trim($repliedMessage['BODY_HTML']))
				{
					$messageHtml = (new \Bitrix\Mail\Helper\Cache\SanitizedBodyCache())->get($repliedId);
					if (!$messageHtml)
					{
						$messageHtml = $repliedMessage['BODY_HTML'];
						$messageSanitized = false;
					}

				}
				else
				{

					$messageHtml = preg_replace('/(\s*(\r\n|\n|\r))+/', '<br>', htmlspecialcharsbx($repliedMessage['BODY']));
				}

				$repliedMessageQuote = \Bitrix\Mail\Message::wrapTheMessageWithAQuote(
					$messageHtml,
					$repliedMessage['SUBJECT'] ?? '',
					$repliedMessage['FIELD_DATE'] ?? null,
					$repliedMessage['__from'],
					$repliedMessage['__to'],
					$repliedMessage['__cc'],
					$messageSanitized,
				);

				if (!empty($repliedMessageQuote))
				{

					$outgoingBody .= $repliedMessageQuote;
				}
			}
		}

		$outgoingParams = [
			'CHARSET' => SITE_CHARSET,
			'CONTENT_TYPE' => 'html',
			'ATTACHMENT' => $attachments,
			'TO' => implode(', ', $toEncoded),
			'SUBJECT' => $data['subject'],
			'BODY' => $outgoingBody,
			'HEADER' => [
				'From' => $fromEncoded ?: $fromEmail,
				'Reply-To' => $fromEncoded ?: $fromEmail,
				'Cc' => implode(', ', $ccEncoded),
				'Bcc' => implode(', ', $bccEncoded),
			],
		];

		if (isset($repliedMessage['MSG_ID']) && (int)$data['IS_FORWARD'] !== 1)
		{
			$outgoingParams['HEADER']['In-Reply-To'] = sprintf('<%s>', $repliedMessage['MSG_ID']);
		}

		$messageId = $this->generateMessageId($hostname);
		$outgoingParams['HEADER']['Message-Id'] = $messageId;

		if ($mailboxHelper === null)
		{
			$context = new Main\Mail\Context();
			$context->setCategory(Main\Mail\Context::CAT_EXTERNAL);
			$context->setPriority(
				count($to) + count($cc) + count($bcc) > 2
					? Main\Mail\Context::PRIORITY_LOW
					: Main\Mail\Context::PRIORITY_NORMAL
			);

			Main\Mail\Mail::send(array_merge(
				$outgoingParams,
				[ 'CONTEXT' => $context ],
			));
		}
		else
		{
			$mailboxHelper->mail(array_merge(
				$outgoingParams,
				[
					'HEADER' => array_merge(
						$outgoingParams['HEADER'],
						[
							'To' => $outgoingParams['TO'],
							'Subject' => $outgoingParams['SUBJECT'],
						]
					),
				]
			));
		}

		addEventToStatFile(
			'mail',
			((empty($repliedMessage['MSG_ID']) || (int)$data['IS_FORWARD'] === 1) ? 'send_message' : 'send_reply'),
			'',
			trim(trim($messageId), '<>')
		);

		return ['MESSAGE_ID' => $messageId];
	}

	/**
	 * @throws ObjectPropertyException|ArgumentException|SystemException|LoaderException
	 */
	public function getUserContactsAction(): array
	{
		if (!$this->includeRequiredModules())
		{
			return [];
		}

		$mailSenderService = new MailSenderService();
		$senders = $mailSenderService->getSenderList();

		return [
			'senders' => $senders
		];
	}

	public function getChainAction(int $id, int $messageType = self::CRM_MESSAGE_TYPE): ?MailMessageChain
	{
		if (!Loader::includeModule('crm') || !Loader::includeModule('mail'))
		{
			return null;
		}

		if ($messageType === self::CRM_MESSAGE_TYPE)
		{
			$mailMessageChainProvider = new \Bitrix\Crm\Activity\Mail\MailMessageChainProvider();
		}
		else
		{
			$mailMessageChainProvider = new Helper\MailMessageChainProvider();
		}

		return $mailMessageChainProvider->getChain($id);
	}

	public function getMessageAction(int $id, bool $takeBody = false, bool $takeFiles = false, int $messageType = self::CRM_MESSAGE_TYPE): ?\Bitrix\Mail\Helper\Dto\MailMessage
	{
		if (!Loader::includeModule('crm') || !Loader::includeModule('mail'))
		{
			return null;
		}

		if ($messageType === self::CRM_MESSAGE_TYPE)
		{
			$mailMessageChainProvider = new \Bitrix\Crm\Activity\Mail\MailMessageChainProvider();
		}
		else
		{
			$mailMessageChainProvider = new Helper\MailMessageChainProvider();
		}

		return $mailMessageChainProvider->getMessage($id, $takeBody, $takeFiles);
	}

	/**
	 * @param Address[] $addressList
	 * @param string $fromField
	 *
	 * @return void
	 * @throws ObjectPropertyException
	 * @throws SqlQueryException
	 * @throws SystemException
	 * @throws ReflectionException
	 */
	private function appendMailContacts(array $addressList, string $fromField = ''): void
	{
		$currentUser = $this->getCurrentUser();
		if (!$currentUser)
		{
			return;
		}

		$fromField = mb_strtoupper($fromField);
		if (
			!in_array($fromField, [
				MailContactTable::ADDED_TYPE_TO,
				MailContactTable::ADDED_TYPE_CC,
				MailContactTable::ADDED_TYPE_BCC,
			], true)
		)
		{
			$fromField = MailContactTable::ADDED_TYPE_TO;
		}

		$allEmails = [];
		$contactsData = [];

		foreach ($addressList as $address)
		{
			$allEmails[] = mb_strtolower($address->getEmail());
			$contactsData[] = [
				'USER_ID' => $currentUser->getId(),
				'NAME' => $address->getName(),
				'ICON' => MailContact::getIconData($address->getEmail(), $address->getName()),
				'EMAIL' => $address->getEmail(),
				'ADDED_FROM' => $fromField,
			];
		}

		MailContactTable::addContactsBatch($contactsData);

		$mailContacts = MailContactTable::query()
			->addSelect('ID')
			->where('USER_ID', $currentUser->getId())
			->whereIn('EMAIL', $allEmails)
			->exec();

		$lastRcpt = [];
		while ($contact = $mailContacts->fetch())
		{
			$lastRcpt[] = 'MC'. $contact['ID'];
		}

		if (count($lastRcpt) > 0)
		{
			FinderDestTable::merge([
				'USER_ID' => $currentUser->getId(),
				'CONTEXT' => 'MAIL_LAST_RCPT',
				'CODE' => $lastRcpt,
			]);
		}
	}

	/**
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	private function isSenderLimitReached(string $fromEmail, int $recipientsCount): bool
	{
		$emailDailyLimit = Sender::getEmailLimit($fromEmail);
		if ($emailDailyLimit <= 0)
		{
			return false;
		}

		$emailCounter = new SenderSendCounter();
		$limit = $emailCounter->get($fromEmail);

		return ($limit + $recipientsCount) > $emailDailyLimit;
	}

	/**
	 * @throws LoaderException
	 */
	private function isDailyPortalLimitReached(int $recipientsCount): bool
	{
		if (!isModuleInstalled('bitrix24') || !Loader::includeModule('bitrix24'))
		{
			return false;
		}

		$counter = new MailCounter();
		$limit = $counter->getDailyLimit();

		return $limit > 0 && MailCounter::checkLimit($limit, $counter->get() + $recipientsCount);
	}

	/**
	 * @throws LoaderException
	 */
	private function isMonthPortalLimitReached(int $recipientsCount): bool
	{
		if (!isModuleInstalled('bitrix24') || !Loader::includeModule('bitrix24'))
		{
			return false;
		}

		$counter = new MailCounter();
		$limit = $counter->getLimit();

		return $limit > 0 && MailCounter::checkLimit($limit, $counter->getMonthly() + $recipientsCount);
	}

	private function generateMessageId(string $hostname): string
	{
		// @TODO: more entropy
		return sprintf(
			'<bx.mail.%x.%x@%s>',
			time(),
			random_int(0, 0xffffff),
			$hostname
		);
	}

	private function getHostname()
	{
		static $hostname;
		if (empty($hostname))
		{
			$hostname = COption::getOptionString('main', 'server_name', '') ?: 'localhost';
			if (defined('BX24_HOST_NAME') && BX24_HOST_NAME !== '')
			{
				$hostname = BX24_HOST_NAME;
			}
			elseif (defined('SITE_SERVER_NAME') && SITE_SERVER_NAME !== '')
			{
				$hostname = SITE_SERVER_NAME;
			}
		}

		return $hostname;
	}

	private function hasUserAccessToMessage(int $messageId): bool
	{
		if (!Loader::includeModule('mail'))
		{
			return false;
		}

		$mailboxId = Helper\Mailbox::getIdByMessageId($messageId);
		if (!$mailboxId)
		{
			return false;
		}

		return MailboxAccess::hasCurrentUserAccessToMailbox($mailboxId, true);
	}
}
