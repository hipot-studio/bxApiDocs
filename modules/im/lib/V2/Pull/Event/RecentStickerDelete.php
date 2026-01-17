<?php

namespace Bitrix\Im\V2\Pull\Event;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Common\ContextCustomer;
use Bitrix\Im\V2\Pull\BaseEvent;
use Bitrix\Im\V2\Pull\EventType;

class RecentStickerDelete extends BaseEvent
{
	use ContextCustomer;

	protected int $stickerId;
	protected int $packId;
	protected string $packType;

	public function __construct(int $stickerId, int $packId, string $packType)
	{
		parent::__construct();

		$this->stickerId = $stickerId;
		$this->packId = $packId;
		$this->packType = $packType;
	}

	protected function getType(): EventType
	{
		return EventType::RecentStickerDelete;
	}

	protected function getBasePullParamsInternal(): array
	{
		return [
			'stickerId' => $this->stickerId,
			'packId' => $this->packId,
			'packType' => $this->packType,
		];
	}

	protected function getRecipients(): array
	{
		return [$this->getContext()->getUserId()];
	}

	public function getTarget(): ?Chat
	{
		return null;
	}
}
