<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Entity\ValueObject\LoadIndicator;

use Bitrix\BIConnector\Internal\Services\LoadIndicator\Thresholds;

/**
 * Severity verdict produced from a {@see LoadCheckResult}: the categorical level
 * and the list of triggered factors that explain it.
 */
final class LoadIndicator
{
	/**
	 * @param TriggeredFactorInfo[] $factors
	 */
	public function __construct(
		private readonly LoadLevel $level,
		private readonly array $factors = [],
	)
	{
	}

	/**
	 * @return LoadLevel
	 */
	public function getLevel(): LoadLevel
	{
		return $this->level;
	}

	/**
	 * @return TriggeredFactorInfo[]
	 */
	public function getFactors(): array
	{
		return $this->factors;
	}

	/**
	 * Decides the load level for a given check result.
	 *
	 *  - No triggered factors → Low.
	 *  - Duration above {@see Thresholds::VERY_SLOW_SECONDS} → High.
	 *  - Otherwise → Medium.
	 */
	public static function createFromCheckResult(LoadCheckResult $result): self
	{
		$factors = $result->getFactors();
		if ($factors === [])
		{
			return new self(LoadLevel::Low, []);
		}

		$duration = 0;
		foreach ($factors as $info)
		{
			if ($info->factor === TriggeredFactor::Duration)
			{
				$duration = $info->duration;
			}
		}

		$level = match (true)
		{
			$duration > Thresholds::VERY_SLOW_SECONDS => LoadLevel::High,
			default => LoadLevel::Medium,
		};

		return new self($level, $factors);
	}
}
