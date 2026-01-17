<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider\Params\Group;

class GroupParams
{
	public function __construct(
		public readonly int $groupId,
		public readonly int $userId,
		public readonly bool $checkAccess = true,
	)
	{

	}
}
