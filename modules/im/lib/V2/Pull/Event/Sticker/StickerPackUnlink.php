<?php

namespace Bitrix\Im\V2\Pull\Event\Sticker;

use Bitrix\Im\V2\Message\Sticker\PackType;
use Bitrix\Im\V2\Pull\EventType;

class StickerPackUnlink extends BaseStickerEvent
{
	protected int $packId;
	protected PackType $packType;

	public function __construct(int $packId, PackType $packType)
	{
		parent::__construct();
		$this->packId = $packId;
		$this->packType = $packType;
	}

	protected function getRecipients(): array
	{
		return [$this->getContext()->getUserId()];
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
		return EventType::StickerPackUnlink;
	}
}
