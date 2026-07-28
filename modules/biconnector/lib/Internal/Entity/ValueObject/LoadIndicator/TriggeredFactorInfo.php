<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Entity\ValueObject\LoadIndicator;

/**
 * One triggered risk factor with its inline payload for recommendation text placeholders.
 * Payload fields are populated selectively — each factor has its own set
 * (see the named constructors below).
 */
final class TriggeredFactorInfo
{
	public function __construct(
		public readonly TriggeredFactor $factor,
		public readonly ?float $duration = null,
		public readonly ?int $selectedColumns = null,
		public readonly ?int $totalColumns = null,
		public readonly ?float $columnsRatio = null,
	)
	{
	}

	public static function duration(float $seconds): self
	{
		return new self(TriggeredFactor::Duration, duration: $seconds);
	}

	public static function periodWide(): self
	{
		return new self(TriggeredFactor::PeriodWide);
	}

	public static function manyColumns(int $selectedFieldsCount, int $totalFieldsCount): self
	{
		return new self(
			TriggeredFactor::ManyColumns,
			selectedColumns: $selectedFieldsCount,
			totalColumns: $totalFieldsCount,
			columnsRatio: $selectedFieldsCount / $totalFieldsCount,
		);
	}

	public static function noFilters(): self
	{
		return new self(TriggeredFactor::NoFilters);
	}

	public static function largeData(): self
	{
		return new self(TriggeredFactor::LargeData);
	}
}
