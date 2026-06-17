<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider\Params\Template;

use Bitrix\Main\Provider\Params\PagerInterface;
use Bitrix\Tasks\V2\Public\Provider\Params\Template\List\Filter;
use Bitrix\Tasks\V2\Public\Provider\Params\Template\List\Select;
use Bitrix\Tasks\V2\Public\Provider\Params\Template\List\Sort;

class ListParams
{
	public function __construct(
		public readonly Select $select,
		public readonly Filter $filter,
		public readonly Sort $sort,
		public readonly PagerInterface $pager,
	)
	{
	}
}