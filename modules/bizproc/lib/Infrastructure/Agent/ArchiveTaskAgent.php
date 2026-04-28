<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Infrastructure\Agent;

use Bitrix\Bizproc\Public\Command\Task\ArchiveTaskCommand\ArchiveTaskCommand;
use Bitrix\Bizproc\Public\Command\Task\ArchiveTaskCommand\ArchiveTaskCommandResult;
use Bitrix\Main\Config\Option;

class ArchiveTaskAgent
{
	private const DEFAULT_OFFSET = 10;

	private static function next(?int $lastModified = null): string
	{
		if ($lastModified)
		{
			return self::class . "::run($lastModified);";
		}

		return self::class . '::run();';
	}

	public static function run(?int $lastModified = null): string
	{
		$command = new ArchiveTaskCommand($lastModified);
		/** @var ArchiveTaskCommandResult $result */
		$result = $command->run();

		global $pPERIOD;
		if ($result->isReachedLimit)
		{
			$pPERIOD = (int)Option::get('bizproc', 'archive_bp_task_offset', self::DEFAULT_OFFSET);
		}
		else
		{
			$pPERIOD = strtotime('tomorrow 01:00') - time();

			return self::next();
		}

		return self::next($result->lastModified);
	}
}
