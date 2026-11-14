<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\DockedUpload;

use Bitrix\Disk\Internal\Entity\DockedUpload\DockedUploadItem;
use Bitrix\Disk\Internal\Enum\DockedUpload\ItemStatus;
use Bitrix\Disk\Internal\Enum\DockedUpload\ItemType;
use Bitrix\Disk\Internal\Model\DockedUploadItemTable;
use Bitrix\Disk\Internal\Model\DockedUploadQueueTable;
use Bitrix\Disk\Internal\Repository\DockedUpload\DockedUploadItemRepository;
use Bitrix\Disk\Internal\Repository\Interface\DockedUploadQueueRepositoryInterface;
use Bitrix\Disk\Internal\Service\Logger\LoggerWrapper;
use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;
use Psr\Log\LoggerInterface;

/**
 * Server-side arbiter of the single-owner invariant for a docked upload item
 * (ADR section "Cross-tab synchronization").
 *
 * The transport of a given item may run in exactly one browser tab at a time.
 * Ownership is captured with an atomic compare-and-swap (CAS) UPDATE guarded by
 * the current OWNER_CLIENT_ID and by the USER_ID gate, so a race between two
 * surviving tabs of the same browser resolves to a single winner and a foreign
 * ID can never be captured. There is no server-side fast death detector in v1:
 * a dead owner is reclaimed either in-browser (BroadcastChannel, frontend) by
 * passing the stale client id to {@see self::acquire()}, or on a restore, which
 * batch-releases the rows whose heartbeat went stale past
 * {@see self::OWNER_STALE_THRESHOLD_SECONDS} ({@see self::releaseOrphaned()}) -
 * the only path that reaches an owner the surviving tabs never heard of.
 *
 * The class never resumes a transfer: releasing an orphaned, still-in-flight
 * item only reclassifies it (interrupted_needs_file, or uploaded_to_temp when the
 * bytes are already fully in temp) and clears the owner - there is no File to
 * continue with in another tab.
 */
class OwnershipService
{
	/**
	 * How long OWNER_HEARTBEAT_AT may lag before the owner is treated as dead by
	 * {@see self::releaseOrphaned()}.
	 *
	 * The frontend refreshes the heartbeat every 3 s and judges a neighbour dead
	 * after 15 s (const/ownership thresholds.ts), but a browser throttles the timers
	 * of a background tab down to roughly once a minute, so a live transfer in a
	 * background tab may legitimately lag far past 15 s. The margin protects such an
	 * owner; the price is that a crashed row stays unclosable for at most this long,
	 * and it recovers by itself afterwards.
	 */
	public const OWNER_STALE_THRESHOLD_SECONDS = 120;

	/** Rows per statement in {@see self::releaseOrphaned()}. */
	private const RELEASE_CHUNK = 500;

	/** Rows per statement in {@see self::heartbeatMany()}. */
	private const HEARTBEAT_CHUNK = 500;

	public function __construct(
		private readonly DockedUploadItemRepository $itemRepository,
		private readonly DockedUploadQueueRepositoryInterface $queueRepository,
		private readonly LoggerInterface $logger = new LoggerWrapper(null),
	)
	{
	}

	/**
	 * Atomically captures ownership of an active item for $newClientId.
	 *
	 * Succeeds only when the row is currently unowned (OWNER_CLIENT_ID IS NULL)
	 * or, when $knownStaleClientId is given, still held by that known-stale owner
	 * - and always only within the caller's own queues (USER_ID gate,
	 * defense-in-depth on top of the repository isolation). The server is the
	 * arbiter: a successful UPDATE (exactly one affected row) is the captured
	 * ownership.
	 *
	 * Only file elements are captured, on both the plain and the takeover branch. A
	 * structural folder row runs no transport of its own, and an owner on a pending one is
	 * what a restore reads as a crashed transport ({@see self::releaseOrphaned()} then
	 * reclassifies it to interrupted_needs_file), so the type predicate sits in the guard
	 * here exactly as in {@see self::heartbeatMany()}: BOTH writers of OWNER_CLIENT_ID
	 * refuse the row, not only the one a caller happens to reach. The single caller today
	 * arrives past the API-01 refusal ({@see ItemMutationApplier::apply()}) and loses
	 * nothing by it.
	 *
	 * @return bool whether this call won ownership.
	 */
	public function acquire(
		int $itemId,
		int $userId,
		string $newClientId,
		?string $knownStaleClientId = null,
	): bool
	{
		if ($itemId <= 0 || $userId <= 0 || $newClientId === '')
		{
			return false;
		}

		// The client id is written by hand-built SQL, where nothing truncates it: refuse an
		// overlong one rather than store a stump under which the tab would not recognise its
		// own ownership. The stale id below is only ever compared, never written, so an
		// overlong one simply matches nothing.
		if (DockedUploadItemTable::exceedsColumnWidth('OWNER_CLIENT_ID', $newClientId))
		{
			return false;
		}

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();

		$itemTable = DockedUploadItemTable::getTableName();
		$queueTable = DockedUploadQueueTable::getTableName();
		$now = $helper->convertToDbDateTime(new DateTime());
		$newClient = "'" . $helper->forSql($newClientId) . "'";
		$fileType = "'" . $helper->forSql(ItemType::File->value) . "'";

		$ownerGuard = 'OWNER_CLIENT_ID IS NULL';
		if ($knownStaleClientId !== null && $knownStaleClientId !== '')
		{
			$stale = "'" . $helper->forSql($knownStaleClientId) . "'";
			$ownerGuard = "(OWNER_CLIENT_ID IS NULL OR OWNER_CLIENT_ID = {$stale})";
		}

		$sql = "
			UPDATE {$itemTable}
			SET OWNER_CLIENT_ID = {$newClient}, OWNER_HEARTBEAT_AT = {$now}, UPDATE_TIME = {$now}
			WHERE ID = {$itemId}
				AND ITEM_TYPE = {$fileType}
				AND {$ownerGuard}
				AND QUEUE_ID IN (SELECT ID FROM {$queueTable} WHERE USER_ID = {$userId})
		";
		$connection->queryExecute($sql);

		$won = $connection->getAffectedRowsCount() === 1;
		if ($won)
		{
			$this->logger->debug('docked ownership acquired', [
				'itemId' => $itemId,
				'takeover' => $knownStaleClientId !== null && $knownStaleClientId !== '',
			]);
		}

		return $won;
	}

	/**
	 * Persists the owner heartbeat (Q-4). Only the current owner within the
	 * caller's queues can refresh it; the thresholds themselves are a frontend
	 * concern (the backend only stores the timestamp).
	 *
	 * @return bool whether the heartbeat landed (the caller still owns the item).
	 */
	public function heartbeat(int $itemId, int $userId, string $clientId): bool
	{
		if ($itemId <= 0 || $userId <= 0 || $clientId === '')
		{
			return false;
		}

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();

		$itemTable = DockedUploadItemTable::getTableName();
		$queueTable = DockedUploadQueueTable::getTableName();
		$now = $helper->convertToDbDateTime(new DateTime());
		$owner = "'" . $helper->forSql($clientId) . "'";

		$ownerGuard = "
			ID = {$itemId}
				AND OWNER_CLIENT_ID = {$owner}
				AND QUEUE_ID IN (SELECT ID FROM {$queueTable} WHERE USER_ID = {$userId})
		";

		$connection->queryExecute("
			UPDATE {$itemTable}
			SET OWNER_HEARTBEAT_AT = {$now}, UPDATE_TIME = {$now}
			WHERE {$ownerGuard}
		");

		// affectedRows is 0 or 1 here (ID is the PK, so the guard hits at most one row).
		// One changed row can only be our own: the guard pins OWNER_CLIENT_ID to the
		// caller, so ownership is confirmed without the read-back SELECT. Zero is
		// ambiguous - either the caller is no longer the owner, or the heartbeat landed
		// within the same second and wrote an identical timestamp, which MySQL reports
		// as 0 changed rows (core does not enable CLIENT_FOUND_ROWS) even though the
		// owner still matched - so fall back to the read-back to tell the two apart.
		if ($connection->getAffectedRowsCount() === 1)
		{
			return true;
		}

		$owned = $connection
			->query("SELECT ID FROM {$itemTable} WHERE {$ownerGuard}")
			->fetch()
		;

		return $owned !== false;
	}

	/**
	 * Batch form of the ownership CAS a keepalive volley performs: one tab beats for the
	 * whole set of rows it holds at once (A5), so the cost is ceil(N / HEARTBEAT_CHUNK)
	 * statements instead of one CAS - and, on a lost heartbeat, a second one - per row.
	 *
	 * The guard is the union of the two the single path walks in turn
	 * ({@see self::heartbeat()} then {@see self::acquire()} without a named stale owner):
	 * a row still held by the caller has its beat refreshed, an unowned row is captured,
	 * and a row another tab holds fails the guard and is left alone. Assigning
	 * OWNER_CLIENT_ID unconditionally is what merges the two - it is a no-op for the rows
	 * the caller already owns.
	 *
	 * Only file elements are beaten for. A structural folder row runs no transport of its
	 * own, and an owner on a pending one is precisely what a restore reads as a crashed
	 * transport ({@see self::releaseOrphaned()} then reclassifies it to
	 * interrupted_needs_file), so the type predicate sits in the guard rather than in the
	 * callers: the single path reaches this write only past the API-01 refusal
	 * ({@see ItemMutationApplier::apply()}), and a bulk caller that names a folder id must
	 * not be able to create that state either.
	 *
	 * Nothing is returned: affectedRows cannot tell a refused row from one whose beat
	 * landed inside the same second (the same ambiguity {@see self::heartbeat()} resolves
	 * with a read-back), so the caller reads the rows back and sees the owner itself.
	 *
	 * @param int[] $itemIds
	 */
	public function heartbeatMany(array $itemIds, int $userId, string $clientId): void
	{
		$ids = [];
		foreach ($itemIds as $itemId)
		{
			$itemId = (int)$itemId;
			if ($itemId > 0)
			{
				$ids[$itemId] = $itemId;
			}
		}

		if ($ids === [] || $userId <= 0 || $clientId === '')
		{
			return;
		}

		// Same hand-built write as {@see self::acquire()}: an id the column cannot hold is
		// dropped here rather than thrown, since the batch caller answers a throw by
		// silently redoing the whole volley one row at a time.
		if (DockedUploadItemTable::exceedsColumnWidth('OWNER_CLIENT_ID', $clientId))
		{
			return;
		}

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();

		$itemTable = DockedUploadItemTable::getTableName();
		$queueTable = DockedUploadQueueTable::getTableName();
		$now = $helper->convertToDbDateTime(new DateTime());
		$owner = "'" . $helper->forSql($clientId) . "'";
		$fileType = "'" . $helper->forSql(ItemType::File->value) . "'";

		$affected = 0;
		foreach (array_chunk(array_values($ids), self::HEARTBEAT_CHUNK) as $chunk)
		{
			$idList = implode(', ', $chunk);

			$connection->queryExecute("
				UPDATE {$itemTable}
				SET OWNER_CLIENT_ID = {$owner}, OWNER_HEARTBEAT_AT = {$now}, UPDATE_TIME = {$now}
				WHERE ID IN ({$idList})
					AND ITEM_TYPE = {$fileType}
					AND (OWNER_CLIENT_ID = {$owner} OR OWNER_CLIENT_ID IS NULL)
					AND QUEUE_ID IN (SELECT ID FROM {$queueTable} WHERE USER_ID = {$userId})
			");

			$affected += $connection->getAffectedRowsCount();
		}

		// One aggregated record per volley - a folder upload beats for up to a thousand rows.
		$this->logger->debug('docked ownership batch heartbeat', [
			'items' => count($ids),
			'affected' => $affected,
		]);
	}

	/**
	 * Releases ownership of an orphaned, still-in-flight item (graceful close or
	 * a stale heartbeat).
	 *
	 * The item is reclassified and its owner cleared: to uploaded_to_temp when the
	 * bytes are already fully in temp (per getStatus of the temp file), otherwise
	 * to interrupted_needs_file (the File is gone, so the user must re-select it).
	 * Terminal or already-actionable items are left untouched. The USER_ID gate
	 * (via the queue) keeps a foreign item invisible.
	 *
	 * @param string|null $tempToken bytes the caller reports it has left in temp for this
	 *   row. A tab closing on a file whose bytes reached temp in full, but whose commit
	 *   packet never left, is the whole reason this exists: the token is the only way back
	 *   to those bytes, and without it on the row the release below reads them as absent,
	 *   ends the row at interrupted_needs_file - the user uploads the file a second time -
	 *   and leaves the temp invisible to every collector of the feature, which all read the
	 *   token off the row. It is taken here rather than by the caller so that it is written
	 *   under the very checks that arbitrate the release, and under no fewer: the USER_ID
	 *   gate, the releasable status, and - when the caller named an $expectedClientId - that
	 *   it still matches the owner. A caller that names no client id is not checked against
	 *   the owner here any more than the release itself is, and it gains nothing by it: the
	 *   same user writes the same token on the same row through the plain token-only path.
	 * @param bool|null $reportedTokenIsPresent whether the bytes behind $tempToken are really
	 *   in temp, when the caller has already established that for a whole packet of hand-backs
	 *   at once ({@see TempPresenceProbe}). A release is one row, but an unload volley is a
	 *   batch of them, and asking the transport row by row costs a query each. Null means
	 *   nobody has asked yet and this call asks for itself - what the single-row surface does.
	 * @return ItemStatus|null the new status, or null when nothing was released.
	 */
	public function release(
		int $itemId,
		int $userId,
		?string $expectedClientId = null,
		?string $tempToken = null,
		?bool $reportedTokenIsPresent = null,
	): ?ItemStatus
	{
		if ($itemId <= 0 || $userId <= 0)
		{
			return null;
		}

		$item = $this->itemRepository->getById($itemId);
		if ($item === null || !$this->belongsToUser($item, $userId))
		{
			return null;
		}

		if ($expectedClientId !== null && $item->getOwnerClientId() !== $expectedClientId)
		{
			// Someone else already owns the item - do not steal the release.
			return null;
		}

		$fromStatus = $item->getStatus();
		if (!$this->isReleasable($fromStatus))
		{
			return null;
		}

		$this->adoptReportedTempToken($item, $userId, $tempToken);

		$newStatus = $this->hasCompleteTempBytes($item, $userId, $tempToken, $reportedTokenIsPresent)
			? ItemStatus::UploadedToTemp
			: ItemStatus::InterruptedNeedsFile
		;

		// Guarded CAS instead of a full-row save (mirrors acquire()): only reclassify
		// and clear the owner while the row is still held by the observed owner and
		// still in the releasable state we read. A concurrent acquire that just captured
		// the item (or a status change) fails the guard, so a stale release can never
		// clobber a freshly captured owner or overwrite a moved-on status.
		if (!$this->casRelease($itemId, $userId, $item->getOwnerClientId(), $fromStatus, $newStatus))
		{
			return null;
		}

		$item
			->setStatus($newStatus)
			->setOwnerClientId(null)
			->setOwnerHeartbeatAt(null)
		;

		$this->logger->info('docked ownership released', [
			'itemId' => $itemId,
			'fromStatus' => $fromStatus?->value,
			'toStatus' => $newStatus->value,
		]);

		return $newStatus;
	}

	/**
	 * Batch variant of {@see self::release()} for rows a crashed tab left in flight:
	 * still pending / uploading / uploaded_to_temp, with no live owner. The caller has
	 * already established that these rows hold no bytes in temp, so the new status is always
	 * interrupted_needs_file and no per-item read happens here at all.
	 *
	 * The staleness predicate sits in the WHERE clause - that IS the compare-and-swap.
	 * An owner that refreshed its heartbeat between the caller's read and this write
	 * keeps its row. Pinning a specific OWNER_CLIENT_ID would be wrong: a crashed tab
	 * may have left no owner at all.
	 *
	 * Chunked, so the query count grows as ceil(N / RELEASE_CHUNK) rather than with N.
	 *
	 * @param int[] $itemIds
	 * @return int[] ids of the rows this call actually released
	 */
	public function releaseOrphaned(array $itemIds, int $userId): array
	{
		$ids = [];
		foreach ($itemIds as $itemId)
		{
			$itemId = (int)$itemId;
			if ($itemId > 0)
			{
				$ids[$itemId] = $itemId;
			}
		}

		if ($ids === [] || $userId <= 0)
		{
			return [];
		}

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();

		$itemTable = DockedUploadItemTable::getTableName();
		$queueTable = DockedUploadQueueTable::getTableName();
		$now = $helper->convertToDbDateTime(new DateTime());
		$staleAt = DateTime::createFromTimestamp(time() - self::OWNER_STALE_THRESHOLD_SECONDS);
		$staleBefore = $helper->convertToDbDateTime($staleAt);
		$newStatusSql = $helper->forSql(ItemStatus::InterruptedNeedsFile->value);
		$inFlightSql =
			"'" . $helper->forSql(ItemStatus::Pending->value) . "'"
			. ", '" . $helper->forSql(ItemStatus::Uploading->value) . "'"
			. ", '" . $helper->forSql(ItemStatus::UploadedToTemp->value) . "'"
		;
		$userGate = "QUEUE_ID IN (SELECT ID FROM {$queueTable} WHERE USER_ID = {$userId})";

		$released = [];
		$queueIds = [];
		foreach (array_chunk(array_values($ids), self::RELEASE_CHUNK) as $chunk)
		{
			$idList = implode(', ', $chunk);

			$connection->queryExecute("
				UPDATE {$itemTable}
				SET STATUS = '{$newStatusSql}', OWNER_CLIENT_ID = NULL, OWNER_HEARTBEAT_AT = NULL, UPDATE_TIME = {$now}
				WHERE ID IN ({$idList})
					AND STATUS IN ({$inFlightSql})
					AND (OWNER_HEARTBEAT_AT IS NULL OR OWNER_HEARTBEAT_AT < {$staleBefore})
					AND {$userGate}
			");

			// Read back rather than trust affectedRows: a concurrent restore may have
			// released the same row first, which leaves it in exactly the wanted state and
			// still counts as released for this caller.
			$rows = $connection->query("
				SELECT ID, QUEUE_ID FROM {$itemTable}
				WHERE ID IN ({$idList})
					AND STATUS = '{$newStatusSql}'
					AND OWNER_CLIENT_ID IS NULL
					AND {$userGate}
			");
			while ($row = $rows->fetch())
			{
				$released[] = (int)$row['ID'];
				$queueIds[(int)$row['QUEUE_ID']] = true;
			}
		}

		if ($released !== [])
		{
			// One aggregated record per call: a folder upload would otherwise produce
			// thousands of per-item lines. Metadata only - no file names or other PII.
			$this->logger->info('docked orphaned items released', [
				'count' => count($released),
				'queueIds' => array_keys($queueIds),
			]);
		}

		return $released;
	}

	/**
	 * Atomic release CAS: clears the owner and reclassifies the status only when the
	 * row still carries the observed owner and the observed releasable status, within
	 * the caller's own queues (USER_ID gate).
	 *
	 * @return bool whether this call won the release (exactly one affected row).
	 */
	private function casRelease(
		int $itemId,
		int $userId,
		?string $expectedOwnerClientId,
		ItemStatus $expectedStatus,
		ItemStatus $newStatus,
	): bool
	{
		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();

		$itemTable = DockedUploadItemTable::getTableName();
		$queueTable = DockedUploadQueueTable::getTableName();
		$now = $helper->convertToDbDateTime(new DateTime());
		$newStatusSql = $helper->forSql($newStatus->value);
		$expectedStatusSql = $helper->forSql($expectedStatus->value);

		$ownerGuard = $expectedOwnerClientId === null || $expectedOwnerClientId === ''
			? 'OWNER_CLIENT_ID IS NULL'
			: "OWNER_CLIENT_ID = '" . $helper->forSql($expectedOwnerClientId) . "'"
		;

		$connection->queryExecute("
			UPDATE {$itemTable}
			SET STATUS = '{$newStatusSql}', OWNER_CLIENT_ID = NULL, OWNER_HEARTBEAT_AT = NULL, UPDATE_TIME = {$now}
			WHERE ID = {$itemId}
				AND STATUS = '{$expectedStatusSql}'
				AND {$ownerGuard}
				AND QUEUE_ID IN (SELECT ID FROM {$queueTable} WHERE USER_ID = {$userId})
		");

		return $connection->getAffectedRowsCount() === 1;
	}

	private function belongsToUser(DockedUploadItem $item, int $userId): bool
	{
		$queue = $this->queueRepository->getById((int)$item->getQueueId());

		return $queue !== null && (int)$queue->getUserId() === $userId;
	}

	/**
	 * Only an item that is still in flight can be released; committed / canceled /
	 * failed / duplicate_conflict / interrupted are already terminal or actionable.
	 */
	private function isReleasable(?ItemStatus $status): bool
	{
		return $status === ItemStatus::Pending
			|| $status === ItemStatus::Uploading
			|| $status === ItemStatus::UploadedToTemp
		;
	}

	/**
	 * Puts the token the caller reported onto the row, so that the bytes behind it are the
	 * ones the reclassification is decided by and the collectors can reach them later. It
	 * stands past every check the release itself had to pass - the USER_ID gate, the
	 * releasable status and, where one was named, the owner - so it writes on exactly the
	 * rows a release writes on, and on no others.
	 *
	 * A token the row already carries is not rewritten - the same hand-back repeated (the
	 * unload volley is retried) must not cost an UPDATE per call. The point write refuses a
	 * committed or canceled row on its own; when it does not land, the row keeps the token it
	 * had and the status below is decided by that one.
	 *
	 * A release that goes on to lose its CAS to a takeover leaves the token behind on
	 * purpose: it describes bytes that really are in temp for this row, and the tab that took
	 * the row over needs them exactly as this one did.
	 */
	private function adoptReportedTempToken(DockedUploadItem $item, int $userId, ?string $tempToken): void
	{
		if ($tempToken === null || $tempToken === '' || (string)$item->getTempToken() === $tempToken)
		{
			return;
		}

		if ($this->itemRepository->updateTempToken((int)$item->getId(), $userId, $tempToken))
		{
			$item->setTempToken($tempToken);
		}
	}

	/**
	 * Whether the bytes of the row are in temp in full AND belong to the caller.
	 *
	 * One rule, whichever surface asked. The token on a row is written by the client and
	 * proves nothing about whose bytes it names, so the lookup is {@see TempPresenceProbe} -
	 * the same one the restore path and all four temp-freeing paths share, GUID plus
	 * UPLOADED plus CREATED_BY. Without the owner in the question a row of one user could be
	 * made to stand at uploaded_to_temp on the strength of another user's temp: not a way to
	 * take the file (the commit re-asks who owns the temp and refuses), but a row lying about
	 * its own state, and an answer that says whether some named token is still alive.
	 *
	 * The probe does not re-verify the HMAC the transport signs the token with, and one thing
	 * IS lost by that, inside the caller's own data: a token of the shape `<own guid>.garbage`
	 * now reads as present, so a row can be put at uploaded_to_temp on a signature that would
	 * never have passed. It costs the user their own next commit, which fails the signature
	 * check for real (ERROR_INVALID_TEMP_FILE) instead of the row asking for the file again
	 * straight away - a worse error later, on one's own row, and nothing beyond it. What the
	 * signature would have stopped across the boundary of a user - naming a temp that is not
	 * yours - the CREATED_BY filter stops on its own.
	 *
	 * A batch caller asks it once for a whole packet of hand-backs and hands the verdict in;
	 * that is the same question with the same answer, asked in one statement instead of one
	 * per row. It is used only for the token the row really ended up carrying - if the point
	 * write did not land, the row keeps its own token and this asks about that one.
	 *
	 * @param string|null $resolvedToken the token the verdict was taken for
	 * @param bool|null $resolvedVerdict the verdict, or null when nobody asked yet
	 */
	private function hasCompleteTempBytes(
		DockedUploadItem $item,
		int $userId,
		?string $resolvedToken = null,
		?bool $resolvedVerdict = null,
	): bool
	{
		$token = (string)$item->getTempToken();
		if ($token === '')
		{
			return false;
		}

		if ($resolvedVerdict !== null && $resolvedToken === $token)
		{
			return $resolvedVerdict;
		}

		return (new TempPresenceProbe())->filterOwnPresent([$token], $userId) !== [];
	}
}
