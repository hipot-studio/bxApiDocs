<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Task\Add\Config;

class AddTaskConfig
{
	public function __construct(
		public readonly int $userId,
		public readonly bool $withSubTasks = false,
	)
	{

	}
}
