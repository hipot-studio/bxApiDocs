<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Template\List\QueryBuilder;

use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Tasks\Internals\Task\Template\DependenceTable;
use Bitrix\Tasks\V2\Internal\Repository\Template\List\Field;

trait TemplateChildrenCountModifierTrait
{
	public function modifySelect(Query $query): Query
	{
		$subQuery = DependenceTable::query()
			->addSelect(
				new ExpressionField(
					'COUNT',
					'COUNT(%s)',
					['TEMPLATE_ID']
				)
			)
			->where('PARENT_TEMPLATE_ID', new SqlExpression('%s'))
			->where('DIRECT', 1)
			->addGroup('PARENT_TEMPLATE_ID')
		;

		$query->registerRuntimeField(
			Field::TemplateChildrenCount->value,
			new ExpressionField(
				Field::TemplateChildrenCount->value,
				'COALESCE((' . $subQuery->getQuery() . '), 0)',
				['ID']
			)
		);

		return $query
			->addSelect(Field::TemplateChildrenCount->value, Field::TemplateChildrenCount->value)
			->addSelect(Field::SubTemplates->value, Field::SubTemplates->value)
		;
	}
}