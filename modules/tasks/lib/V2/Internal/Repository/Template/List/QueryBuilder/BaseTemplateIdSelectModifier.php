<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Template\List\QueryBuilder;

use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Tasks\V2\Internal\Repository\Template\List\Field;

class BaseTemplateIdSelectModifier extends BaseSelectModifier
{
	public function modify(Query $query): Query
	{
		return $query
			->registerRuntimeField(
				Field::BaseTemplateId->value,
				new ExpressionField(Field::BaseTemplateId->value, 'COALESCE(%s, 0)', ['DIRECT_PARENT.PARENT_TEMPLATE_ID']),
			)
			->addSelect('DIRECT_PARENT.PARENT_TEMPLATE_ID')
		;
	}
}