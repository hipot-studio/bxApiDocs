<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Repository\Interface;

use Bitrix\Disk\Internal\Entity\UserQuota;
use Bitrix\Main\Repository\RepositoryInterface;

/**
 * Runs the recalculation queue over the personal quota rows.
 *
 * The queue is the state of the row itself rather than records of its own, so a role that serves the
 * queue addresses rows by id and by the status snapshot it read them with - while the everyday role
 * of {@see UserQuotaRepositoryInterface} addresses the row of one employee by their id. The two are
 * kept apart because their writes guard each other: an everyday write steps aside from a row a run
 * holds, and the write below is the only one allowed into it.
 */
interface UserQuotaRecalculationRepositoryInterface extends RepositoryInterface
{
	/**
	 * Returns up to $limit rows waiting for a recalculation: the stale ones, the ones a dead run left
	 * behind - held longer than $reclaimTtl - and the ones sitting in actual longer than $verifyTtl,
	 * which is what makes the run a reconciliation rather than a catch-up over invalidations.
	 *
	 * The order is part of the contract rather than an accident of the reading: the rows waiting on an
	 * invalidation come first, and the reconciliation is served on what is left of $limit. A portal
	 * hands out its quotas in a batch, so its whole population comes of age for the reconciliation at
	 * the same moment, and a portion given away to that would leave the invalidations of that same
	 * moment waiting for the whole of it to pass.
	 *
	 * Both ages are answered by the clock of the database, the very clock that stamps the rows, and
	 * both arrive as seconds instead of being spelled here: for how long a row is trusted is the
	 * policy of the recalculation, not a property of the storage it lives in.
	 *
	 * @return UserQuota[]
	 */
	public function findCandidates(int $limit, int $reclaimTtl, int $verifyTtl): array;

	/**
	 * Claims the row for a recalculation run and returns the claim - the row as it stands once it is
	 * taken - or null when the claim was not granted.
	 *
	 * The claim is a conditional update over the status and the status time the candidate was read
	 * with, not a read followed by a write: a row another run has already taken changes neither of
	 * them, no row is affected, and this run leaves it alone.
	 *
	 * The status time the claim carries is read back from the row rather than guessed at, since it is
	 * what the write below is judged by.
	 */
	public function capture(UserQuota $candidate): ?UserQuota;

	/**
	 * Writes the aggregated size into the row this run holds and lets the claim go: back to stale for
	 * a row an invalidation reached in the meantime - the run owes one more pass over a source of
	 * truth it has already read - and to actual for every other one. Tells whether the row took the
	 * write.
	 *
	 * The write belongs to the claim and not to the row: a run held longer than the reclaim threshold
	 * has had the row taken over, and a size aggregated before that must not bury the one of the run
	 * holding it now. The status time of the claim stands for the claim itself - while a row is held,
	 * nothing but another claim moves that stamp.
	 */
	public function finishRecalculation(UserQuota $claim, int $usedSize): bool;
}
