<?php
declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Agent;

use Bitrix\Disk\Internal\Service\Quota\UsageRecalculator;
use Bitrix\Main\DI\ServiceLocator;

class RecalculateStorageUsageAgent extends BaseDiskAgent
{
	protected UsageRecalculator $recalculator;

	public function __construct()
	{
		$this->recalculator = ServiceLocator::getInstance()->get(UsageRecalculator::class);
	}

	/**
	 * {@inheritDoc}
	 *
	 * The agent stays registered whatever the run found: an empty queue is the ordinary state of a
	 * portal, and a run result of false reaches the core as an empty string - which takes the row of
	 * the agent away and with it the reconciliation itself.
	 */
	protected function runInternal(): RunResult
	{
		$this->recalculator->runQueue();

		return new RunResult(true);
	}
}
