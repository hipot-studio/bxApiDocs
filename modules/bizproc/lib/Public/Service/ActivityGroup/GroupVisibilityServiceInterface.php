<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Service\ActivityGroup;

use Bitrix\Bizproc\Activity\Enum\ActivityGroup;

/**
 * Decides which activity groups are visible in the workflow designer catalog.
 *
 * Visibility is driven by a set of HideableGroupRuleInterface rules, so new
 * hideable groups can be added by registering another rule (see bizproc/.settings.php)
 * without changing catalog consumers.
 */
interface GroupVisibilityServiceInterface
{
	/**
	 * Returns false if at least one rule hides the given group.
	 */
	public function isVisible(ActivityGroup $group): bool;

	/**
	 * Removes hidden groups from a catalog map keyed by ActivityGroup value.
	 * Keys that do not map to a known ActivityGroup are kept untouched.
	 *
	 * @param array<string, mixed> $groups
	 *
	 * @return array<string, mixed>
	 */
	public function filterHidden(array $groups): array;
}
