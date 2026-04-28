<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Command\Task\ArchiveTaskCommand;

use Bitrix\Main\Result;

class ArchiveTaskCommandResult extends Result
{
	public function __construct(
		public readonly bool $isReachedLimit = false,
		public readonly ?int $lastModified = null,
	)
	{
		parent::__construct();
	}
}
