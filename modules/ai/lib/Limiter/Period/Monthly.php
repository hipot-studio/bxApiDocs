<?php

namespace Bitrix\AI\Limiter\Period;

use Bitrix\AI\Context;
use Bitrix\AI\Limiter\Plan;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Type\DateTime;

class Monthly implements IPeriod
{
	private const PERIOD_FORMAT = 'Y-m';

	/**
	 * @inheritDoc
	 */
	public function __construct(
		private Context $context,
		private ?string $time = null,
	) {}

	/**
	 * @inheritDoc
	 */
	public function getCode(): string
	{
		return (new DateTime($this->time))->format(self::PERIOD_FORMAT);
	}

	/**
	 * @inheritDoc
	 */
	public function getCurrentUsage(): int
	{
		$testUsageCount = $this->getTestUsageCount();
		if ($testUsageCount >= 0)
		{
			return $testUsageCount;
		}

		return (new \Bitrix\AI\Limiter\SharedMonthlyPoolService())->readTotal($this->getCode());
	}

	protected function getTestUsageCount(): int
	{
		return (int)Option::get('ai', 'test_monthly_usage', -1);
	}

	/**
	 * @inheritDoc
	 */
	public function getMaximumUsage(): int
	{
		return $this->getPlanMaximumUsage() ?? 0;
	}

	protected function getPlanMaximumUsage(): ?int
	{
		return Plan::createByB24()?->getMaxUsage();
	}
}
