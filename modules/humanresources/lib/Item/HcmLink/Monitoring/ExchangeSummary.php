<?php

namespace Bitrix\HumanResources\Item\HcmLink\Monitoring;

use Bitrix\HumanResources\Contract\Item;
use Bitrix\HumanResources\Type\HcmLink\Monitoring\ExchangeHealth;
use Bitrix\Main\Type\DateTime;

class ExchangeSummary implements Item
{
	/**
	 * @param list<CompanySummary> $companies
	 */
	public function __construct(
		public readonly array $companies,
		public readonly DateTime $collectedAt,
	)
	{
	}

	/**
	 * The portal is called broken only when every company that had jobs at all is broken: the worst
	 * of the verdicts would let one abandoned test company paint the portal red forever.
	 */
	public function portalHealth(): ?ExchangeHealth
	{
		$hasCompanyWithJobs = false;

		foreach ($this->companies as $company)
		{
			$health = $company->health();
			if ($health === ExchangeHealth::NO_DATA)
			{
				continue;
			}

			if ($health !== ExchangeHealth::BROKEN)
			{
				return null;
			}

			$hasCompanyWithJobs = true;
		}

		return $hasCompanyWithJobs ? ExchangeHealth::BROKEN : null;
	}
}
