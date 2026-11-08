<?php

namespace Bitrix\Im\V2\Pull\Event;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Pull\BaseEvent;
use Bitrix\Im\V2\Pull\EventType;

/**
 * Asks an active client of the recipient to open the given dialog.
 *
 * Transport only: neither permissions nor membership are checked here, the caller
 * must guarantee that the recipient is a member of $dialogId. Access to the chat
 * itself is verified by im when the dialog is opened.
 *
 * @see \Bitrix\Im\dev\docs\pull\applicationOpenChat
 */
class ApplicationOpenChat extends BaseEvent
{
	private int $userId;
	private string $dialogId;

	public function __construct(int $userId, string $dialogId)
	{
		parent::__construct();
		$this->userId = $userId;
		$this->dialogId = $dialogId;
		$this->expiry = 5;
	}

	protected function getRecipients(): array
	{
		// A malformed dialog id would only make the client fail to open it, so such an event is not sent at all
		if ($this->userId <= 0 || !$this->isStrictDialogId())
		{
			return [];
		}

		return [$this->userId];
	}

	/**
	 * Accepts the same dialog ids as Common::isDialogId(), but anchored to the very end of the string:
	 * the pattern in Common has no D modifier, so it also accepts a trailing newline. The explicit '0'
	 * check keeps the rest of that parity - Common drops a falsy id before matching the pattern.
	 */
	private function isStrictDialogId(): bool
	{
		return
			$this->dialogId !== '0'
			&& (bool)preg_match('/^([0-9]+|(chat|sg|crm)[0-9]+)$/iD', $this->dialogId);
	}

	public function shouldSendToOnlySpecificRecipients(): bool
	{
		return true;
	}

	protected function getBasePullParamsInternal(): array
	{
		return ['dialogId' => $this->dialogId];
	}

	protected function getType(): EventType
	{
		return EventType::ApplicationOpenChat;
	}

	public function getTarget(): ?Chat
	{
		return null;
	}
}
