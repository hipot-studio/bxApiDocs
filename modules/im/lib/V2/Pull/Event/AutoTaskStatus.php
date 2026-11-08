<?php

namespace Bitrix\Im\V2\Pull\Event;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Integration\AI\TaskCreation\Status;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Pull\BaseEvent;
use Bitrix\Im\V2\Pull\EventType;

class AutoTaskStatus extends BaseEvent
{
	protected Status $status;
	protected Message $message;
	protected int $initiatorId;
	protected bool $sendImmediately;
	protected bool $isPartial;

	/**
	 * @param bool $isPartial the task was built without part of the selected sources; meaningful
	 *        for the terminal success only, so any other status drops it from the payload
	 */
	public function __construct(
		Message $message,
		int $initiatorId,
		Status $status,
		bool $sendImmediately = false,
		bool $isPartial = false
	)
	{
		parent::__construct();

		$this->message = $message;
		$this->initiatorId = $initiatorId;
		$this->status = $status;
		$this->sendImmediately = $sendImmediately;
		$this->isPartial = $isPartial;
	}

	protected function getBasePullParamsInternal(): array
	{
		$params = [
			'status' => $this->status->value,
			'messageId' => $this->message->getId(),
			'chatId' => $this->message->getChatId(),
			'type' => $this->getMessageType(),
		];

		// the field is a property of the created task, so it exists only where a task exists:
		// clients read it under this status only and treat its absence as a plain success
		if ($this->status === Status::TaskCreationCompleted)
		{
			$params['isPartial'] = $this->isPartial;
		}

		return $params;
	}

	protected function getMessageType(): string
	{
		if ($this->message->isVoiceNote())
		{
			return 'voiceNote';
		}

		if ($this->message->isVideoNote())
		{
			return 'videoNote';
		}

		return 'message';
	}

	protected function getType(): EventType
	{
		return EventType::AutoTaskStatus;
	}

	protected function getRecipients(): array
	{
		return [$this->initiatorId];
	}

	public function shouldSendToOnlySpecificRecipients(): bool
	{
		return false;
	}

	public function getTarget(): ?Chat
	{
		return null;
	}

	public function shouldSendImmediately(): bool
	{
		return $this->sendImmediately;
	}
}
