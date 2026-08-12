<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Service\ActivityGroup;

use Bitrix\Bizproc\Activity\Enum\ActivityGroup;

/**
 * A rule that may hide a single activity group from the workflow designer catalog.
 *
 * Implement this interface and register an instance in the GroupVisibilityService
 * (see bizproc/.settings.php) to hide a new group in the future without touching
 * the designer catalog or the ActivityGroup enum.
 */
interface HideableGroupRuleInterface
{
	/**
	 * The activity group this rule controls.
	 */
	public function getGroup(): ActivityGroup;

	/**
	 * Returns true when the group must be hidden from the catalog right now.
	 */
	public function isHidden(): bool;
}
