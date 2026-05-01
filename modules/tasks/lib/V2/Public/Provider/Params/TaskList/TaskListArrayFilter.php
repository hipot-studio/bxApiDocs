<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider\Params\TaskList;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;

class TaskListArrayFilter extends AbstractTaskListFilter
{
	public function __construct(protected readonly array $filter)
	{
	}

	/**
	 * @throws ArgumentException
	 */
	public function prepareFilter(): ConditionTree
	{
		return ConditionTree::createFromArray($this->filter) ?? new ConditionTree();
	}
}
