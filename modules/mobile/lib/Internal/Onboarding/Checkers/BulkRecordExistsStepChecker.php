<?php

namespace Bitrix\Mobile\Internal\Onboarding\Checkers;

use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\DateTime;

/**
 * A record-exists check whose per-user query re-reads the same date range of the campaign for
 * every candidate, because no index of its table starts with the author. Reading the whole
 * selection at once turns that into one query per pass.
 *
 * Announcing a selection is deliberately free: preload() only remembers it, and the table is
 * read on the first isCompleted() that reaches it. The pass announces the selection for every
 * campaign region, but the series sends on a handful of days of the campaign and the path of
 * the portal uses one or two steps - so on most passes nobody asks this check at all, and a
 * preload that read eagerly would spend a query and, worse, load the module of the table
 * inside the epilogue of somebody's hit.
 *
 * Preloading stays an optimisation, never a contract change: a check that was not preloaded,
 * and a user outside the announced selection, keep the per-user route.
 */
abstract class BulkRecordExistsStepChecker extends RecordExistsStepChecker implements BulkStepChecker
{
	/**
	 * Upper bound of one read of the announced selection. The selection is every candidate of the
	 * portal and travels into the query as an `IN` expression, which the database has to parse,
	 * plan and keep within its packet limit - same reason and same shape as the bound of
	 * UserOptionReader.
	 */
	public const CHUNK_SIZE = 500;

	/**
	 * The selection announced by the pass, `null` when none was. Kept unread until somebody
	 * asks about one of its users.
	 *
	 * @var int[]|null
	 */
	private ?array $announcedUserIds = null;

	private ?DateTime $announcedSince = null;

	/**
	 * Completion of the announced selection once it has been read, keyed by user id so that a
	 * lookup does not walk the selection. Every announced user is present, with `false` for
	 * those who left no record.
	 *
	 * @var array<int, bool>|null
	 */
	private ?array $completedUserIds = null;

	/**
	 * Name of the column holding the author of the record - the one the batch query groups by
	 * and selects.
	 */
	abstract protected function getAuthorField(): string;

	/**
	 * The batch shape of recordExists(): the same conditions asked about a selection and
	 * grouped by author, so one row comes back per user who left a record.
	 *
	 * @param int[] $userIds never empty
	 */
	abstract protected function createBatchQuery(array $userIds, DateTime $sinceDate): Query;

	public function preload(array $userIds, DateTime $sinceDate): void
	{
		$userIds = array_values(array_unique(array_filter(
			array_map('intval', $userIds),
			static fn (int $userId): bool => $userId > 0,
		)));

		if ($userIds === [])
		{
			return;
		}

		$this->announcedUserIds = $userIds;
		$this->announcedSince = $sinceDate;
		$this->completedUserIds = null;
	}

	protected function recordExists(int $userId, DateTime $sinceDate): bool
	{
		$completed = $this->readAnnouncedSelection($sinceDate);

		// a user the announcement did not cover is not "not completed" - nothing was read
		// about them - so they keep the per-user route rather than a silent false
		if ($completed !== null && array_key_exists($userId, $completed))
		{
			return $completed[$userId];
		}

		return $this->recordExistsForUser($userId, $sinceDate);
	}

	/**
	 * Whether the single user left a record, asked of the table directly.
	 */
	abstract protected function recordExistsForUser(int $userId, DateTime $sinceDate): bool;

	/**
	 * The announced selection, read on demand. An announcement is only valid for the bound it
	 * was made against: a pass uses one anchor throughout, but preload() is an optional
	 * optimisation nobody has to call and a harness can state a moment of its own, so a
	 * question about another bound must not be answered from a selection read for a different
	 * day.
	 *
	 * @return array<int, bool>|null null while no selection for this bound was announced
	 */
	private function readAnnouncedSelection(DateTime $sinceDate): ?array
	{
		if ($this->announcedUserIds === null || !$this->isAnnouncedBound($sinceDate))
		{
			return null;
		}

		if ($this->completedUserIds !== null)
		{
			return $this->completedUserIds;
		}

		$completed = [];
		foreach (array_chunk($this->announcedUserIds, self::CHUNK_SIZE) as $chunk)
		{
			// the chunks are disjoint, so the union keeps every answer it is given
			$completed += $this->fetchCompletedUserIds($chunk, $this->announcedSince);
		}

		$this->completedUserIds = array_replace(
			array_fill_keys($this->announcedUserIds, false),
			array_map(static fn (): bool => true, $completed),
		);

		return $this->completedUserIds;
	}

	private function isAnnouncedBound(DateTime $sinceDate): bool
	{
		return $this->announcedSince !== null
			&& $this->announcedSince->getTimestamp() === $sinceDate->getTimestamp()
		;
	}

	/**
	 * @param int[] $userIds
	 * @return array<int, true>
	 */
	protected function fetchCompletedUserIds(array $userIds, DateTime $sinceDate): array
	{
		$rows = $this->createBatchQuery($userIds, $sinceDate)->fetchAll();

		return array_fill_keys(array_column($rows, $this->getAuthorField()), true);
	}
}
