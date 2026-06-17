<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Template\List\QueryBuilder;

use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Tasks\V2\Internal\Repository\Template\List\Field;

class SearchIndexFilterModifier extends BaseFilterModifier
{
	public function modify(Query $query): Query
	{
		$value = '%' . $this->value . '%';

		return $query->where(
			Query::filter()
				->logic(ConditionTree::LOGIC_OR)
				->whereLike(Field::Title->value, $value)
				->whereLike(Field::Description->value, $value)
				->whereLike(Field::CreatedByName->value, $value)
				->whereLike(Field::CreatedBySecondName->value, $value)
				->whereLike(Field::CreatedByLastName->value, $value)
				->whereLike(Field::CreatedByLogin->value, $value)
				->whereLike(Field::ResponsibleName->value, $value)
				->whereLike(Field::ResponsibleSecondName->value, $value)
				->whereLike(Field::ResponsibleLastName->value, $value)
				->whereLike(Field::ResponsibleLogin->value, $value)
		);
	}
}