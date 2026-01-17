<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider\Params;

use Bitrix\Main\Provider\Params\FilterInterface;
use Bitrix\Main\Provider\Params\GridParams;
use Bitrix\Main\Provider\Params\PagerInterface;
use Bitrix\Main\Provider\Params\SelectInterface;
use Bitrix\Main\Provider\Params\SortInterface;

class TaskHistoryParams extends GridParams
{
	public function __construct(
		public readonly int $taskId,
		public readonly int $userId,
		public PagerInterface $pager,
		public readonly bool $checkAccess = true,
		public ?FilterInterface $filter = null,
		public ?SortInterface $sort = null,
		public ?SelectInterface $select = null,
	)
	{
		parent::__construct(
			pager: $this->pager,
			filter: $filter,
			sort: $sort,
			select: $select,
		);
	}
}
