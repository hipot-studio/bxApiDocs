<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Analytics;

use Bitrix\Im\V2\Analytics\Event\StickerEvent;
use Bitrix\Im\V2\Message\Sticker\PackType;

class StickerAnalytics extends AbstractAnalytics
{
	protected const ADD_PACK = 'create_stickerpack';
	protected const ADD_STICKER = 'add_sticker';
	protected const DELETE_PACK = 'delete_stickerpack_for_all';
	protected const UNLINK_PACK = 'delete_stickerpack';

	public function addAddPack(PackType $type): void
	{
		$this->async(function () use ($type) {
			$this
				->createStickerEvent(self::ADD_PACK)
				?->setType($type->value)
				?->send()
			;
		});
	}

	public function addAddSticker(PackType $type, int $stickerCount): void
	{
		$this->async(function () use ($type, $stickerCount) {
			$this
				->createStickerEvent(self::ADD_STICKER)
				?->setType($type->value)
				?->setP2('stickerCount_' . $stickerCount)
				?->send()
			;
		});
	}

	public function addDeletePack(PackType $type): void
	{
		$this->async(function () use ($type) {
			$this
				->createStickerEvent(self::DELETE_PACK)
				?->setType($type->value)
				?->send()
			;
		});
	}

	public function addUnlinkPack(PackType $type): void
	{
		$this->async(function () use ($type) {
			$this
				->createStickerEvent(self::UNLINK_PACK)
				?->setType($type->value)
				?->send()
			;
		});
	}

	protected function createStickerEvent(
		string $eventName,
	): ?StickerEvent
	{
		return (new StickerEvent($eventName, $this->chat, $this->getContext()->getUserId()));
	}
}
