<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Sticker;

use Bitrix\Im\V2\Message\Sticker\Recent\RecentCollection;
use Bitrix\Im\V2\Result;
use Bitrix\Main\Engine\Response\Converter;
use Bitrix\Main\Localization\Loc;

class StickerService
{
	public function getList(int $limit = 10, ?int $lastPackId = null, ?PackType $packType = null): PackCollection
	{
		$packs = PackFactory::getInstance()
			->getByType(PackType::Custom)
			->getList($limit, $lastPackId, $packType)
		;

		if ($packs->count() < $limit)
		{
			$limit = $limit - $packs->count();

			$vendorPacks = PackFactory::getInstance()
				->getByType(PackType::Vendor)
				->getList($limit, $lastPackId, $packType)
			;

			$packs = $packs
				->setHasNextPage($vendorPacks->hasNextPage())
				->mergeRegistry($vendorPacks)
			;
		}

		return $packs;
	}

	public function getPackById(int $packId, PackType $packType): ?PackItem
	{
		return PackFactory::getInstance()->getByType($packType)->getPackById($packId);
	}

	public function getStickerById(int $stickerId, int $packId, PackType $packType): ?StickerItem
	{
		return PackFactory::getInstance()->getByType($packType)->getStickerById($stickerId, $packId);
	}

	public function addPack(array $fileUuidMap, PackType $packType, ?string $packName): Result
	{
		return PackFactory::getInstance()->getByType($packType)->addPack($fileUuidMap, $packName);
	}

	public function linkPack(int $packId, PackType $packType): Result
	{
		return PackFactory::getInstance()->getByType($packType)->linkPack($packId);
	}

	public function addStickers(array $fileUuidMap, int $packId, PackType $packType): Result
	{
		return PackFactory::getInstance()->getByType($packType)->addStickers($fileUuidMap, $packId);
	}

	public function deletePack(int $packId, PackType $packType): Result
	{
		return PackFactory::getInstance()->getByType($packType)->deletePack($packId);
	}

	public function unlinkPack(int $packId, PackType $packType): Result
	{
		return PackFactory::getInstance()->getByType($packType)->unlinkPack($packId);
	}

	public function renamePack(int $packId, PackType $packType, string $name): Result
	{
		return PackFactory::getInstance()->getByType($packType)->renamePack($packId, $name);
	}

	public function deleteStickers(array $stickerIds, int $packId, PackType $packType): Result
	{
		return PackFactory::getInstance()->getByType($packType)->deleteStickers($stickerIds, $packId);
	}

	public function getStickersByRecent(RecentCollection $recentCollection): StickerCollection
	{
		$vendorStickers = PackFactory::getInstance()
			->getByType(PackType::Vendor)
			->getStickersByRecent($recentCollection)
		;

		$customStickers = PackFactory::getInstance()
			->getByType(PackType::Custom)
			->getStickersByRecent($recentCollection)
		;

		return $vendorStickers->mergeRegistry($customStickers);
	}

	public static function getPlaceholder(): string
	{
		return Loc::getMessage('IM_MESSAGE_STICKER_PLACEHOLDER') ?? '';
	}

	public static function getStickerMessageParams(int $stickerId, int $packId, PackType $packType): array
	{
		$sticker = PackFactory::getInstance()->getByType($packType)->getStickerById($stickerId, $packId);
		if (!isset($sticker))
		{
			return [];
		}

		$converter = new Converter(Converter::TO_SNAKE | Converter::TO_UPPER | Converter::KEYS);

		return $converter->process($sticker->toShortRestFormat());
	}
}
