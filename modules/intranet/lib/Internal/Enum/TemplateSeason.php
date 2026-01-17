<?php

namespace Bitrix\Intranet\Internal\Enum;

use Bitrix\Main\Type\Date;

enum TemplateSeason: string
{
	case WINTER = 'winter';
	case SPRING = 'spring';
	case SUMMER = 'summer';
	case AUTUMN = 'autumn';

	public static function fromCurrentDate(): self
	{
		$monthNumber = (int)(new Date())->format('n');

		return match ($monthNumber) {
			12, 1, 2 => self::WINTER,
			3, 4, 5 => self::SPRING,
			6, 7, 8 => self::SUMMER,
			9, 10, 11 => self::AUTUMN,
		};
	}

	private static function hasRegionalDivision(TemplateSeason $season): bool
	{
		$separateSeasonZones = [
			self::WINTER->value => false,
			self::SPRING->value => false,
			self::SUMMER->value => false,
			self::AUTUMN->value => true,
		];

		return $separateSeasonZones[$season->value];
	}

	private static function getRegionCode(): string
	{
		return \Bitrix\Main\Application::getInstance()->getLicense()->isCis()
			? 'cis'
			: 'eu';
	}

	public static function getSeasonZone(TemplateSeason $season): ?string
	{
		if (self::hasRegionalDivision($season))
		{
			return self::getRegionCode();
		}

		return null;
	}
}
