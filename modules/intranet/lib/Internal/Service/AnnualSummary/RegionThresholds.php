<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Service\AnnualSummary;

use Bitrix\Intranet\Internal\Entity\AnnualSummary\MetricThreshold;

interface RegionThresholds
{
	public function getThreshold(string $code): ?MetricThreshold;

	public function getReservedHours(): ?MetricThreshold;

	public function isSupported(): bool;
}
