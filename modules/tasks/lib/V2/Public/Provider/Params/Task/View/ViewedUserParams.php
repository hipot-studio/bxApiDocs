<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider\Params\Task\View;

use Bitrix\Main\Provider\Params\PagerInterface;

class ViewedUserParams
{
	public function __construct(
		public readonly int $taskId,
		public readonly int $userId = 0,
		public ?PagerInterface $pager = null,
		public readonly bool $checkAccess = true,

	)
	{

	}
}
