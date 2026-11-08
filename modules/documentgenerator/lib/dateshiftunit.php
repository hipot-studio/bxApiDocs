<?php

namespace Bitrix\DocumentGenerator;

/**
 * Defines units supported by the document date modifier.
 *
 * @internal
 */
enum DateShiftUnit: string
{
	case Year = 'year';
	case Month = 'month';
	case Day = 'day';
	case Hour = 'hour';
	case Minute = 'minute';
	case Second = 'second';

	public static function tryFromName(string $name): ?self
	{
		$name = strtolower($name);
		if (str_ends_with($name, 's'))
		{
			$name = substr($name, 0, -1);
		}

		return self::tryFrom($name);
	}

	public function isTimeOfDay(): bool
	{
		return match ($this)
		{
			self::Hour, self::Minute, self::Second => true,
			default => false,
		};
	}

	public function getDesignator(): string
	{
		return match ($this)
		{
			self::Year => 'Y',
			self::Month, self::Minute => 'M',
			self::Day => 'D',
			self::Hour => 'H',
			self::Second => 'S',
		};
	}
}
