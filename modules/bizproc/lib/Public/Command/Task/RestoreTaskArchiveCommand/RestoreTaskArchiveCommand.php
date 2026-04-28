<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Command\Task\RestoreTaskArchiveCommand;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Config\Option;

class RestoreTaskArchiveCommand extends AbstractCommand
{
	private const DEFAULT_LIMIT = 200;
	private const DEFAULT_CHUNK_SIZE = 200;

	public readonly int $limit;
	public readonly int $chunkSize;

	public function __construct()
	{
		$this->limit = (int)Option::get('bizproc', 'restore_bp_task_workflow_limit', self::DEFAULT_LIMIT);
		$this->chunkSize = (int)Option::get('bizproc', 'restore_bp_task_chunk_size', self::DEFAULT_CHUNK_SIZE);
	}

	protected function execute(): RestoreTaskArchiveCommandResult
	{
		$isLimitReached = (new RestoreTaskArchiveCommandHandler())($this);

		return new RestoreTaskArchiveCommandResult($isLimitReached);
	}
}
