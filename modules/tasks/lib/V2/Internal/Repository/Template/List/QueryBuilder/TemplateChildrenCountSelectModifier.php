<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Template\List\QueryBuilder;

use Bitrix\Main\ORM\Query\Query;

class TemplateChildrenCountSelectModifier extends BaseSelectModifier
{
	use TemplateChildrenCountModifierTrait;

	public function modify(Query $query): Query
	{
		return $this->modifySelect($query);
	}
}