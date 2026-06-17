<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Template\List\QueryBuilder;

use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Tasks\Internals\Task\Template\TemplateTagTable;

class TagListFilterModifier extends BaseFilterModifier
{
	public function modify(Query $query): Query
	{
		$value = $this->value;
		if (!is_array($value))
		{
			$value = [$value];
		}

		$subQuery = TemplateTagTable::query()
			->setSelect(['TEMPLATE_ID'])
			->registerRuntimeField(new ExpressionField('COUNT', 'COUNT(TEMPLATE_ID)'))
			->where('NAME', 'in', $value)
			->setGroup('TEMPLATE_ID')
			->having('COUNT', '=', count($value))
		;

		return $query
			->whereExpr('%s IN (' . $subQuery->getQuery() . ')', ['ID'])
		;
	}
}