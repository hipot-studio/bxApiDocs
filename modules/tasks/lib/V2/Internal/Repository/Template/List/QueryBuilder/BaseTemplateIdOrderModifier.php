<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Template\List\QueryBuilder;

use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Tasks\V2\Internal\Repository\Template\List\Field;

class BaseTemplateIdOrderModifier extends BaseOrderModifier
{
	public function modify(Query $query): Query
	{
		$alias = Field::BaseTemplateId->value . '_ORDER';

		return $query
			->registerRuntimeField(
				$alias,
				new ExpressionField(Field::BaseTemplateId->value, 'COALESCE(%s, 0)', ['DIRECT_PARENT.PARENT_TEMPLATE_ID']),
			)
			->addOrder($alias, $this->direction)
		;
	}
}