<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider\Params\Template\List;

use Bitrix\Tasks\V2\Public\Provider\Params\SortDirection;
use Bitrix\Tasks\V2\Public\Provider\Params\Template\Field;

class SortItem
{
	public function __construct(
		public readonly Field $field,
		public readonly SortDirection $direction,
	)
	{
	}
}