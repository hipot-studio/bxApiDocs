<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Provider\Params;

use Bitrix\Main\Provider\Params\FilterInterface;
use Bitrix\Main\Provider\Params\GridParams;
use Bitrix\Main\Provider\Params\PagerInterface;
use Bitrix\Main\Provider\Params\SelectInterface;
use Bitrix\Main\Provider\Params\SortInterface;

class ApplicationParams extends GridParams
{
	public function __construct(
		public PagerInterface $pager,
		public ?FilterInterface $filter = null,
		public ?SortInterface $sort = null,
		public ?SelectInterface $select = null,
	)
	{
		parent::__construct(
			pager: $pager,
			select: $select,
		);
	}
}
