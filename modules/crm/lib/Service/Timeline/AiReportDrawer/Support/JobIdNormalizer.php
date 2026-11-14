<?php

namespace Bitrix\Crm\Service\Timeline\AiReportDrawer\Support;

final class JobIdNormalizer
{
	private const MAX_JOB_ID = 9007199254740991;

	public static function normalize(mixed $value): ?int
	{
		if (is_int($value))
		{
			return $value > 0 && $value <= self::MAX_JOB_ID ? $value : null;
		}

		if (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1)
		{
			return null;
		}

		$value = ltrim($value, '0');
		$maximum = (string)self::MAX_JOB_ID;
		if (
			$value === ''
			|| strlen($value) > strlen($maximum)
			|| (strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0)
		)
		{
			return null;
		}

		return (int)$value;
	}
}
