<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Service\AnnualSummary;

final class RegionThresholdsFactory
{
	public static function create(string $region): RegionThresholds
	{
		return match (strtolower($region))
		{
			'ru' => new RuThresholds(),
			'by' => new ByThresholds(),
			default => new EmptyThresholds(),
		};
	}
}
