<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Promotion\Event\Read;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Pull\BaseEvent;
use Bitrix\Im\V2\Pull\EventType;

class PromotionReadEvent extends BaseEvent
{
	public function __construct(
		private readonly int $userId,
		private readonly string $promotionId,
	)
	{
		parent::__construct();
	}

	protected function getRecipients(): array
	{
		return [$this->userId];
	}

	public function shouldSendToOnlySpecificRecipients(): bool
	{
		return true;
	}

	protected function getBasePullParamsInternal(): array
	{
		return ['id' => $this->promotionId];
	}

	protected function getType(): EventType
	{
		return EventType::PromotionRead;
	}

	public function getTarget(): ?Chat
	{
		return null;
	}
}
