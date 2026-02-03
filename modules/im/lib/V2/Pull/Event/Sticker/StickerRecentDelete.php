<?php

namespace Bitrix\Im\V2\Pull\Event\Sticker;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Common\ContextCustomer;
use Bitrix\Im\V2\Pull\EventType;

class StickerRecentDelete extends BaseStickerEvent
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
		return EventType::StickerRecentDelete;
	}

	protected function getBasePullParamsInternal(): array
	{
		return [
			'id' => $this->stickerId,
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
