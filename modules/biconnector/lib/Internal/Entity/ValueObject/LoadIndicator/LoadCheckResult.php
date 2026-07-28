<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Entity\ValueObject\LoadIndicator;

/**
 * Accumulates triggered load factors for one usage-log row.
 * Mutable during the check pass; treated as a value object once handed off
 * to {@see LoadIndicator::createFromCheckResult()}.
 */
final class LoadCheckResult
{
	/** @var TriggeredFactorInfo[] */
	private array $factors = [];

	public function addFactor(TriggeredFactorInfo $factor): void
	{
		$this->factors[] = $factor;
	}

	/**
	 * @return TriggeredFactorInfo[]
	 */
	public function getFactors(): array
	{
		return $this->factors;
	}
}
