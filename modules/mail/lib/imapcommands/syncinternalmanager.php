<?php

namespace Bitrix\Mail\ImapCommands;

use Bitrix\Mail;
use Bitrix\Mail\Helper\Mailbox;
use Bitrix\Mail\Internals\MailboxDirectoryTable;
use Bitrix\Main;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/**
 * Class SyncInternalManager
 * @package Bitrix\Mail\ImapCommands
 */
class SyncInternalManager
{
	const FLAG_UNSEEN = 'unseen';
	const FLAG_SEEN = 'seen';

	protected $userId;
	protected $mailbox;
	protected $mailboxId;
	protected $mailboxUserId;
	protected $messagesIds;
	protected $messages;
	protected bool $deferredPushCancelled = false;
	/** @var int[]|null message ids captured before the operation */
	protected ?array $deferredPushTargets = null;
	private $isInit;
	/** @var Repository */
	protected $repository;
	/** @var Mailbox */
	protected $mailboxHelper;

	public function __construct($mailboxId, $messagesIds, $userId = null)
	{
		$this->mailboxId = $mailboxId;
		if (!is_array($messagesIds))
		{
			$messagesIds = [$messagesIds];
		}
		$this->messagesIds = $messagesIds;
		$this->userId = $userId;
		$this->repository = $this->getRepository();
		$this->mailboxHelper = $this->getMailClientHelper();
	}

	public function setUserId($userId)
	{
		$this->userId = $userId;
	}

	protected function getRepository()
	{
		return new Repository($this->mailboxId, $this->messagesIds);
	}

	protected function getMailClientHelper($throwExceptions = true)
	{
		return Mailbox::createInstance($this->mailboxId, $throwExceptions);
	}

	protected function initData($folderType = null)
	{
		if ($this->isInit)
		{
			return new Main\Result();
		}
		$this->isInit = true;
		$result = new Main\Result();

		$this->mailbox = $this->repository->getMailbox($this->mailboxUserId);
		if (!$this->mailbox)
		{
			return $result->addError(new Main\Error(Loc::getMessage('MAIL_CLIENT_MAILBOX_NOT_FOUND'),
				'MAIL_CLIENT_MAILBOX_NOT_FOUND'));
		}

		if ($folderType)
		{
			$folder = $this->getDirPathByType($folderType);
			if (!$folder)
			{
				$errorCode = 'MAIL_CLIENT_' . ($folderType == MailboxDirectoryTable::TYPE_TRASH ? 'TRASH' : 'SPAM') . '_FOLDER_NOT_SELECTED_ERROR';
				return $result->addError(new Main\Error(
					Loc::getMessage($errorCode),
					$errorCode));
			}
		}
		if (is_null($this->messages))
		{
			$this->messages = $this->repository->getMessages();
		}

		if (empty($this->messages))
		{
			return $result->addError(new Main\Error(Loc::getMessage('MAIL_CLIENT_MESSAGES_NOT_FOUND'),
				'MAIL_CLIENT_MESSAGES_NOT_FOUND'));
		}
		$this->fillMessagesEmails();

		$folders = [];
		foreach ($this->messages as $index => $message)
		{
			if (in_array($message['ID'], $this->messagesIds, true))
			{
				$folders[$message['DIR_MD5']] = $message['DIR_MD5'];
			}
		}
		if (count($folders) > 1)
		{
			return $result->addError(new Main\Error(Loc::getMessage('MAIL_CLIENT_MESSAGES_MULTIPLE_FOLDERS'),
				'MAIL_CLIENT_MESSAGES_MULTIPLE_FOLDERS'));
		}
		return $result;
	}

	/**
	 * Must run before a destructive operation: permanent deletion drops the uid rows,
	 * and after that the messages can no longer be resolved.
	 */
	protected function collectDeferredPushTargets(): void
	{
		if ($this->deferredPushTargets === null)
		{
			$this->deferredPushTargets = $this->getRecentlyDeliveredMessageIds((int)$this->mailboxId);
		}
	}

	/**
	 * Once the user has dealt with a message in web, a push about it is a duplicate.
	 * Drops it while it is still deferred; runs at most once per manager.
	 */
	protected function cancelDeferredPush(): void
	{
		if ($this->deferredPushCancelled)
		{
			return;
		}
		$this->collectDeferredPushTargets();
		$this->deferredPushCancelled = true;

		if (empty($this->deferredPushTargets))
		{
			return;
		}

		Mail\Integration\Im\Notification::cancelDeferredPushForReadMessages(
			(int)$this->mailboxId,
			$this->deferredPushTargets,
			$this->getActingUserId(),
		);
	}

	/**
	 * @return int[]
	 */
	protected function getRecentlyDeliveredMessageIds(int $mailboxId): array
	{
		if (empty($this->messagesIds))
		{
			return [];
		}

		$deliveredAfter = (new Main\Type\DateTime())->add(
			'- ' . Mail\Integration\Im\Notification::deferredPushCancelWindowSeconds . ' seconds'
		);

		$messageIds = [];
		$res = Mail\MailMessageUidTable::getList([
			'select' => ['MESSAGE_ID'],
			'filter' => [
				'=MAILBOX_ID' => $mailboxId,
				'@ID' => $this->messagesIds,
				'>=DATE_INSERT' => $deliveredAfter,
			],
		]);
		while ($row = $res->fetch())
		{
			$messageId = (int)$row['MESSAGE_ID'];
			if ($messageId > 0)
			{
				$messageIds[$messageId] = $messageId;
			}
		}

		return array_values($messageIds);
	}

	protected function getActingUserId(): int
	{
		// delete/spam/move from MCP pass the acting user through setMailboxUserId(), not the constructor
		return (int)($this->userId ?: $this->mailboxUserId ?: Main\Engine\CurrentUser::get()->getId());
	}

	protected function getDirPathByType($dirType)
	{
		return $this->mailboxHelper->getDirsHelper()->getDirPathByType($dirType);
	}

	protected function getDirByPath($path)
	{
		return $this->mailboxHelper->getDirsHelper()->getDirByPath($path);
	}

	protected function fillMessagesEmails()
	{
		foreach ($this->messages as $index => $message)
		{
			$address = new Main\Mail\Address($message['FIELD_FROM']);
			$this->messages[$index]['EMAIL'] = $address->getEmail();
		}
	}
}