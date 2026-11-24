<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Entity\AnnualSummary;

final readonly class MetricThreshold
{
	public function __construct(
		private int $min,
		private int $max,
	)
	{
	}

	public function getMin(): int
	{
		return $this->min;
	}

	public function getMax(): int
	{
		return $this->max;
	}
}
