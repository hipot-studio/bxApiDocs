<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Sticker;

use Bitrix\Im\V2\Message\Sticker\VendorPack\VendorPacks;

class PackFactory
{
	protected static self $instance;

	public static function getInstance(): self
	{
		self::$instance ??= new self();

		return self::$instance;
	}

	public function getByType(PackType $packType): StickerPacks
	{
		return match ($packType)
		{
			PackType::Vendor => VendorPacks::getInstance(),
			PackType::Custom => CustomPacks::getInstance(),
		};
	}
}
