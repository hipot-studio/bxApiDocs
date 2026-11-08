<?php

namespace Bitrix\StaffTrack\Access;

use Bitrix\Stafftrack\Integration\HumanResources\Structure;

class AccessibleUserProvider
{
	private Structure $structure;

	public function __construct(?Structure $structure = null)
	{
		$this->structure = $structure ?? Structure::getInstance();
	}

	/**
	 * Default scope for a caller who requested no specific users: only himself.
	 * Listing "everyone accessible" is not a real client scenario, so we never
	 * expand into department rosters here (task-250794).
	 *
	 * @param int $callerId
	 * @return int[]
	 */
	public function getDefaultAccessibleUserIds(int $callerId): array
	{
		return [$callerId];
	}

	/**
	 * Filters the requested user IDs down to those the caller may view: himself
	 * plus co-members of the departments he belongs to. Only the requested
	 * users' membership is queried — no department roster is loaded. Without the
	 * humanresources module only the caller himself (if requested) is returned.
	 *
	 * @param int $callerId
	 * @param int[] $requestedUserIds
	 * @return int[]
	 */
	public function filterAccessibleUserIds(int $callerId, array $requestedUserIds): array
	{
		$requestedUserIds = array_values(array_unique(array_map('intval', $requestedUserIds)));

		$accessibleUserIds = $this->structure->filterUsersInSameDepartments($callerId, $requestedUserIds);

		// the caller may always view his own shifts
		if (in_array($callerId, $requestedUserIds, true))
		{
			$accessibleUserIds[] = $callerId;
		}

		return array_values(array_unique(array_map('intval', $accessibleUserIds)));
	}
}
