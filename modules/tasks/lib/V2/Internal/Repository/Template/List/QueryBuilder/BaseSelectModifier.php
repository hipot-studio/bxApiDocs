<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Template\List\QueryBuilder;

use Bitrix\Main\ORM\Query\Query;
use Bitrix\Tasks\V2\Internal\Repository\Template\List\Field;

class BaseSelectModifier implements QueryModifierInterface
{
	public function __construct(protected readonly Field $field)
	{
	}

	public function modify(Query $query): Query
	{
		return $query->addSelect($this->field->value);
	}
}