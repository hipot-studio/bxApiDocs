<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Infrastructure\Agent;

use Bitrix\Bizproc\Public\Command\Task\RestoreTaskArchiveCommand\RestoreTaskArchiveCommand;
use Bitrix\Bizproc\Public\Command\Task\RestoreTaskArchiveCommand\RestoreTaskArchiveCommandResult;
use Bitrix\Main\Config\Option;

class RestoreTaskArchiveAgent extends BaseAgent
{
	private const DEFAULT_OFFSET = 10;

	public static function run(): string
	{
		$command = new RestoreTaskArchiveCommand();
		/** @var RestoreTaskArchiveCommandResult $result */
		$result = $command->run();

		global $pPERIOD;
		if ($result->isReachedLimit)
		{
			$pPERIOD = (int)Option::get('bizproc', 'restore_bp_task_offset', self::DEFAULT_OFFSET);
		}
		else
		{
			$pPERIOD = strtotime('tomorrow 01:00') - time();
		}

		return self::next();
	}
}
