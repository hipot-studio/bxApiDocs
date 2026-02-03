<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Sticker;

use Bitrix\Im\V2\Message\Sticker\Recent\RecentCollection;
use Bitrix\Im\V2\Result;

interface StickerPacks
{
	public function getList(int $limit, ?int $lastId = null, ?PackType $lastType = null): PackCollection;

	public function getStickerById(int $stickerId, int $packId): ?StickerItem;

	public function getPackById(int $packId): ?PackItem;

	public function isPackAdded(int $packId): bool;

	public function getStickersByRecent(RecentCollection $recentCollection): StickerCollection;

	public function addPack(array $fileUuidMap, ?string $packName): Result;

	public function linkPack(int $packId): Result;

	public function addStickers(array $fileUuidMap, int $packId, bool $sendPush = true): Result;

	public function deletePack(int $packId): Result;

	public function unlinkPack(int $packId): Result;

	public function renamePack(int $packId, string $name): Result;

	public function deleteStickers(array $stickerIds, int $packId): Result;

	public function getType(): PackType;
}
