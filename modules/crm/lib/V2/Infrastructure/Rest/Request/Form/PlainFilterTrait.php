<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Form;

use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Filtering\Logic;

/**
 * The shape of filter a request of the form family reads its resource off: conditions joined by
 * conjunction, negating nothing.
 *
 * The resource is taken by a flat reading of the filter, which keeps the conditions it understands
 * and passes over the rest without a word. A group that negates its conditions or joins them by
 * alternative is therefore refused wherever it sits in the tree and not only at the top: dropped
 * silently, it would answer about a resource the caller did not ask about.
 */
trait PlainFilterTrait
{
	private function isPlainGroup(FilterStructure $group): bool
	{
		return !$group->isNegative() && $group->logic() === Logic::And;
	}

	private function isPlainFilterTree(FilterStructure $group): bool
	{
		if (!$this->isPlainGroup($group))
		{
			return false;
		}

		foreach ($group->getConditions() as $condition)
		{
			if ($condition instanceof FilterStructure && !$this->isPlainFilterTree($condition))
			{
				return false;
			}
		}

		return true;
	}
}
