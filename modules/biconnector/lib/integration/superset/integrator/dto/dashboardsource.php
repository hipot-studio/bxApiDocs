<?php

namespace Bitrix\BIConnector\Integration\Superset\Integrator\Dto;

final class DashboardSource
{
	private const MAX_CHARTS = 1000;
	private const MAX_CHART_SOURCE_BYTES = 1048576;

	/** @param list<int>|null $chartIds */
	public function __construct(
		public ?string $positionJson = null,
		public ?string $jsonMetadataSource = null,
		public bool $published = true,
		public ?array $chartIds = null,
	)
	{}

	public static function isValidChartIds(mixed $chartIds): bool
	{
		if (!is_array($chartIds) || !array_is_list($chartIds) || count($chartIds) > self::MAX_CHARTS)
		{
			return false;
		}

		$seen = [];
		foreach ($chartIds as $chartId)
		{
			if (!is_int($chartId) || $chartId <= 0 || isset($seen[$chartId]))
			{
				return false;
			}

			$seen[$chartId] = true;
		}

		$encoded = json_encode($chartIds);

		return $encoded !== false && strlen($encoded) <= self::MAX_CHART_SOURCE_BYTES;
	}
}
