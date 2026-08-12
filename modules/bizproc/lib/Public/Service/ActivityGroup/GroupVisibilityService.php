<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Service\ActivityGroup;

use Bitrix\Bizproc\Activity\Enum\ActivityGroup;

class GroupVisibilityService implements GroupVisibilityServiceInterface
{
	/** @var array<string, HideableGroupRuleInterface[]> */
	private array $rulesByGroup = [];

	/**
	 * @param iterable<HideableGroupRuleInterface> $rules
	 */
	public function __construct(iterable $rules = [])
	{
		foreach ($rules as $rule)
		{
			$this->rulesByGroup[$rule->getGroup()->value][] = $rule;
		}
	}

	public function isVisible(ActivityGroup $group): bool
	{
		foreach ($this->rulesByGroup[$group->value] ?? [] as $rule)
		{
			if ($rule->isHidden())
			{
				return false;
			}
		}

		return true;
	}

	public function filterHidden(array $groups): array
	{
		return array_filter(
			$groups,
			function ($value, $key): bool {
				$group = ActivityGroup::tryFrom((string)$key);

				return $group === null || $this->isVisible($group);
			},
			ARRAY_FILTER_USE_BOTH,
		);
	}
}
