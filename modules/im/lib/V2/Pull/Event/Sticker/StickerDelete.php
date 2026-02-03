<?php

namespace Bitrix\Im\V2\Pull\Event\Sticker;

use Bitrix\Im\V2\Message\Sticker\PackType;
use Bitrix\Im\V2\Pull\EventType;

class StickerDelete extends BaseStickerEvent
{
	protected int $packId;
	protected PackType $packType;
	protected array $userIds;
	protected array $stickerIds;

	public function __construct(
		int $packId,
		PackType $packType,
		array $userIds,
		array $stickerIds
	)
	{
		parent::__construct();
		$this->packId = $packId;
		$this->packType = $packType;
		$this->userIds = $userIds;
		$this->stickerIds = $stickerIds;
	}

	protected function getRecipients(): array
	{
		return $this->userIds;
	}

	protected function getBasePullParamsInternal(): array
	{
		return [
			'packId' => $this->packId,
			'packType' => $this->packType->value,
			'ids' => $this->stickerIds,
		];
	}

	protected function getType(): EventType
	{
		return EventType::StickerDelete;
	}
}
