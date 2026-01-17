<?php

namespace Bitrix\Intranet\Internal\Service\AnnualSummary;

use Bitrix\Intranet\Internal\Entity\AnnualSummary\Feature;
use Bitrix\Main\Type\DateTime;

abstract class AbstractFeatureProvider implements ProviderInterface
{
	public function getFeatureSummary(int $userId, DateTime $from, DateTime $to): Feature
	{
		$precalc = $this->precalcValue($userId);
		if (is_int($precalc))
		{
			return $this->createFeature($precalc);
		}

		return $this->createFeature($this->calcValue($userId, $from, $to));
	}

	abstract public function precalcValue(int $userId): ?int;

	abstract public function calcValue(int $userId, DateTime $from, DateTime $to): int;

	abstract public function createFeature(int $value): Feature;

	abstract public function isAvailable(): bool;

	public function partCalc(int $userId, DateTime $from, DateTime $to, int $lastId): array
	{
		return [$lastId, 0];
	}

	public function needPartCalc(): bool
	{
		return false;
	}
}