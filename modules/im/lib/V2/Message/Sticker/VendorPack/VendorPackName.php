<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Sticker\VendorPack;

enum VendorPackName: string
{
	case BitrixVibe = 'bitrixVibe';
	case Zefir = 'zefir';
	case ArkashaAndCat = 'arkashaAndCat';
	case BitrixReactions = 'bitrixReactions';
	case Airy = 'airy';
	case BittyBob = 'bittyBob';

	public function getId(): int
	{
		return match ($this)
		{
			self::BitrixVibe => 1,
			self::Zefir => 2,
			self::ArkashaAndCat => 3,
			self::BitrixReactions => 4,
			self::Airy => 5,
			self::BittyBob => 6,
		};
	}

	public static function getById(int $id): ?self
	{
		return match ($id)
		{
			1 => self::BitrixVibe,
			2 => self::Zefir,
			3 => self::ArkashaAndCat,
			4 => self::BitrixReactions,
			5 => self::Airy,
			6 => self::BittyBob,
			default => null,
		};
	}
}
