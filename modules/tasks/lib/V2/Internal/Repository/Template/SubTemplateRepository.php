<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Template;

use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Tasks\Internals\Task\Template\DependenceTable;

class SubTemplateRepository implements SubTemplateRepositoryInterface
{
	public function containsSubTemplates(int $parentId): bool
	{
		$result = DependenceTable::query()
			->setSelect([new ExpressionField('EXISTS', 1)])
			->where('PARENT_TEMPLATE_ID', $parentId)
			->setLimit(1)
			->fetch()
		;

		return $result !== false;
	}
}
