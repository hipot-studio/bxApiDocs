<?php

namespace Bitrix\StaffTrack\Public\Provider;

use Bitrix\StaffTrack\Dictionary\CheckInGeoPermissionScenario;
use Bitrix\StaffTrack\Dictionary\CheckInGeoPermissionState;

/**
 * The mark of one employee. An employee who never answered the popup has no mark at all, so that
 * state is the absence of this object and not a case of it.
 */
final class CheckInGeoPermissionDto
{
	/**
	 * @param CheckInGeoPermissionState $state
	 * @param CheckInGeoPermissionScenario[] $postponedScenarios scenarios the permission was asked for
	 * @param int $postponedCount number of postponements in a row
	 * @param int|null $postponedAt unix time of the last postponement
	 */
	public function __construct(
		public readonly CheckInGeoPermissionState $state,
		public readonly array $postponedScenarios = [],
		public readonly int $postponedCount = 0,
		public readonly ?int $postponedAt = null,
	)
	{
	}
}
