<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use CAccess;

/**
 * What a method of the contour does before it reads anything by visibility.
 *
 * List visibility is a subquery over b_user_access and refreshes nothing by itself: only the
 * per-object path of the module calls updateCodes, so a user excluded from a group would keep being
 * served the files of that group by every listing until something else happened to refresh the codes.
 * Refreshing them here is therefore mandatory rather than an optimisation, and the comparison of the
 * snapshot rides on the set that refresh has already produced.
 *
 * Which methods this stands in front of is decided by the controller of the contour, not here: the
 * rule is addressed to named methods and never to the prefix disk.sync.*.
 */
final class VisibilityReadGuard
{
	private AccessCodeSnapshotService $snapshotService;

	public function __construct(?AccessCodeSnapshotService $snapshotService = null)
	{
		$this->snapshotService = $snapshotService ?? new AccessCodeSnapshotService();
	}

	/**
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SqlQueryException
	 * @throws SystemException
	 */
	public function onVisibilityReadingMethod(int $userId, string $appId): void
	{
		$this->snapshotService->compareWithSnapshot($userId, $appId, $this->actualAccessCodes($userId));
	}

	/**
	 * Refreshing the codes and reading them is one call and one pass: the platform recalculates only
	 * when its own staleness marker says the user is due - a row of b_user_access_check whose check
	 * date has come - and hands back the set it has just made sure of. Asking for the set separately
	 * would be a second walk over the same rights for nothing.
	 *
	 * The refresh is a write and goes to the master, and so does everything the methods of the contour
	 * read afterwards (Q-14: the reading mode of the contour is the master, and the safe window is
	 * counted without a term for replication).
	 *
	 * @return string[]
	 */
	private function actualAccessCodes(int $userId): array
	{
		return CAccess::GetUserCodesArray($userId);
	}
}
