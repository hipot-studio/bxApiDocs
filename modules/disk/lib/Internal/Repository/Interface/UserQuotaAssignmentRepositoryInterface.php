<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Repository\Interface;

use Bitrix\Main\Repository\RepositoryInterface;

/**
 * Assigns and withdraws the personal quota of an employee.
 *
 * Kept apart from the everyday role of {@see UserQuotaRepositoryInterface} and from the queue role
 * of {@see UserQuotaRecalculationRepositoryInterface} because this role owns another column of the
 * same row: the assigned limit, which neither of them ever writes, while the used size counter and
 * the status of the row are none of its business in return.
 */
interface UserQuotaAssignmentRepositoryInterface extends RepositoryInterface
{
	/**
	 * Writes the assigned limit of the employee, in bytes.
	 *
	 * One row per employee, so assigning a limit to somebody who already has one changes the limit
	 * instead of adding a second row. The write is a single upsert statement and carries nothing but
	 * the limit into an existing row: the used size counter next to it is moved by parallel uploads
	 * and by a recalculation run, and an administrator changing the number of gigabytes has no
	 * business resetting that counter, its actuality status or the claim of a run holding the row.
	 *
	 * A row that did not exist is born with an unknown used size rather than a zero: nobody has
	 * counted the disk of that employee yet, and a zero is a size the quota check would trust.
	 */
	public function assign(int $userId, int $quotaSize): void;

	/**
	 * Removes the quota row of the employee and tells whether there was one to remove.
	 *
	 * The counter of the used size goes away with the row rather than by a write of its own: what an
	 * employee with no limit takes up is nobody's accounting, and a counter kept behind would only
	 * be served stale the day a limit is assigned again.
	 */
	public function withdraw(int $userId): bool;
}
