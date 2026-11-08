<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

final class FeaturePolicy
{
	/**
	 * @param callable(): int[] $enabledGroupIdsProvider
	 * @param callable(): ?int $resolvedGroupIdProvider
	 */
	public static function isEnabled(
		bool $portalEnabled,
		callable $enabledGroupIdsProvider,
		callable $resolvedGroupIdProvider,
	): bool
	{
		if (!$portalEnabled)
		{
			return false;
		}

		$enabledGroupIds = $enabledGroupIdsProvider();
		if (empty($enabledGroupIds))
		{
			return true;
		}

		return ProjectCohort::isAllowed($resolvedGroupIdProvider(), $enabledGroupIds);
	}
}
