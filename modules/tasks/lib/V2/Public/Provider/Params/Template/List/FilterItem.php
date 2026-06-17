<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider\Params\Template\List;

use Bitrix\Tasks\V2\Public\Provider\Params\FilterOperator;
use Bitrix\Tasks\V2\Public\Provider\Params\Template\Field;

class FilterItem
{
	public function __construct(
		public readonly Field $field,
		public readonly FilterOperator $operator,
		public readonly mixed $value,
	)
	{
	}
}