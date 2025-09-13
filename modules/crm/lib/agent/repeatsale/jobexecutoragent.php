<?php

namespace Bitrix\Crm\Agent\RepeatSale;

use Bitrix\Crm\Agent\AgentBase;
use Bitrix\Crm\RepeatSale\Queue\Executor;
use Bitrix\Crm\Service\Container;

class JobExecutorAgent extends AgentBase
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
				$instance->setExecutionPeriod(60);
				Executor::getInstance()->execute();
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
