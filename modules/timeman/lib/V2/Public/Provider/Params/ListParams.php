<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Provider\Params;

use Bitrix\Main\Provider\Params\FilterInterface;
use Bitrix\Main\Provider\Params\GridParams;
use Bitrix\Main\Provider\Params\PagerInterface;
use Bitrix\Main\Provider\Params\SelectInterface;
use Bitrix\Main\Provider\Params\SortInterface;

class ListParams extends GridParams
{
	public function __construct(
		PagerInterface $pager,
		?FilterInterface $filter = null,
		?SortInterface $sort = null,
		?SelectInterface $select = null,
	) {
		parent::__construct($pager, $filter, $sort, $select);
	}
}
