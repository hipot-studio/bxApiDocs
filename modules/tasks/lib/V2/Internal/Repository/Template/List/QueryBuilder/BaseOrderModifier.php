<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Template\List\QueryBuilder;

use Bitrix\Main\ORM\Query\Query;
use Bitrix\Tasks\V2\Internal\Repository\Template\List\Field;

class BaseOrderModifier implements QueryModifierInterface
{
	public function __construct(protected readonly Field $field, protected readonly string $direction)
	{
	}

	public function modify(Query $query): Query
	{
		return $query->addOrder($this->field->value, $this->direction);
	}
}