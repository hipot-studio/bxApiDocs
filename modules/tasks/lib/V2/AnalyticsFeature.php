<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2;

use Bitrix\Main\Config\Option;

class AnalyticsFeature
{
	private const P5_FORMAT_LEGACY = 'legacy';
	private const P5_FORMAT_SNAPSHOT = 'snapshot';

	public static function getP5Format(): string
	{
		$format = Option::get('tasks', 'tasks_analytics_p5_format', self::P5_FORMAT_LEGACY);

		return $format === self::P5_FORMAT_SNAPSHOT ? self::P5_FORMAT_SNAPSHOT : self::P5_FORMAT_LEGACY;
	}
}
