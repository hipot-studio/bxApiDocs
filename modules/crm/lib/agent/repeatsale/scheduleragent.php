<?php

namespace Bitrix\Crm\Agent\RepeatSale;

use Bitrix\Crm\Agent\AgentBase;
use Bitrix\Crm\RepeatSale\AllowedAgentsTimeManager;
use Bitrix\Crm\RepeatSale\Schedule\Scheduler;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Type\DateTime;

class SchedulerAgent extends AgentBase
{
	public const PERIODICAL_AGENT_RUN_LATER = true;

	public static function doRun(): bool
	{
		$availabilityChecker = Container::getInstance()->getRepeatSaleAvailabilityChecker();
		if ($availabilityChecker->isItemsCountsLessThenLimit())
		{
			Scheduler::getInstance()->execute();
		}

		$instance = new self();
		$timeManager = AllowedAgentsTimeManager::getInstance();
		if ($timeManager->isUseTimeLimit())
		{
			$allowedExecutorTime = $timeManager->getAllowedTime();
			$currentTime = (new DateTime())->disableUserTime();

			// one minute before the queue executor agent starts
			$period = $allowedExecutorTime->getTimestamp() - $currentTime->getTimestamp() - 60;
			$hours12inSeconds = 12 * 3600;
			$instance->setExecutionPeriod($period > 0 ? $period : $hours12inSeconds);
		}
		else
		{
			$hours24inSeconds = 24 * 3600;
			$instance->setExecutionPeriod($hours24inSeconds);
		}

		return self::PERIODICAL_AGENT_RUN_LATER;
	}
}
