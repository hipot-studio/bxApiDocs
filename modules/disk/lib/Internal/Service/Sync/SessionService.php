<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync;

use Bitrix\Disk\Driver;
use Bitrix\Disk\Infrastructure\Rest\V3\Dto\Sync\SessionDto;
use Bitrix\Disk\Internal\Repository\Sync\SyncSessionRepository;
use Bitrix\Disk\Internal\Service\Sync\Journal\SafeWindow;
use Bitrix\Disk\Internal\Service\Sync\Position\JournalCursor;
use Bitrix\Disk\Internal\Service\Sync\Position\SnapshotToken;
use Bitrix\Main\Config\Option;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;

/**
 * Opening a cycle of synchronisation for a pair of a user and an application.
 *
 * What the session fixes is what makes the initial fetch repeatable: the boundaries of the state
 * both of its phases are read against, the storages visible to the user at this moment, the
 * checkpoints of visibility they hold in storages of other people, the depth of history actually
 * applied and how long the fetch may take. Everything the fetch of phase 3 reads later comes from
 * here, so the same instant and the same lists serve the whole cycle.
 *
 * The cursor handed out with the session stands at that same boundary rather than at the start of
 * the journal: everything below it is what the fetch itself hands over, and reading it out of the
 * journal a second time would make every new pair replay the whole retention. What the two overlap
 * by is the width of the safe window twice over, and applying that overlap again changes nothing.
 *
 * Creating a session again while one is live is a normal thing to do: the previous session is
 * replaced and its fetch token stops matching the live row, while the cursor of the previous
 * session keeps working - it belongs to the pair, not to the session, and invalidating it would
 * make every change of session a re-read of the journal.
 */
final class SessionService
{
	/**
	 * The ceiling the portal puts on the requested depth of history. It is a setting rather than a
	 * derived number: the lever exists so that a portal whose initial fetch does not fit into the
	 * life of its token can shorten what it promises, and how much shortening is enough is a
	 * property of the portal, not a formula.
	 */
	public const OPTION_HISTORY_DEPTH_MAX_DAYS = 'disk_sync_history_depth_max_days';

	public const DEFAULT_HISTORY_DEPTH_MAX_DAYS = 365;

	/**
	 * How long the token of the initial fetch is accepted for. Kept apart from the published
	 * numbers of the contour on purpose: a client never plans against it, it only has to finish the
	 * fetch before it runs out.
	 */
	public const OPTION_SNAPSHOT_TOKEN_TTL_DAYS = 'disk_sync_snapshot_token_ttl_days';

	public const DEFAULT_SNAPSHOT_TOKEN_TTL_DAYS = 7;

	private const SECONDS_IN_DAY = 86400;

	private SyncLimits $limits;
	private SyncSessionRepository $repository;
	private StorageListProvider $storageListProvider;
	private ApproximateCountProvider $approximateCountProvider;
	private SafeWindow $safeWindow;
	private CheckpointListProvider $checkpointListProvider;

	public function __construct(
		?SyncLimits $limits = null,
		?SyncSessionRepository $repository = null,
		?StorageListProvider $storageListProvider = null,
		?ApproximateCountProvider $approximateCountProvider = null,
		?SafeWindow $safeWindow = null,
		?CheckpointListProvider $checkpointListProvider = null,
	)
	{
		$this->limits = $limits ?? new SyncLimits();
		$this->repository = $repository ?? new SyncSessionRepository();
		$this->storageListProvider = $storageListProvider ?? new StorageListProvider();
		$this->approximateCountProvider = $approximateCountProvider ?? new ApproximateCountProvider();
		$this->safeWindow = $safeWindow ?? new SafeWindow();
		$this->checkpointListProvider = $checkpointListProvider ?? new CheckpointListProvider();
	}

	/**
	 * @param int $requestedHistoryDepthDays what the client asked for; what is applied is answered
	 *        back in {@see SessionDto::$effectiveHistoryDepthDays}
	 * @param int $plannedPollIntervalSeconds already checked against the published bounds by the
	 *        request; recorded and nothing more - no server behaviour is timed by it
	 * @throws SystemException the session could not be written
	 */
	public function create(
		int $userId,
		string $appId,
		int $requestedHistoryDepthDays,
		int $plannedPollIntervalSeconds,
	): SessionDto
	{
		/**
		 * The boundary is set back by the safe window rather than to the current instant. A change
		 * committed a moment ago may still be invisible to the reader that serves the fetch - a
		 * write transaction still open, a read replica behind, the clocks of the nodes apart - and
		 * a boundary at "now" would leave it out of the fetch while its journal record already lies
		 * before the boundary. Stepping the boundary back costs the client a small overlap it reads
		 * twice; not stepping it back loses the change silently.
		 */
		$boundTimestamp = time() - $this->limits->getSafeWindowSeconds();

		// Whole seconds throughout: the row keeps the boundaries in a datetime column, and a
		// microsecond that survives into the token would stop the token from matching its own row.
		$boundAt = DateTime::createFromTimestamp($boundTimestamp);
		$snapshotTokenExpiresAt = DateTime::createFromTimestamp($boundTimestamp + $this->getSnapshotTokenTtlSeconds());
		$cursorExpiresAt = DateTime::createFromTimestamp($boundTimestamp + $this->limits->getJournalRetentionSeconds());

		$effectiveHistoryDepthDays = $this->resolveEffectiveHistoryDepthDays($requestedHistoryDepthDays);
		$storages = $this->storageListProvider->getVisibleStorages($userId);
		// Asked for once, here, and not on every page: the checkpoints are what the stream of shared
		// objects merges by, and a page rebuilding them would pay for the whole of the rights index again.
		$checkpointsByStorage = $this->checkpointListProvider->getCheckpointsByStorage($userId);

		$sessionId = $this->store(
			$userId,
			$appId,
			$snapshotTokenExpiresAt,
			$boundAt,
			$effectiveHistoryDepthDays,
			$plannedPollIntervalSeconds,
			array_keys($storages),
			$checkpointsByStorage,
		);

		$session = new SessionDto();
		$session->sessionId = $sessionId;
		$session->snapshotToken = SnapshotToken::start($userId, $appId, $snapshotTokenExpiresAt, $boundAt, $boundAt)
			->encode()
		;
		// From the boundary the session fixed and not from the start of the journal: what happened
		// before that boundary is what the fetch hands over, and reading it out of the journal as well
		// would replay the whole retention to every new pair.
		$session->cursor = JournalCursor::at(
			$userId,
			$appId,
			$cursorExpiresAt,
			$this->safeWindow->maxPublishedIdReflectedAt($boundAt),
		)->encode();
		$session->snapshotTokenExpiresAt = $snapshotTokenExpiresAt;
		$session->effectiveHistoryDepthDays = $effectiveHistoryDepthDays;

		$approximateCount = $this->approximateCountProvider->countCheaply(
			$userId,
			$storages,
			DateTime::createFromTimestamp($boundTimestamp - $effectiveHistoryDepthDays * self::SECONDS_IN_DAY),
			$boundAt,
			$checkpointsByStorage,
		);
		if ($approximateCount !== null)
		{
			$session->approximateObjectCount = $approximateCount['count'];
			$session->approximateObjectCountComplete = $approximateCount['complete'];
		}

		return $session;
	}

	/**
	 * The depth the portal applies. Shortening is allowed and answering it back is not optional -
	 * a client handed less than it asked for without being told would take the shorter answer for
	 * the whole of its history.
	 */
	public function resolveEffectiveHistoryDepthDays(int $requestedDays): int
	{
		return min(max(1, $requestedDays), $this->getHistoryDepthMaxDays());
	}

	/**
	 * Both phases of the fetch are bounded by the same instant. They are two columns because the
	 * phases order by different keys - when an object changed against when the user last touched it
	 * - and not because the boundary itself differs.
	 *
	 * @param int[] $storageIds
	 * @param array<int, int[]> $checkpointsByStorage storages of other people are kept here and nowhere
	 *        else: added to the list of visible storages they would widen the search for places of every
	 *        card of the fetch, which is what the cost of collecting places rests on
	 * @throws SystemException
	 */
	private function store(
		int $userId,
		string $appId,
		DateTime $snapshotTokenExpiresAt,
		DateTime $boundAt,
		int $effectiveHistoryDepthDays,
		int $plannedPollIntervalSeconds,
		array $storageIds,
		array $checkpointsByStorage,
	): int
	{
		$result = $this->repository->replaceForPair(
			$userId,
			$appId,
			$snapshotTokenExpiresAt,
			$boundAt,
			$boundAt,
			$effectiveHistoryDepthDays,
			$plannedPollIntervalSeconds,
			$storageIds,
			$checkpointsByStorage,
		);

		if (!$result->isSuccess())
		{
			throw new SystemException(implode('; ', $result->getErrorMessages()));
		}

		// The identifier is read back rather than taken from the write: the row of a pair is
		// replaced in place, and a replacement reports no inserted identifier.
		$session = $this->repository->findByPair($userId, $appId);
		if ($session === null)
		{
			throw new SystemException('Sync session is missing right after it has been written.');
		}

		return $session['ID'];
	}

	private function getHistoryDepthMaxDays(): int
	{
		$configured = (int)Option::get(
			Driver::INTERNAL_MODULE_ID,
			self::OPTION_HISTORY_DEPTH_MAX_DAYS,
			(string)self::DEFAULT_HISTORY_DEPTH_MAX_DAYS,
		);

		return max(1, $configured);
	}

	/**
	 * A token outliving the journal would let a fetch finish at a boundary no journal record covers
	 * any more, so however the option is set, the token never outlives the retention.
	 */
	private function getSnapshotTokenTtlSeconds(): int
	{
		$configured = (int)Option::get(
			Driver::INTERNAL_MODULE_ID,
			self::OPTION_SNAPSHOT_TOKEN_TTL_DAYS,
			(string)self::DEFAULT_SNAPSHOT_TOKEN_TTL_DAYS,
		);

		return min(max(1, $configured) * self::SECONDS_IN_DAY, $this->limits->getJournalRetentionSeconds());
	}
}
