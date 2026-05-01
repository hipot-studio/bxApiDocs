<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider\Params\TaskList;

use Bitrix\Main\Provider\Params\FilterInterface;
use Bitrix\Main\Provider\Params\GridParams;
use Bitrix\Main\Provider\Params\PagerInterface;
use Bitrix\Main\Provider\Params\SelectInterface;
use Bitrix\Main\Provider\Params\SortInterface;

class TaskListParams extends GridParams
{
	public function __construct(
		public int $userId,
		PagerInterface $pagination,
		?FilterInterface $filter = null,
		?SortInterface $sort = null,
		?SelectInterface $select = null,
		public bool $skipAccessCheck = false,
	)
	{
		parent::__construct($pagination, $filter, $sort, $select);
	}
}
