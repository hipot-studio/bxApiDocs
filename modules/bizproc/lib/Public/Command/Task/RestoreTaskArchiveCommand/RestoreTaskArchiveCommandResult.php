<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Command\Task\RestoreTaskArchiveCommand;

use Bitrix\Main\Result;

class RestoreTaskArchiveCommandResult extends Result
{
	public function __construct(public readonly bool $isReachedLimit = false)
	{
		parent::__construct();
	}
}
