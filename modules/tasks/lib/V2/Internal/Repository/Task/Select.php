<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Task;

class Select
{
	public function __construct(
		public readonly bool $group = false,
		public readonly bool $flow = false,
		public readonly bool $stage = false,
		public readonly bool $members = false,
		public readonly bool $checkLists = false,
		public readonly bool $crm = false,
		public readonly bool $tags = false,
		public readonly bool $subTasks = false,
		public readonly bool $relatedTasks = false,
		public readonly bool $gantt = false,
		public readonly bool $placements = false,
		public readonly bool $containsCommentFiles = false,
		public readonly bool $favorite = false,
		public readonly bool $options = false,
		public readonly bool $parameters = false,
		public readonly bool $results = false,
		public readonly bool $reminders = false,
		public readonly bool $userFields = false,
		public readonly bool $email = false,
		public readonly bool $scenarios = false,
	)
	{
	}
}
