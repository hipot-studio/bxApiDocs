<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Template\List\QueryBuilder;

use Bitrix\Main\ORM\Query\Query;
use Bitrix\Tasks\V2\Internal\Repository\Template\List\Field;

class TemplateChildrenCountFilterModifier extends BaseFilterModifier
{
	use TemplateChildrenCountModifierTrait;

	public function modify(Query $query): Query
	{
		return $this->modifySelect($query)
			->where(Field::TemplateChildrenCount->value, $this->operator, $this->value)
		;
	}
}