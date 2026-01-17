<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Template;

class Select
{
	public function __construct(
		public readonly bool $group = false,
		public readonly bool $members = false,
		public readonly bool $checkLists = false,
		public readonly bool $crm = false,
		public readonly bool $tags = false,
		public readonly bool $subTemplates = false,
		public readonly bool $userFields = false,
		public readonly bool $relatedTasks = false,
		public readonly bool $permissions = false,
		public readonly bool $parent = false,
	)
	{

	}
}
