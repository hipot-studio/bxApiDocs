<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Sticker;

use Bitrix\Im\V2\Message\Sticker\VendorPack\VendorPacks;
use Bitrix\Main\Engine\Response\Converter;
use Bitrix\Main\Localization\Loc;

class StickerService
{
	public function getList(int $limit = 10, ?int $lastPackId = null, ?string $lastPackType = null): array
	{
		return (VendorPacks::getInstance())->getList($limit, $lastPackId, $lastPackType);
	}

	public function getPackById(int $packId, string $packType): ?array
	{
		if ($packType === PackType::Vendor->value)
		{
			return (VendorPacks::getInstance())->getPackById($packId)?->toRestFormat();
		}

		return null;
	}

	public function getStickerById(int $stickerId, int $packId, string $packType): ?array
	{
		if ($packType === PackType::Vendor->value)
		{
			return (VendorPacks::getInstance())->getStickerById($stickerId, $packId);
		}

		return null;
	}

	public function getStickerByRecent(array $recentItems): array
	{
		return VendorPacks::getInstance()->getStickersByRecent($recentItems);
	}

	public static function getPlaceholder(): string
	{
		return Loc::getMessage('IM_MESSAGE_STICKER_PLACEHOLDER') ?? '';
	}

	public static function getStickerMessageComponentId(): string
	{
		return 'StickerMessage';
	}

	public static function getStickerMessageParams(int $stickerId, int $packId, string $packType): array
	{
		if ($packType === PackType::Vendor->value)
		{
			$sticker =  VendorPacks::getInstance()->getStickerById($stickerId, $packId);
			if (!isset($sticker))
			{
				return [];
			}

			$converter = new Converter(Converter::TO_SNAKE | Converter::TO_UPPER | Converter::KEYS);

			return $converter->process($sticker);
		}

		return [];
	}
}
