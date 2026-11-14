<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync\Journal;

/**
 * Own readable access of the descendants of a folder about to be deleted: who could reach a descendant
 * through a right laid on the descendant itself rather than inherited from the folder.
 */
interface DescendantAccessSource
{
	/**
	 * One row per own readable right of a descendant, the root excluded. Access codes are returned as
	 * stored: CR is not expanded here.
	 *
	 * @return list<array{objectId: int, isFolder: bool, accessCode: string, createdBy: int}>
	 */
	public function ownReadableAccess(int $rootObjectId): array;
}
