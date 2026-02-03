<?php

namespace Bitrix\Mail\Helper;

use Bitrix\Mail\MailMessageUidTable;
use Bitrix\Main\ErrorCollection;
use Bitrix\Mail\Helper\Dto\MailMessageChain;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Mail\MailMessageTable;
use Bitrix\Mail\Helper\Dto\MailMessage;
use Bitrix\Mail;
use Bitrix\Mobile\UI;
use Bitrix\Mail\Message;
use Bitrix\Mail\Helper;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\Entity\ReferenceField;

class MailMessageChainProvider extends AbstractMailMessageChainProvider
{
	const SELECT_MESSAGE_FIELDS = [
		'MAILBOX_EMAIL' => 'MAILBOX.EMAIL',
		'UID_ID' => 'MESSAGE_UID.ID',
		'IS_SEEN' => 'MESSAGE_UID.IS_SEEN',
		'ID',
		'MAILBOX_ID',
		'FIELD_DATE',
		'SUBJECT',
		'BODY_HTML',
		'HEADER',
		'OPTIONS',
	];

	const SELECT_MESSAGE_FIELDS_FOR_TAKE_ATTACHMENTS = [
		'ID',
		'OPTIONS',
		'MAILBOX_ID',
	];

	const SELECT_RECIPIENTS_FIELDS = [
		'FIELD_FROM',
		'FIELD_REPLY_TO',
		'FIELD_TO',
		'FIELD_CC',
		'FIELD_BCC',
	];

	public ErrorCollection $errorCollection;

	public function __construct()
	{
		$this->errorCollection = new ErrorCollection();
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

	private function getMessageAsArray(int $id, ?array $select = null): ?array
	{
		if (!$this->hasUserAccessToMessage($id))
		{
			return null;
		}

		if (is_null($select))
		{
			$select = array_merge(
				self::SELECT_MESSAGE_FIELDS,
				self::SELECT_RECIPIENTS_FIELDS
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
				)
			)
			->registerRuntimeField(
				new Reference(
					'MESSAGE_UID',
					MailMessageUidTable::class,
					[
						'=this.MAILBOX_ID' => 'ref.MAILBOX_ID',
						'=this.ID' => 'ref.MESSAGE_ID',
					],
					['join_type' => 'INNER']
				)
			)
			->setSelect($select)
			->setFilter(
				[
					'=ID' => $id
				],
			)->setLimit(1)->exec()->fetchAll();

		if (count($threadMessageRows) > 0)
		{
			return $threadMessageRows[0];
		}

		return null;
	}

	protected function getMessageFilesLinkMessages(int $id, bool $forMobile = true): array
	{
		$attachments = Mail\Internals\MailMessageAttachmentTable::getList([
			'select' => [
				'ID',
				'FILE_ID',
				'FILE_NAME',
			],
			'filter' => [
				'=MESSAGE_ID' => $id,
			],
		])->fetchAll();

		$filesInfo = [];

		foreach ($attachments as $attachment)
		{
			if ($forMobile)
			{
				if (\Bitrix\Main\Loader::includeModule('mobile'))
				{
					$diskFile = UI\File::loadWithPreview($attachment['FILE_ID']);

					if ($diskFile)
					{
						$diskFileInfo = $diskFile->getInfo();
						/*
							For security reasons, the file is renamed when stored,
							which is why it is required to restore its real name.
						*/
						$diskFileInfo['name'] = $attachment['FILE_NAME'];
						$diskFileInfo[self::KEY_ID_IN_MESSAGE_BODY] = (int)$attachment['ID'];
						$filesInfo[] = $diskFileInfo;

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

	public function getMessage(int $id, bool $takeBody = false, bool $takeFiles = false): MailMessage
	{
		$message = new MailMessage();

		if (!$this->hasUserAccessToMessage($id))
		{
			return $message;
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

			$message->uidId = $messageData['UID_ID'].'-'.$messageData['MAILBOX_ID'];
			$message->body = $this->cleanCharset($messageData['BODY_HTML']);
			$message->id = $messageData['ID'];
			$message->subject = $messageData['SUBJECT'];
			$message->date = $messageData['FIELD_DATE']->getTimestamp();
			$message->replyFromEmail = $messageData['MAILBOX_EMAIL'];
		}

		if ($takeFiles)
		{
			$messageData = $this->getMessageAsArray($id, self::SELECT_MESSAGE_FIELDS_FOR_TAKE_ATTACHMENTS);
			$messageId = (int)$messageData['ID'];

			if (isset($messageData['OPTIONS']['attachments']) &&  isset($messageData['OPTIONS']['attachments']) > 0)
			{
				$message->withAttachments = (int)($messageData['OPTIONS']['attachments']);
			}

			$message->attachments = $this->getMessageFilesLinkMessages($id);

			if (empty($message->attachments) && $message->withAttachments !== 0)
			{
				$messageAttachments = new AttachmentHelper((int)$messageData['MAILBOX_ID'], $messageId);
				$messageAttachments->update();
				$message->attachments = $this->getMessageFilesLinkMessages($id);
			}

			$message->body = $this->replaceAttachmentPlaceholders($message->body, $message->attachments);
		}

		return $message;
	}

	private function getMessagesRows(bool $takeParentMessages, int $threadId, int $limit = 50): array
	{
		$messageQuery = new Query(MailMessageTable::getEntity());

		if ($takeParentMessages)
		{
			$order = [
				'FIELD_DATE' => 'DESC'
			];

			$filter = [
				'=CLOSURE.MESSAGE_ID' => $threadId,
			];

			$mergeFilter = [
				'=this.ID' => 'ref.PARENT_ID',
			];
		}
		else
		{
			$order = [
				'FIELD_DATE' => 'ASC'
			];

			$filter = [
				'=CLOSURE.PARENT_ID' => $threadId,
			];

			$mergeFilter = [
				'=this.ID' => 'ref.MESSAGE_ID',
			];
		}

		$selectChainNodes = array_merge(
			self::SELECT_MESSAGE_FIELDS,
			self::SELECT_RECIPIENTS_FIELDS
		);
		unset($selectChainNodes['BODY_HTML']);

		return $messageQuery
			->registerRuntimeField(
				new ReferenceField(
					'MAILBOX',
					Mail\MailboxTable::class,
					[
						'=this.MAILBOX_ID' => 'ref.ID',
					],
					['join_type' => 'INNER'],
				)
			)
			->registerRuntimeField(
				new ReferenceField(
					'CLOSURE',
					Mail\Internals\MessageClosureTable::class,
					$mergeFilter,
					['join_type' => 'INNER'],
				)
			)
			->registerRuntimeField(
				new Reference(
					'MESSAGE_UID',
					MailMessageUidTable::class,
					[
						'=this.MAILBOX_ID' => 'ref.MAILBOX_ID',
						'=this.ID' => 'ref.MESSAGE_ID',
					],
					['join_type' => 'INNER']
				)
			)
			->setSelect($selectChainNodes)
			->setFilter($filter)
			->setOrder($order)
			->setOffset(1)
			->setLimit($limit)->exec()->fetchAll();
	}

	public function getChain(int $messageId): MailMessageChain
	{
		$mailMessageChain = new MailMessageChain();

		if (!$this->hasUserAccessToMessage($messageId))
		{
			return $mailMessageChain;
		}

		$threadMessageRow = $this->getMessageAsArray($messageId);

		if (is_null($threadMessageRow))
		{
			return $mailMessageChain;
		}

		$threadMessageRowId = (int)$threadMessageRow['ID'];

		$childMessagesRows = $this->getMessagesRows(false, $messageId);
		$parentMessagesRows = $this->getMessagesRows(true, $messageId);

		$allRows = array_merge($childMessagesRows, [$threadMessageRow], $parentMessagesRows);

		$messages = [];

		$lastIncomingId = null;
		$lastIncomingKey = null;
		$index = 0;

		$uniqueMessages = [];

		foreach ($allRows as $row)
		{
			$messageId = (int)$row['ID'];

			/*
				In some services, the same letter can be located in different folders
				(if the folders are shortcuts)
			*/
			if (isset($uniqueMessages[$messageId]))
			{
				continue;
			}

			$uniqueMessages[$messageId] = true;

			$mailMessage = new MailMessage();
			$mailMessage->id = $messageId;
			$mailMessage->uidId = $row['UID_ID'].'-'.$row['MAILBOX_ID'];

			if ($row['IS_SEEN'] === 'Y')
			{
				$mailMessage->isRead = true;
			}

			if (isset($row['SUBJECT']))
			{
				$mailMessage->subject = $row['SUBJECT'];
			}

			$mailMessage->date = $row['FIELD_DATE']->getTimestamp();
			$mailMessage->replyFromEmail = $row['MAILBOX_EMAIL'];

			if (isset($row['BODY_HTML']))
			{
				$mailMessage->body = $this->cleanCharset($row['BODY_HTML']);
			}

			if ($threadMessageRowId === (int)$row['ID'])
			{
				$mailMessage->attachments = $this->getMessageFilesLinkMessages($row['ID']);
				$mailMessage->body = $this->replaceAttachmentPlaceholders($mailMessage->body, $mailMessage->attachments);
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
			$full = $this->getMessage($lastIncomingId, true, true);
			$mailMessageChain->list[$lastIncomingKey]->body = $full->body;
			$mailMessageChain->list[$lastIncomingKey]->attachments = $full->attachments;
		}

		$mailMessageChain->properties = [
			'lastIncomingId' => $lastIncomingId,
		];

		return $mailMessageChain;
	}
}