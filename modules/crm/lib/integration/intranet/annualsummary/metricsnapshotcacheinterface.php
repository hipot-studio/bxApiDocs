<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\Intranet\AnnualSummary;

use Bitrix\Main\Type\Date;

interface MetricSnapshotCacheInterface
{
	/**
	 * @param callable(): array<string, int> $calculate
	 * @return array<string, int>
	 */
	public function getOrCalculate(Date $from, Date $to, callable $calculate): array;

	public function clear(Date $from, Date $to): void;
}
