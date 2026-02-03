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
	protected bool $sendImmediately;

	public function __construct(Message $message, Status $status, bool $sendImmediately = false)
	{
		parent::__construct();

		$this->message = $message;
		$this->status = $status;
		$this->sendImmediately = $sendImmediately;
	}

	protected function getBasePullParamsInternal(): array
	{
		return [
			'status' => $this->status->value,
			'messageId' => $this->message->getId(),
			'chatId' => $this->message->getChatId(),
			'type' => $this->getMessageType(),
		];
	}

	protected function getMessageType(): string
	{
		if ($this->message->isVoiceNote())
		{
			return 'voiceNote';
		}

		return 'videoNote';
	}

	protected function getType(): EventType
	{
		return EventType::AutoTaskStatus;
	}

	protected function getRecipients(): array
	{
		return [$this->message->getAuthorId()];
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
