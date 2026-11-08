<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\CheckList;

use Bitrix\Tasks\V2\Internal\Entity;

class SaveCheckListResult
{
	public function __construct(
		public readonly Entity\Task $task,
		public readonly string $revision,
	)
	{
	}
}
