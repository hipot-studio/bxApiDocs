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
			->where('DIRECT', 1)
			->setLimit(1)
			->fetch()
		;

		return $result !== false;
	}

	public function getSubTemplateIdsByParentIds(array $parentIds): array
	{
		$result = [];

		$dependencies = DependenceTable::query()
			->setSelect(['TEMPLATE_ID', 'PARENT_TEMPLATE_ID'])
			->whereIn('PARENT_TEMPLATE_ID', $parentIds)
			->where('DIRECT', 1)
			->fetchAll()
		;

		foreach ($dependencies as $dependence)
		{
			$parentId = (int)$dependence['PARENT_TEMPLATE_ID'];

			$result[$parentId] ??= [];
			$result[$parentId][] = (int)$dependence['TEMPLATE_ID'];
		}

		return $result;
	}
}
