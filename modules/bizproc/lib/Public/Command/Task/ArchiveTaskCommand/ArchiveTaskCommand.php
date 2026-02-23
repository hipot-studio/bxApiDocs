<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Command\Task\ArchiveTaskCommand;

use Bitrix\Bizproc\Result;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Config\Option;

class ArchiveTaskCommand extends AbstractCommand
{
	private const DEFAULT_LIMIT = 100;
	public readonly int $limit;

	public function __construct()
	{
		$this->limit = (int)Option::get('bizproc', 'archive_bp_task_limit', self::DEFAULT_LIMIT);
	}

	protected function execute(): ArchiveTaskCommandResult
	{
		$isLimitReached = (new ArchiveTaskCommandHandler())($this);

		return new ArchiveTaskCommandResult($isLimitReached);
	}
}
