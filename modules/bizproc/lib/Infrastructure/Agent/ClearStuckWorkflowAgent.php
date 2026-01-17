<?php

namespace Bitrix\Bizproc\Infrastructure\Agent;

use Bitrix\Bizproc\Public\Command\WorkflowState\ClearStuckWorkflowCommand\ClearStuckWorkflowCommand;
use Bitrix\Bizproc\Public\Command\WorkflowState\ClearStuckWorkflowCommand\ClearStuckWorkflowResult;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Type\DateTime;

class ClearStuckWorkflowAgent
{
	private const DEFAULT_OFFSET = 20;

	private static function next(int $start): string
	{
		return self::class . "::run($start);";
	}

	public static function run(?int $start = null): string
	{
		$startedDate = $start;
		if (!is_null($startedDate))
		{
			$startedDate = DateTime::createFromTimestamp($startedDate);
		}
		$command = new ClearStuckWorkflowCommand($startedDate);
		/** @var ClearStuckWorkflowResult $result */
		$result = $command->run();

		global $pPERIOD;
		if ($result->startedDate < DateTime::createFromTimestamp(strtotime('-1 year')))
		{
			$pPERIOD = (int)Option::get('bizproc', 'clear_stuck_workflow_offset', self::DEFAULT_OFFSET);
		}
		else
		{
			$pPERIOD = strtotime('next year 01:00') - time();
		}

		return self::next($result->startedDate->getTimestamp());
	}
}
