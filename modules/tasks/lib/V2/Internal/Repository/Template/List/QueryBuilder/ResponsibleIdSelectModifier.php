<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Template\List\QueryBuilder;

use Bitrix\Main\ORM\Query\Query;
use Bitrix\Tasks\V2\Internal\Repository\Template\List\Field;

class ResponsibleIdSelectModifier implements QueryModifierInterface
{
	public function modify(Query $query): Query
	{
		return $query->addSelect(Field::ResponsibleId->value)
			->addSelect(Field::Members->value)
		;
	}
}