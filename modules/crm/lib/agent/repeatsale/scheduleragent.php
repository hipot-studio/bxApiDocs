<?php

namespace Bitrix\Crm\Agent\RepeatSale;

use Bitrix\Crm\Agent\AgentBase;
use Bitrix\Crm\RepeatSale\Schedule\Scheduler;
use Bitrix\Crm\Service\Container;

class SchedulerAgent extends AgentBase
{
	public const PERIODICAL_AGENT_RUN_LATER = true;

	public static function doRun(): bool
	{
		$availabilityChecker = Container::getInstance()->getRepeatSaleAvailabilityChecker();
		$instance = new self();

		if ($availabilityChecker->isItemsCountsLessThenLimit())
		{
			if ($availabilityChecker->isAllowedTime() || $availabilityChecker->isSegmentsInitializationProgress())
			{
				$instance->setExecutionPeriod(3600 * 4);
				Scheduler::getInstance()->execute();
			}
			else
			{
				$instance->setExecutionPeriod(3600);
			}
		}
		else
		{
			$instance->setExecutionPeriod(86400);
		}

		return self::PERIODICAL_AGENT_RUN_LATER;
	}
}
