<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Template\Add\Config;

class AddConfig
{
	public function __construct(
		public readonly int $userId,
		public readonly bool $withReplication = false,
		public readonly bool $withCheckLists = true,
		public readonly bool $withRelatedTasks = true,
	)
	{
	}
}
