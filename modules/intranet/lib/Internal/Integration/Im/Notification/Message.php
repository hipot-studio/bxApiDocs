<?php

declare(strict_types = 1);

namespace Bitrix\Intranet\Internal\Integration\Im\Notification;

use Closure;

class Message
{
	private int $toUserId;
	private int $fromUserId;
	private string $notifyTag;
	private int $notifyType;
	private string $notifyModule;
	private string $notifyEvent;
	private Closure|string $notifyMessage;
	private array $params = [];

	public function __construct(
		int $toUserId,
		int $fromUserId,
		string $notifyTag,
		int $notifyType,
		string $notifyModule,
		string $notifyEvent,
		Closure|string $notifyMessage,
	) {
		$this->toUserId = $toUserId;
		$this->fromUserId = $fromUserId;
		$this->notifyTag = $notifyTag;
		$this->notifyType = $notifyType;
		$this->notifyModule = $notifyModule;
		$this->notifyEvent = $notifyEvent;
		$this->notifyMessage = $notifyMessage;
	}

	public function setParams(array $params): self
	{
		$this->params = $params;
		
		return $this;
	}

	public function setComponentParams(string $componentId, array $componentParams): self
	{
		$this->params = [
			'COMPONENT_ID' => $componentId,
			'COMPONENT_PARAMS' => $componentParams,
		];

		return $this;
	}

	public function toArray(): array
	{
		return [
			'TO_USER_ID' => $this->toUserId,
			'FROM_USER_ID' => $this->fromUserId,
			'NOTIFY_TAG' => $this->notifyTag,
			'NOTIFY_TYPE' => $this->notifyType,
			'NOTIFY_MODULE' => $this->notifyModule,
			'NOTIFY_EVENT' => $this->notifyEvent,
			'NOTIFY_MESSAGE' => $this->notifyMessage,
			'PARAMS' => $this->params,
		];
	}
}
