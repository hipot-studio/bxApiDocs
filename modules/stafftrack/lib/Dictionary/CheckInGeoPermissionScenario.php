<?php

namespace Bitrix\StaffTrack\Dictionary;

enum CheckInGeoPermissionScenario: string
{
	case AUTO_CHECK_IN = 'auto';
	case DAY_START_CHECK_IN = 'day_start';

	private const SEPARATOR = ',';

	/**
	 * Both sides walk the cases in their declaration order, so the same set of scenarios always gives
	 * the same stored value whatever order it was collected in.
	 *
	 * @param self[] $scenarios
	 * @return string
	 */
	public static function serialize(array $scenarios): string
	{
		$values = [];
		foreach (self::cases() as $case)
		{
			if (in_array($case, $scenarios, true))
			{
				$values[] = $case->value;
			}
		}

		return implode(self::SEPARATOR, $values);
	}

	/**
	 * @param string $value
	 * @return self[]
	 */
	public static function deserialize(string $value): array
	{
		$storedValues = explode(self::SEPARATOR, $value);

		$scenarios = [];
		foreach (self::cases() as $case)
		{
			if (in_array($case->value, $storedValues, true))
			{
				$scenarios[] = $case;
			}
		}

		return $scenarios;
	}
}
