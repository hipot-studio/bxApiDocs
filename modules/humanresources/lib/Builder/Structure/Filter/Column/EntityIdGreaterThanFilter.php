<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Builder\Structure\Filter\Column;

use Bitrix\HumanResources\Builder\Structure\Filter\BaseFilter;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;

final class EntityIdGreaterThanFilter extends BaseFilter
{
	public function __construct(
		public readonly int $entityId,
	)
	{}

	public function prepareFilter(): ConditionTree
	{
		return (new ConditionTree())->where($this->getFieldByQueryContext('ENTITY_ID'), '>', $this->entityId);
	}
}
