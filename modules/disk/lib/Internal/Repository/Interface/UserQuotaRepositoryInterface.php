<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Repository\Interface;

use Bitrix\Disk\Internal\Entity\UserQuota;
use Bitrix\Main\Repository\RepositoryInterface;

/**
 * Reads and writes the personal quota row of an employee.
 *
 * Every write below is a single conditional statement, not a read followed by a write: the used size
 * counter is moved by parallel uploads and by a recalculation run at the same time, so a value read
 * into php is stale by the time it would be written back.
 */
interface UserQuotaRepositoryInterface extends RepositoryInterface
{
	/**
	 * Returns the quota row of the employee, or null when no quota is assigned to them.
	 */
	public function findByUserId(int $userId): ?UserQuota;

	/**
	 * Moves the stored used size by $deltaBytes with an expression on the database side and clamps
	 * the result at zero.
	 *
	 * A row held by a recalculation run keeps its size and gets RECALC_REQUESTED instead: the run is
	 * about to overwrite the value with its own aggregation. A size that has never been calculated
	 * stays unknown - a delta on top of an unknown value is still unknown, and writing a number there
	 * would make the quota check trust it.
	 */
	public function applyDelta(int $userId, int $deltaBytes): void;

	/**
	 * Puts the row into the recalculation queue: stale for a row nobody is calculating, and
	 * RECALC_REQUESTED for a row a run holds - that run has already read the source of truth, so it
	 * owes one more pass.
	 */
	public function markForRecalculation(int $userId): void;
}
