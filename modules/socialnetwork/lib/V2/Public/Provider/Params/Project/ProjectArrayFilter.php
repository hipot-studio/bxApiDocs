<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Provider\Params\Project;

use Bitrix\Main\ORM\Query\Filter\ConditionTree;

class ProjectArrayFilter extends AbstractProjectFilter
{
	/**
	 * @param array<string, mixed> $filter
	 */
	public function __construct(
		protected readonly array $filter,
	)
	{
	}

	public function prepareFilter(): ConditionTree
	{
		$normalized = $this->mapFilter($this->filter);

		return $this->buildConditionTree($normalized);
	}
}
