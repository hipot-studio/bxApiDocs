<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Service\AnnualSummary;

use Bitrix\Intranet\Internal\Entity\AnnualSummary\MetricThreshold;

final class EmptyThresholds implements RegionThresholds
{
	public function getThreshold(string $code): ?MetricThreshold
	{
		return null;
	}

	public function getReservedHours(): ?MetricThreshold
	{
		return null;
	}

	public function isSupported(): bool
	{
		return false;
	}
}
