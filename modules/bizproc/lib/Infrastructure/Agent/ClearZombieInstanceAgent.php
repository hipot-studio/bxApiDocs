<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Infrastructure\Agent;

use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use Bitrix\Bizproc\Workflow\Entity\WorkflowInstanceTable;

class ClearZombieInstanceAgent extends BaseAgent
{
	private const CLEAR_LOG_SELECT_LIMIT = 50000;
	private const CLEAR_LOG_DELETE_LIMIT = 1000;
	private const MAX_DAYS_APPEND = 7;
	private const AGENT_MIN_INTERVAL = 60;
	private const AGENT_INTERVAL = 15 * self::AGENT_MIN_INTERVAL;

	public static function run(): string
	{
		$days = \CBPSchedulerService::getDelayMaxDays();
		if ($days < 1)
		{
			return '';
		}

		$days += self::MAX_DAYS_APPEND;
		$deletedCount = self::deleteInstances($days);
		self::setAgentPeriod($deletedCount, $days);

		return self::next();
	}

	private static function deleteInstances(int $days): int
	{
		$query = WorkflowInstanceTable::query();
		$query->addSelect('ID');
		$query->where('MODIFIED', '<', (new Date())->add("-{$days}D"));
		$query->setLimit(self::CLEAR_LOG_SELECT_LIMIT);
		$ids = $query->exec()->fetchAll();
		$idsCount = count($ids);

		while ($partIds = array_splice($ids, 0, static::CLEAR_LOG_DELETE_LIMIT))
		{
			WorkflowInstanceTable::deleteByFilter(['@ID' => array_column($partIds, 'ID')]);
		}

		return $idsCount;
	}

	private static function setAgentPeriod(int $idsCount, int $days): void
	{
		/** @global int $pPERIOD */
		global $pPERIOD;

		if ($idsCount === static::CLEAR_LOG_SELECT_LIMIT)
		{
			$pPERIOD = self::AGENT_INTERVAL;

			return;
		}

		$query = WorkflowInstanceTable::query();
		$query->addSelect('MODIFIED');
		$query->setOrder(['MODIFIED' => 'ASC']);
		$query->setLimit(1);
		$row = $query->exec()->fetch();

		$sourceDate = $row['MODIFIED'] ?? new DateTime();
		$sourceDate->add("{$days}D");

		$pPERIOD = max(self::AGENT_MIN_INTERVAL, $sourceDate->getTimestamp() - time());
	}
}
