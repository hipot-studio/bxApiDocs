<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Internal\Event;

final class RecurrenceWeekStart
{
	public static function readParameter(array &$rule, array $parameter): void
	{
		if (
			($parameter[0] ?? null) === 'WKST'
			&& in_array($parameter[1] ?? null, ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'], true)
		)
		{
			$rule['WKST'] = $parameter[1];
		}
	}

	public static function normalize(array &$rule): void
	{
		if (($rule['FREQ'] ?? null) !== 'WEEKLY' && isset($rule['WKST']))
		{
			unset($rule['WKST']);
		}
	}
}
