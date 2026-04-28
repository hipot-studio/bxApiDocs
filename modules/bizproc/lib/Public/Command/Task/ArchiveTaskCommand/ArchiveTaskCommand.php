<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Command\Task\ArchiveTaskCommand;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Config\Option;

class ArchiveTaskCommand extends AbstractCommand
{
	private const DEFAULT_LIMIT = 100;
	public readonly int $limit;
	public readonly int $candidateLimit;
	public readonly ?int $afterDate;

	public function __construct(?int $afterDate = null)
	{
		$this->limit = (int)Option::get('bizproc', 'archive_bp_task_limit', self::DEFAULT_LIMIT);
		$this->candidateLimit = (int)Option::get('bizproc', 'archive_bp_task_candidate_limit', $this->limit * 5);
		$this->afterDate = $afterDate;
	}

	protected function execute(): ArchiveTaskCommandResult
	{
		$handlerResult = (new ArchiveTaskCommandHandler())($this);
		$lastModified = $handlerResult->lastModified ?? $this->afterDate;

		return new ArchiveTaskCommandResult($handlerResult->isReachedLimit, $lastModified);
	}
}
