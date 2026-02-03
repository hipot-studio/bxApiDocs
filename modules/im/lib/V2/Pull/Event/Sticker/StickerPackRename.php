<?php

namespace Bitrix\Im\V2\Pull\Event\Sticker;

use Bitrix\Im\V2\Message\Sticker\CustomPacks\UserPack;
use Bitrix\Im\V2\Message\Sticker\PackType;
use Bitrix\Im\V2\Pull\EventType;

class StickerPackRename extends BaseStickerEvent
{
	protected int $packId;
	protected PackType $packType;
	protected string $name;

	public function __construct($packId, PackType $packType, string $name)
	{
		parent::__construct();
		$this->packId = $packId;
		$this->packType = $packType;
		$this->name = $name;
	}

	protected function getRecipients(): array
	{
		return UserPack::getInstance()->getUsersWithPack($this->packId);
	}

	protected function getBasePullParamsInternal(): array
	{
		return [
			'id' => $this->packId,
			'type' => $this->packType,
			'name' => $this->name,
		];
	}

	protected function getType(): EventType
	{
		return EventType::StickerPackRename;
	}
}
