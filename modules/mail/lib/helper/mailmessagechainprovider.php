<?php

namespace Bitrix\Mail\Helper;

use Bitrix\MailMobile\Internal\Services\MessageLoader;
use Bitrix\Mail\Internals\MessageAccessTable;
use Bitrix\Mail\MailMessageUidTable;
use Bitrix\Mail\Public\Service\Access\MessageAccessService;
use Bitrix\Main\Error;
use Bitrix\Main\ErrorCollection;
use Bitrix\Mail\Helper\Dto\MailMessageChain;
use Bitrix\Mail\Helper\Message\Loader\QueryBuilder;
use Bitrix\Mail\Helper\Attachment\Storage;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Mail\MailMessageTable;
use Bitrix\Mail\Helper\Dto\MailMessage;
use Bitrix\Mail;
use Bitrix\Mobile\UI;
use Bitrix\Mail\Message;
use Bitrix\Mail\Helper;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\Entity\ReferenceField;
use Bitrix\Main;
use Bitrix\Main\Result;

class MailMessageChainProvider extends AbstractMailMessageChainProvider
{
	/** Side of the square attachment preview, shared by the mobile file info and the disk url. */
	protected const ATTACHMENT_PREVIEW_SIZE = 120;

	/** Named here because the select of a chain is the same list with this one field taken out of it. */
	protected const BODY_FIELD = 'BODY_HTML';

	protected const SELECT_MESSAGE_FIELDS = [
		'MAILBOX_EMAIL' => 'MAILBOX.EMAIL',
		'UID_ID' => 'MESSAGE_UID.ID',
		'IS_SEEN' => 'MESSAGE_UID.IS_SEEN',
		'ID',
		'MAILBOX_ID',
		'FIELD_DATE',
		'INTERNALDATE' => 'MESSAGE_UID.INTERNALDATE',
		'SUBJECT',
		self::BODY_FIELD,
		'HEADER',
		'OPTIONS',
	];

	protected const SELECT_MESSAGE_ACCESS_FIELDS = [
		'BIND_ENTITY_TYPE' => 'MESSAGE_ACCESS.ENTITY_TYPE',
		'BIND_ENTITY_ID' => 'MESSAGE_ACCESS.ENTITY_ID',
	];

	protected const SELECT_MESSAGE_CRM_ACCESS_FIELDS = [
		'CRM_ACTIVITY_OWNER_TYPE_ID' => 'MESSAGE_ACCESS.CRM_ACTIVITY.OWNER_TYPE_ID',
		'CRM_ACTIVITY_OWNER_ID' => 'MESSAGE_ACCESS.CRM_ACTIVITY.OWNER_ID',
	];

	protected const SELECT_MESSAGE_FIELDS_FOR_TAKE_ATTACHMENTS = [
		'ID',
		'OPTIONS',
		'MAILBOX_ID',
	];

	protected const SELECT_RECIPIENTS_FIELDS = [
		'FIELD_FROM',
		'FIELD_REPLY_TO',
		'FIELD_TO',
		'FIELD_CC',
		'FIELD_BCC',
	];

	public ErrorCollection $errorCollection;

	/**
	 * Whether an attachment is reported with the disk object of the module storage as well. Off by default:
	 * the object is looked for and, when absent, created, and reading a message may not pay for what only a
	 * forward of a heavy set needs. A consumer that offers such a forward asks for the objects itself, see
	 * the enabling method below.
	 */
	protected bool $withAttachmentDiskObjects = false;

	/**
	 * Disk objects of the attachments of the message being read, by the stored file behind them. Filled once
	 * per message by loadAttachmentDiskObjects and read per attachment, so a message of many attachments
	 * costs one search instead of one per file. An object created for an attachment that had none is put
	 * back here: two attachments of one message may stand behind the same stored file, and the second of
	 * them must find the object the first one created instead of creating a second.
	 *
	 * @var array<int, object>
	 */
	private array $attachmentDiskObjectsByFileId = [];

	public function __construct()
	{
		$this->errorCollection = new ErrorCollection();
	}

	protected function getMessageAsArray(int $id, ?array $select = null): ?array
	{
		if (is_null($select))
		{
			$select = array_merge(
				self::SELECT_MESSAGE_FIELDS,
				self::SELECT_RECIPIENTS_FIELDS,
			);
		}

		$messageQuery = new Query(MailMessageTable::getEntity());

		$threadMessageRows = $messageQuery
			->registerRuntimeField(
				new ReferenceField(
					'MAILBOX',
					Mail\MailboxTable::class,
					[
						'=this.MAILBOX_ID' => 'ref.ID',
					],
					['join_type' => 'INNER'],
				),
			)
			->registerRuntimeField(
				new Reference(
					'MESSAGE_UID',
					MailMessageUidTable::class,
					[
						'=this.MAILBOX_ID' => 'ref.MAILBOX_ID',
						'=this.ID' => 'ref.MESSAGE_ID',
					],
					['join_type' => 'INNER'],
				),
			)
			->setSelect($select)
			->setFilter(
				array_merge(
					['=ID' => $id],
					QueryBuilder::generationScopeFilterOfMessages([$id], 'MESSAGE_UID.'),
				),
			)->setLimit(1)->exec()->fetchAll()
		;

		if (count($threadMessageRows) > 0)
		{
			return $threadMessageRows[0];
		}

		return null;
	}

	/**
	 * Full and read access alike are decided by the access service, the single place holding that rule.
	 */
	protected function resolveMessageAccess(int $messageId, string $token = ''): Result
	{
		$mailboxId = $this->getMessageMailboxId($messageId);
		if ($mailboxId <= 0)
		{
			return (new Result())->addError(
				new Error('Access to the mail message was denied.', MessageAccessService::ERROR_ACCESS_DENIED),
			);
		}

		/*
			The screens behind this provider have always admitted the owner of a mailbox of theirs,
			and a mailbox stays theirs once it stops syncing or was never an IMAP one. The synced-mailbox
			set the access service works with is narrower, so asking it first would lock people out of
			letters they already have.
		*/
		if ($this->hasFullAccessToMailbox($mailboxId))
		{
			return (new Result())->setData([
				MessageAccessService::RESULT_ACCESS_LEVEL => MessageAccessService::ACCESS_LEVEL_FULL,
			]);
		}

		return $this->getMessageAccessService()->resolve(
			$mailboxId,
			$messageId,
			$this->getCurrentUserId(),
			$token,
		);
	}

	protected function hasFullAccessToMailbox(int $mailboxId): bool
	{
		return MailboxAccess::hasUserAccessToMailbox($mailboxId, $this->getCurrentUserId(), true);
	}

	protected function getMessageMailboxId(int $messageId): int
	{
		return Helper\Mailbox::getIdByMessageId($messageId);
	}

	protected function getMessageAccessService(): MessageAccessService
	{
		return new MessageAccessService();
	}

	protected function getCurrentUserId(): int
	{
		return (int)CurrentUser::get()->getId();
	}

	protected function addMessageBinding(MailMessage $mailMessage, array $row): void
	{
		MessageLoader::addBinding($mailMessage, $row);
	}

	protected function findMailbox(int $mailboxId): ?Mailbox
	{
		return Mailbox::findBy($mailboxId);
	}

	protected function loadAttachments(
		MailMessage $mailMessage,
		int $mailboxId,
		int $attachmentsCount,
		string $token = '',
	): void
	{
		if (empty($attachmentsCount))
		{
			return;
		}

		$mailMessage->attachments = $this->getMessageFilesLinkMessages($mailMessage->id, true, $token);

		if (empty($mailMessage->attachments))
		{
			$messageAttachments = new AttachmentHelper($mailboxId, $mailMessage->id);
			$messageAttachments->update();
			$mailMessage->attachments = $this->getMessageFilesLinkMessages($mailMessage->id, true, $token);

			$updatedBody = $messageAttachments->getBody();
			if ($updatedBody !== null)
			{
				$mailMessage->body = $this->cleanCharset($updatedBody);
			}
		}

		$mailMessage->body = $this->replaceAttachmentPlaceholders($mailMessage->body, $mailMessage->attachments);
	}

	protected function getMessageFilesLinkMessages(
		int $id,
		bool $forMobile = true,
		string $token = '',
	): array
	{
		$attachments = Mail\Internals\MailMessageAttachmentTable::getList([
			'select' => [
				'ID',
				'FILE_ID',
				'FILE_NAME',
				'FILE_SIZE',
			],
			'filter' => [
				'=MESSAGE_ID' => $id,
			],
		])->fetchAll();

		$filesInfo = [];
		$diskObjects = $token === '' ? [] : $this->getAttachmentDiskObjects($attachments);

		// Only when the consumer asked for it, and once for the whole message: see the batch below
		$this->loadAttachmentDiskObjects($attachments);

		foreach ($attachments as $attachment)
		{
			if ($forMobile)
			{
				if (\Bitrix\Main\Loader::includeModule('mobile'))
				{
					$diskFile = UI\File::loadWithPreview($attachment['FILE_ID'], [
						'width' => self::ATTACHMENT_PREVIEW_SIZE,
						'height' => self::ATTACHMENT_PREVIEW_SIZE,
					]);

					if ($diskFile)
					{
						$diskFileInfo = $diskFile->getInfo();
						/*
							For security reasons, the file is renamed when stored,
							which is why it is required to restore its real name.
						*/
						$diskFileInfo['name'] = $attachment['FILE_NAME'];
						$diskFileInfo[self::KEY_ID_IN_MESSAGE_BODY] = (int)$attachment['ID'];
						// Only when the consumer asked for it: an ordinary read of a message is left untouched
						$this->addAttachmentDiskObject($diskFileInfo, $attachment);
						$diskFileInfo = $this->addAccessTokenToAttachmentUrls(
							$diskFileInfo,
							$token,
							$diskObjects[(int)$attachment['FILE_ID']] ?? null,
						);

						if ($diskFileInfo !== null)
						{
							$filesInfo[] = $diskFileInfo;
						}
					}
				}
			}
			else
			{
				// @TODO: Add support for loading files to the web version
			}
		}

		return $filesInfo;
	}

	/**
	 * Asks for the disk objects of the attachments read afterwards, see addAttachmentDiskObject below. The ask
	 * belongs to a consumer whose own scenario of a heavy forward is available, and without it this class never
	 * touches the storage of the module.
	 */
	public function enableAttachmentDiskObjects(): void
	{
		$this->withAttachmentDiskObjects = true;
	}

	/**
	 * The disk objects of every attachment of one message, asked for in a single search. The enrichment runs
	 * twice on every opening of a chain (the anchor message and the last incoming one), so a search per
	 * attachment was the whole cost of that opening.
	 *
	 * A list that carries no stored file does not reach the storage at all: the batch answer reaches for the
	 * storage of the module before it looks at the list, and the storage is created on the first ask, so an
	 * empty message would start paying for what it does not use.
	 *
	 * @param array $attachments Attachment rows with FILE_ID.
	 */
	protected function loadAttachmentDiskObjects(array $attachments): void
	{
		$this->attachmentDiskObjectsByFileId = [];

		if (!$this->withAttachmentDiskObjects)
		{
			return;
		}

		$fileIds = [];
		foreach ($attachments as $attachment)
		{
			$fileId = (int)($attachment['FILE_ID'] ?? 0);
			if ($fileId > 0 && !in_array($fileId, $fileIds, true))
			{
				$fileIds[] = $fileId;
			}
		}

		if ($fileIds === [])
		{
			return;
		}

		$this->attachmentDiskObjectsByFileId = $this->findAttachmentDiskObjects($fileIds);
	}

	/**
	 * Enriches an attachment with its disk object in the module storage: the object id, the stored file behind
	 * it and the raw size. A forwarding form reads them to shape the attachment the way the web does (n<object>
	 * with a size), so the large attachment scenario converts a heavy forward the ordinary way. The object is
	 * created once and reuses the existing file, spending neither a copy nor the user quota; when the disk or
	 * the storage is unavailable the attachment is left as is and a light forward still attaches it by file id.
	 *
	 * Nothing of this happens unless the consumer asked for it: creating an object costs a search and a write,
	 * and the read path of a message carries that cost for no one.
	 *
	 * @param array $diskFileInfo Attachment info built for the mobile form; modified in place.
	 * @param array $attachment Attachment row with FILE_ID, FILE_NAME and FILE_SIZE.
	 */
	protected function addAttachmentDiskObject(array &$diskFileInfo, array $attachment): void
	{
		if (!$this->withAttachmentDiskObjects)
		{
			return;
		}

		$fileId = (int)($attachment['FILE_ID'] ?? 0);
		if ($fileId <= 0)
		{
			return;
		}

		$object = $this->attachmentDiskObjectsByFileId[$fileId] ?? null;
		if ($object === null)
		{
			$object = $this->registerAttachmentDiskObject($attachment) ?: null;
			if ($object === null)
			{
				return;
			}

			// the batch was answered before the loop and knows nothing of this object, so the next
			// attachment of the same stored file finds it here instead of creating a second one
			$this->attachmentDiskObjectsByFileId[$fileId] = $object;
		}

		$diskFileInfo['objectId'] = (int)$object->getId();
		$diskFileInfo['fileId'] = (int)$object->getFileId();
		$diskFileInfo['bytes'] = (int)$object->getSize();
		$diskFileInfo['size'] = (int)$object->getSize();
	}

	/**
	 * The disk objects that already exist among the stored files named, by the stored file. Nothing is
	 * created here. One of the two places this class reaches the storage, so a consumer that asked for
	 * nothing can be told apart from one that did.
	 *
	 * @param int[] $fileIds
	 *
	 * @return array<int, object>
	 */
	protected function findAttachmentDiskObjects(array $fileIds): array
	{
		return Attachment\Storage::getObjectsByFileIds($fileIds);
	}

	/**
	 * An attachment whose url cannot carry the token is dropped whole instead of being served
	 * without it: the reader holds the message by that token only, a plain disk url would outlive
	 * it, and a row left with no url is an attachment that does nothing when tapped.
	 */
	protected function addAccessTokenToAttachmentUrls(
		array $fileInfo,
		string $token,
		object|false|null $diskObject,
	): ?array
	{
		if ($token === '')
		{
			return $fileInfo;
		}

		$urlParams = ['mail_uf_message_token' => $token];
		$url = $diskObject ? $this->buildAttachmentUrl($diskObject, $urlParams) : null;

		if ($url === null)
		{
			return null;
		}

		$fileInfo['url'] = $url;

		if (isset($fileInfo['previewUrl']))
		{
			$previewUrl = $this->buildAttachmentPreviewUrl($diskObject, $urlParams);
			if ($previewUrl === null)
			{
				unset($fileInfo['previewUrl']);
			}
			else
			{
				$fileInfo['previewUrl'] = $previewUrl;
			}
		}

		return $fileInfo;
	}

	/** @return array<int, object> Map of file ID to disk object. */
	protected function getAttachmentDiskObjects(array $attachments): array
	{
		$fileIds = array_values(array_unique(array_map(
			static fn(array $attachment): int => (int)$attachment['FILE_ID'],
			$attachments,
		)));
		$diskObjects = $this->getExistingAttachmentDiskObjects($fileIds);

		foreach ($attachments as $attachment)
		{
			$fileId = (int)$attachment['FILE_ID'];
			if ($fileId <= 0 || isset($diskObjects[$fileId]))
			{
				continue;
			}

			$diskObject = $this->registerAttachmentDiskObject($attachment);
			if ($diskObject)
			{
				$diskObjects[$fileId] = $diskObject;
			}
		}

		return $diskObjects;
	}

	protected function getExistingAttachmentDiskObjects(array $fileIds): array
	{
		return Storage::getObjectsByFileIds($fileIds);
	}

	protected function registerAttachmentDiskObject(array $attachment): object|false|null
	{
		return Storage::registerAttachment($attachment);
	}

	protected function buildAttachmentUrl(object $diskObject, array $urlParams): ?string
	{
		return Storage::getFileUrl($diskObject, $urlParams);
	}

	protected function buildAttachmentPreviewUrl(object $diskObject, array $urlParams): ?string
	{
		// the preview exists, so the file is an image: the flag saves disk one file row per attachment
		return Storage::getFilePreviewUrl($diskObject, self::ATTACHMENT_PREVIEW_SIZE, $urlParams, true);
	}

	public static function fillRecipients(MailMessage $message, array $fields): void
	{
		$fields = Helper\Message::prepare($fields);

		$message->bcc = Message::getSelectedRecipientsForDialog($fields['__bcc']);
		$message->cc = Message::getSelectedRecipientsForDialog($fields['__cc']);
		$message->to = Message::getSelectedRecipientsForDialog($fields['__to']);
		$message->from = Message::getSelectedRecipientsForDialog($fields['__from']);
		$message->replyTo = Message::getSelectedRecipientsForDialog($fields['__reply_to']);

		if ($fields['__is_outcome'])
		{
			$message->direction = MailMessage::DIRECTION_OUTGOING;
		}
		else
		{
			$message->direction = MailMessage::DIRECTION_INCOMING;
		}
	}

	public function getMessage(
		int $id,
		bool $takeBody = false,
		bool $takeFiles = false,
		string $token = '',
	): MailMessage
	{
		$message = new MailMessage();
		$accessResult = $this->resolveMessageAccess($id, $token);

		if (!$accessResult->isSuccess())
		{
			return $message;
		}

		$accessLevel = $accessResult->getData()[MessageAccessService::RESULT_ACCESS_LEVEL] ?? null;
		$attachmentToken = $accessLevel === MessageAccessService::ACCESS_LEVEL_READ ? $token : '';
		if ($accessLevel === MessageAccessService::ACCESS_LEVEL_READ)
		{
			$message->readOnly = true;
		}

		if ($takeBody)
		{
			$messageData = $this->getMessageAsArray($id);

			if (is_null($messageData))
			{
				return $message;
			}

			if ($messageData['IS_SEEN'] === 'Y')
			{
				$message->isRead = true;
			}

			$message->uidId = $messageData['UID_ID'] . '-' . $messageData['MAILBOX_ID'];
			$message->body = $this->cleanCharset($messageData['BODY_HTML']);
			$message->id = $messageData['ID'];
			$message->subject = $messageData['SUBJECT'];
			$message->date = ($messageData['INTERNALDATE'] ?? $messageData['FIELD_DATE'])->getTimestamp();
			$message->replyFromEmail = $messageData['MAILBOX_EMAIL'];
			$message->mailboxId = (int)$messageData['MAILBOX_ID'];
		}

		if ($takeFiles)
		{
			$messageData = $this->getMessageAsArray($id, self::SELECT_MESSAGE_FIELDS_FOR_TAKE_ATTACHMENTS);

			if (is_null($messageData))
			{
				return $message;
			}

			if (isset($messageData['OPTIONS']['attachments']) &&  isset($messageData['OPTIONS']['attachments']) > 0)
			{
				$message->withAttachments = (int)($messageData['OPTIONS']['attachments']);
			}

			$this->loadAttachments(
				$message,
				(int)$messageData['MAILBOX_ID'],
				(int)($messageData['OPTIONS']['attachments'] ?? 0),
				$attachmentToken,
			);
		}

		return $message;
	}

	/**
	 * Fields a card of a chain is drawn from.
	 *
	 * The body is not among them: the reader opens one message, and the body of a card opened afterwards is
	 * asked for by that card alone. The field stands in the list of message fields without a key of its own,
	 * so removing a key of its name leaves it in the select and puts the body of every message of the chain,
	 * a hundred of them at most, into the answer of the first opening.
	 *
	 * @return array<int|string, string>
	 */
	protected function getChainNodeSelect(): array
	{
		$select = array_merge(
			self::SELECT_MESSAGE_FIELDS,
			self::SELECT_RECIPIENTS_FIELDS,
			self::SELECT_MESSAGE_ACCESS_FIELDS,
		);

		if (Main\Loader::includeModule('crm'))
		{
			$select = array_merge(
				$select,
				self::SELECT_MESSAGE_CRM_ACCESS_FIELDS,
			);
		}

		return array_diff($select, [self::BODY_FIELD]);
	}

	protected function getMessagesRows(bool $takeParentMessages, int $threadId, int $limit = 50): array
	{
		$messageQuery = new Query(MailMessageTable::getEntity());

		if ($takeParentMessages)
		{
			$order = [
				'MESSAGE_UID.INTERNALDATE' => 'DESC',
			];

			$filter = [
				'=CLOSURE.MESSAGE_ID' => $threadId,
				'==MESSAGE_UID.DELETE_TIME' => 0,
				'!@MESSAGE_UID.IS_OLD' => MailMessageUidTable::HIDDEN_STATUSES,
			];

			$mergeFilter = [
				'=this.ID' => 'ref.PARENT_ID',
			];
		}
		else
		{
			$order = [
				'MESSAGE_UID.INTERNALDATE' => 'ASC',
			];

			$filter = [
				'=CLOSURE.PARENT_ID' => $threadId,
				'==MESSAGE_UID.DELETE_TIME' => 0,
				'!@MESSAGE_UID.IS_OLD' => MailMessageUidTable::HIDDEN_STATUSES,
			];

			$mergeFilter = [
				'=this.ID' => 'ref.MESSAGE_ID',
			];
		}

		// A chain never leaves the mailbox of its root: the closure is built inside one mailbox
		$filter = array_merge(
			$filter,
			QueryBuilder::generationScopeFilterOfMessages([$threadId], 'MESSAGE_UID.'),
		);

		return $messageQuery
			->registerRuntimeField(
				new ReferenceField(
					'MAILBOX',
					Mail\MailboxTable::class,
					[
						'=this.MAILBOX_ID' => 'ref.ID',
					],
					['join_type' => 'INNER'],
				),
			)
			->registerRuntimeField(
				new ReferenceField(
					'CLOSURE',
					Mail\Internals\MessageClosureTable::class,
					$mergeFilter,
					['join_type' => 'INNER'],
				),
			)
			->registerRuntimeField(
				new Reference(
					'MESSAGE_UID',
					MailMessageUidTable::class,
					[
						'=this.MAILBOX_ID' => 'ref.MAILBOX_ID',
						'=this.ID' => 'ref.MESSAGE_ID',
					],
					['join_type' => 'INNER'],
				),
			)
			->registerRuntimeField(
				new Reference(
					'MESSAGE_ACCESS',
					MessageAccessTable::class,
					[
						'=this.MAILBOX_ID' => 'ref.MAILBOX_ID',
						'=this.ID' => 'ref.MESSAGE_ID',
					],
				),
			)
			->setSelect($this->getChainNodeSelect())
			->setFilter($filter)
			->setOrder($order)
			->setOffset(1)
			->setLimit($limit)->exec()->fetchAll()
		;
	}

	public function getChain(int $messageId, string $token = ''): MailMessageChain
	{
		$mailMessageChain = new MailMessageChain();
		$accessResult = $this->resolveMessageAccess($messageId, $token);

		if (!$accessResult->isSuccess())
		{
			return $mailMessageChain;
		}

		$accessLevel = $accessResult->getData()[MessageAccessService::RESULT_ACCESS_LEVEL] ?? null;
		$attachmentToken = $accessLevel === MessageAccessService::ACCESS_LEVEL_READ ? $token : '';

		$threadMessageRow = $this->getMessageAsArray($messageId);

		if (is_null($threadMessageRow))
		{
			return $mailMessageChain;
		}

		$threadMessageRowId = (int)$threadMessageRow['ID'];

		$childMessagesRows = $this->getMessagesRows(false, $messageId);

		/*
			A signed pass covers the message it was issued for and its descendants alone - the
			closure it is checked against holds no ancestor. The history above the anchor is served
			to full access only, as in the web view of a message.
		*/
		$parentMessagesRows = $accessLevel === MessageAccessService::ACCESS_LEVEL_FULL
			? $this->getMessagesRows(true, $messageId)
			: [];

		$allRows = self::deduplicateRowsByMessageId(
			array_merge($childMessagesRows, [$threadMessageRow], $parentMessagesRows),
		);
		foreach ($allRows as $key => $row)
		{
			if ((int)$row['ID'] === $threadMessageRowId)
			{
				$allRows[$key] = array_replace($row, $threadMessageRow);

				break;
			}
		}

		$messages = [];

		$lastIncomingId = null;
		$lastIncomingKey = null;
		$mailboxId = (int)$threadMessageRow['MAILBOX_ID'];
		$index = 0;

		foreach ($allRows as $row)
		{
			$messageId = (int)$row['ID'];

			$mailMessage = new MailMessage();
			$mailMessage->id = $messageId;
			$mailMessage->uidId = $row['UID_ID'] . '-' . $row['MAILBOX_ID'];

			if ($row['IS_SEEN'] === 'Y')
			{
				$mailMessage->isRead = true;
			}

			if (isset($row['SUBJECT']))
			{
				$mailMessage->subject = $row['SUBJECT'];
			}

			$mailMessage->date = ($row['INTERNALDATE'] ?? $row['FIELD_DATE'])->getTimestamp();
			$mailMessage->replyFromEmail = $row['MAILBOX_EMAIL'];
			$mailMessage->mailboxId = (int)$row['MAILBOX_ID'];
			$this->addMessageBinding($mailMessage, $row);

			if (isset($row['BODY_HTML']))
			{
				$mailMessage->body = $this->cleanCharset($row['BODY_HTML']);
			}

			if ($threadMessageRowId === (int)$row['ID'])
			{
				$this->loadAttachments(
					$mailMessage,
					(int)$row['MAILBOX_ID'],
					(int)($row['OPTIONS']['attachments'] ?? 0),
					$attachmentToken,
				);
			}

			$this->fillRecipients($mailMessage, $row);

			if (is_null($lastIncomingId) && isset($mailMessage->direction) && $mailMessage->direction === MailMessage::DIRECTION_INCOMING)
			{
				$lastIncomingId = $mailMessage->id;
				$lastIncomingKey = $index;
			}

			$messages[] = $mailMessage;
			$index++;
		}

		$mailMessageChain->list = $messages;

		if (
			$lastIncomingKey !== null
			&& $lastIncomingId !== null
			&& $lastIncomingId !== $threadMessageRowId
		)
		{
			$full = $this->getMessage($lastIncomingId, true, true, $token);
			$mailMessageChain->list[$lastIncomingKey]->body = $full->body;
			$mailMessageChain->list[$lastIncomingKey]->attachments = $full->attachments;
		}

		$mailboxHelper = $this->findMailbox($mailboxId);
		$dirs = $mailboxHelper
			? $mailboxHelper->getDirsHelper()->buildDirectoryTreeForContextMenu($mailboxId, $mailboxHelper)
			: [];

		$mailMessageChain->properties = [
			'lastIncomingId' => $lastIncomingId,
			'dirs' => $dirs,
		];

		if ($accessLevel === MessageAccessService::ACCESS_LEVEL_READ)
		{
			$mailMessageChain->properties['readOnly'] = true;
		}

		return $mailMessageChain;
	}
}
