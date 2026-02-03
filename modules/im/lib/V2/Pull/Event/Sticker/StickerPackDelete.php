<?php

namespace Bitrix\Im\V2\Pull\Event\Sticker;

use Bitrix\Im\V2\Message\Sticker\PackItem;
use Bitrix\Im\V2\Message\Sticker\PackType;
use Bitrix\Im\V2\Pull\EventType;

class StickerPackDelete extends BaseStickerEvent
{
	protected int $packId;
	protected PackType $packType;
	protected array $userIds;

	public function __construct(int $packId, PackType $packType, array $userIds)
	{
		parent::__construct();
		$this->packId = $packId;
		$this->packType = $packType;
		$this->userIds = $userIds;
	}

	protected function getRecipients(): array
	{
		return $this->userIds;
	}

	protected function getBasePullParamsInternal(): array
	{
		return [
			'id' => $this->packId,
			'type' => $this->packType->value,
		];
	}

	protected function getType(): EventType
	{
		return EventType::StickerPackDelete;
	}
}
