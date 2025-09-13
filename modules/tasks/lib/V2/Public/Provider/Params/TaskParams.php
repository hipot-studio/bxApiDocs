<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider\Params;

class TaskParams
{
	public function __construct(
		public readonly int $taskId,
		public readonly int $userId,
		public readonly bool $group = true,
		public readonly bool $flow = true,
		public readonly bool $stage = true,
		public readonly bool $members = true,
		public readonly bool $checkLists = true,
		public readonly bool $chat = true,
		public readonly bool $checkTaskAccess = true,
		public readonly bool $checkGroupAccess = true,
		public readonly bool $checkFlowAccess = true,
	)
	{

	}
}