<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync\Snapshot;

use Bitrix\Disk\Infrastructure\Rest\V3\Exception\SnapshotTokenExpiredException;
use Bitrix\Disk\Infrastructure\Rest\V3\Structure\Sync\SectionsStructure;
use Bitrix\Disk\Internal\Repository\Sync\SyncSessionRepository;
use Bitrix\Disk\Internal\Service\Sync\Position\SnapshotToken;
use Bitrix\Disk\Internal\Service\Sync\SyncLimits;
use Bitrix\Disk\Internal\Service\Sync\Visibility\AccessSourceConfirmer;
use Bitrix\Disk\Internal\Service\Sync\Visibility\InteractionStream;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;

/**
 * One page of the initial fetch (ALG-03).
 *
 * The fetch runs as two phases, and each phase as several streams. The split is not an
 * optimisation: the groups are ordered by different keys - freshness by when the object changed,
 * interaction by when the user last touched it - and under a single key a file opened yesterday and
 * changed three years ago would travel to the tail of the fetch. Streams of unlike keys are
 * therefore never merged; each is read in its own order, against its own boundary of state, and the
 * token carries the phase, the stream and the place inside it.
 *
 * What is shared is a stream of the same key as the files and of other units: the storages of other
 * people the user holds checkpoints of visibility in, walked by the subtrees of those checkpoints.
 * Without it the fetch would answer by where a file lies rather than by what the user may read, and
 * everything shared with them would be left to the journal alone.
 *
 * Two things the size of a page must not be read as. It is not the end of the data: rows are lost
 * to the collapsing of links and to the confirmation of the interaction set, so a page is refilled
 * from the rows behind it, and whether the fetch goes on is decided by a read that came back empty
 * rather than by a page that came back short. And it is not the number of rows read: a page is
 * counted in real objects, which is why links are collapsed on the fetch and not after it - the
 * collapsing of an already ordered page would break its size.
 */
final class SnapshotService
{
	/**
	 * How many reads of the streams one page may cost.
	 *
	 * The refill has to stop somewhere: a stream whose every row is dropped by the collapsing or by
	 * the confirmation would otherwise be walked to its end by a single request.
	 */
	private const MAX_PAGE_READS = 5;

	/**
	 * The width of the fresh sub-window of the first phase, in days.
	 *
	 * It divides "recently changed" from "everything else" and nothing more: it is not published, no
	 * client plans against it, and it is not one of the levers the volume of a fetch is brought down
	 * by. Making it wider moves work from the second phase into the first, not into or out of the
	 * fetch as a whole - what the fetch contains is decided by the effective depth of history.
	 */
	public const FRESH_WINDOW_DAYS = 30;

	/**
	 * The sections whose assembly is expensive enough to halve the page. Places cost a walk of the
	 * closure table and a search for links whose result is not bounded by the size of the page at
	 * all - it grows with the number of people a file is shared with.
	 */
	private const HEAVY_SECTIONS = [SectionsStructure::SECTION_LOCATION];

	private const SECONDS_IN_DAY = 86400;

	private SyncSessionRepository $sessions;
	private SyncLimits $limits;
	private SkipRightsProbe $skipRightsProbe;
	private StorageStreamMerger $merger;
	private AccessSourceConfirmerInterface $accessSourceConfirmer;
	private InteractionStream $interactionStream;

	public function __construct(
		?SyncSessionRepository $sessions = null,
		?SyncLimits $limits = null,
		?SkipRightsProbe $skipRightsProbe = null,
		?StorageStreamMerger $merger = null,
		?AccessSourceConfirmerInterface $accessSourceConfirmer = null,
		?InteractionStream $interactionStream = null,
	)
	{
		$this->sessions = $sessions ?? new SyncSessionRepository();
		$this->limits = $limits ?? new SyncLimits();
		$this->skipRightsProbe = $skipRightsProbe ?? new SkipRightsProbe();
		$this->merger = $merger ?? new StorageStreamMerger();
		$this->accessSourceConfirmer = $accessSourceConfirmer ?? new AccessSourceConfirmer();
		$this->interactionStream = $interactionStream ?? new InteractionStream();
	}

	/**
	 * The identifiers of one page and the token that continues the fetch.
	 *
	 * Real objects and positions, not cards: assembling a card is a task of its own, and the folders
	 * of the page are collected from these very identifiers by the collector of ancestor folders -
	 * they come by kinship rather than by their own freshness, and they are not counted into the
	 * size of the page.
	 *
	 * @param int $limit what the caller asked for; what is served is capped by the ceiling of the
	 *        requested sections
	 * @return array{
	 *         objectIds: int[],
	 *         hasMore: bool,
	 *         token: SnapshotToken,
	 *         storageIds: int[],
	 *         checkpointIds: int[]
	 *         }
	 *         storageIds are the storages the session fixed: the caller describes the page against
	 *         the same set the page was read from, and asking visibility again would cost a pass over
	 *         the storages of the whole portal per page. The storages of other people the stream of
	 *         what is shared walks are not among them and must not be added: the search for links of
	 *         the places of a card is bounded by this set, and that bound is what its cost rests on.
	 *         checkpointIds are the checkpoints the fetch hands over with this page, for the caller to
	 *         pass to the collector of the folders of the page; empty on every page but the first of
	 *         the stream of what is shared
	 * @throws SnapshotTokenExpiredException the token belongs to a session that is no longer live
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function page(SnapshotToken $token, int $limit, SectionsStructure $sections): array
	{
		$session = $this->readLiveSession($token);
		$limit = $this->resolveLimit($limit, $sections);

		$userId = $token->getUserId();
		$stream = $token->getStream();
		$storageIds = $session['STORAGE_IDS'];

		// The unit of every stream is a storage, which is what lets one merge serve them all; which
		// storages they are is what the stream decides. What is shared is merged by the storages of
		// other people the session holds checkpoints in, and those are no part of the visible set.
		$unitStorageIds = $stream === SnapshotToken::STREAM_SHARED
			? array_keys($session['CHECKPOINTS_BY_STORAGE'])
			: $storageIds
		;

		// The checkpoints themselves travel with the first page of the stream that walks them, and
		// there alone. The streams carry files and the folders of a page are the ancestors of those
		// files, so a folder shared with the reader holding nothing they may read is nobody's ancestor
		// and would never reach them - which is the very complaint the stream was added for. Nothing
		// is read for it: the checkpoints lie in the row of the session, and there are tens of them.
		$checkpointIds = $stream === SnapshotToken::STREAM_SHARED && $token->getLastObjectId() === null
			? self::flatten($session['CHECKPOINTS_BY_STORAGE'])
			: []
		;

		// The storages already read to their end are not asked again: at hundreds of storages per
		// session the empty ones were the greater part of the cost of a page.
		$storagesToRead = $token->getStoragesToRead($unitStorageIds);

		// Which storages the page is read from is what the session settled; whether the rights check may
		// be dropped for one of them is decided page by page and never carried in the token. The list
		// outlives the access behind it - a member taken out of a workgroup keeps the storage of that
		// group on their list until the token expires - so an answer frozen once would let the fetch be
		// read to its end after the access was gone.
		//
		// The stream of what is shared is not asked about at all: the promise rests on the reader being
		// able to read the root of the storage, and the root of a storage of another person is by
		// definition the one thing they cannot read. The answer would be empty at the cost of a query.
		$storageIdsWithoutRightsCheck = $stream === SnapshotToken::STREAM_SHARED
			? []
			: $this->skipRightsProbe->findStoragesWithoutRightsCheck(
				$userId,
				$this->skipRightsProbe->findReadableRootObjects($userId, $storagesToRead),
			)
		;

		$overInteractionSet = $stream === SnapshotToken::STREAM_INTERACTION;
		$objectQuery = $overInteractionSet ? null : $this->createObjectQuery($token, $session);

		$afterFreshnessAt = $token->getLastFreshnessAt();
		$afterObjectId = $token->getLastObjectId();

		$collected = [];
		$collapsedRealObjectIds = [];
		$streamIsOver = false;
		$reads = 0;

		while (count($collected) < $limit && $reads < self::MAX_PAGE_READS)
		{
			$reads++;

			if ($objectQuery !== null)
			{
				$read = $this->merger->read(
					$objectQuery,
					$storagesToRead,
					$storageIdsWithoutRightsCheck,
					$afterFreshnessAt,
					$afterObjectId,
					$limit + 1,
				);

				$rows = $read['rows'];
				$storagesToRead = self::without($storagesToRead, $read['exhaustedStorageIds']);
			}
			else
			{
				$rows = $this->interactionStream->read(
					$userId,
					$session['BOUND_INTERACTED_AT'],
					$afterFreshnessAt,
					$afterObjectId,
					$limit + 1,
				);
			}

			if ($rows === [])
			{
				$streamIsOver = true;

				break;
			}

			$lastRead = $rows[array_key_last($rows)];
			$afterFreshnessAt = $lastRead['freshnessAt'];
			$afterObjectId = $lastRead['objectId'];

			if ($overInteractionSet)
			{
				$rows = $this->confirmInteractionSource($rows, $userId);
			}
			$rows = $this->collapseToRealObjects($rows, $collapsedRealObjectIds);

			foreach ($rows as $row)
			{
				$collected[] = $row;
			}
		}

		$items = array_slice($collected, 0, $limit);

		// What was read beyond the page is left for the next one, so the position stops at the last
		// row actually handed over. Advancing past rows the client never saw would lose them.
		if (count($collected) > $limit && $items !== [])
		{
			$boundary = $items[array_key_last($items)];
			$afterFreshnessAt = $boundary['freshnessAt'];
			$afterObjectId = $boundary['objectId'];
		}

		// What is exhausted belongs to the stream being read: a stream or a phase that starts is read
		// against a key of its own, and the tokens of those beginnings carry nothing about storages.
		[$nextToken, $hasMore] = $streamIsOver
			? $this->advance($token)
			: [
				$this->keepPosition($token, $afterFreshnessAt, $afterObjectId)
					->withStoragesToRead($unitStorageIds, $storagesToRead)
				,
				true,
			];

		return [
			'objectIds' => array_map(static fn(array $row): int => $row['realObjectId'], $items),
			'hasMore' => $hasMore,
			'token' => $nextToken,
			'storageIds' => $storageIds,
			'checkpointIds' => $checkpointIds,
		];
	}

	/**
	 * A signed token is decoded until its own expiry whatever happens to the session, while a session
	 * is annulled by the row of the pair being written over. So a token is only accepted while its
	 * expiry and both boundaries still match the live row: a token of a replaced session would
	 * otherwise go on serving pages of a fetch that no longer exists, silently and without an error.
	 *
	 * @return array{
	 *     ID: int,
	 *     USER_ID: int,
	 *     APP_ID: string,
	 *     CREATED_AT: DateTime,
	 *     SNAPSHOT_TOKEN_EXPIRES_AT: DateTime,
	 *     BOUND_MODIFIED_AT: DateTime,
	 *     BOUND_INTERACTED_AT: DateTime,
	 *     EFFECTIVE_DEPTH_DAYS: int,
	 *     PLANNED_POLL_INTERVAL: int,
	 *     STORAGE_IDS: int[],
	 *     CHECKPOINTS_BY_STORAGE: array<int, int[]>
	 * }
	 * @throws SnapshotTokenExpiredException
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function readLiveSession(SnapshotToken $token): array
	{
		$session = $this->sessions->findByPair($token->getUserId(), $token->getAppId());

		if (
			$session === null
			|| !self::sameInstant($session['SNAPSHOT_TOKEN_EXPIRES_AT'], $token->getExpiresAt())
			|| !self::sameInstant($session['BOUND_MODIFIED_AT'], $token->getBoundModifiedAt())
			|| !self::sameInstant($session['BOUND_INTERACTED_AT'], $token->getBoundInteractedAt())
		)
		{
			throw new SnapshotTokenExpiredException();
		}

		return $session;
	}

	private static function sameInstant(mixed $stored, DateTime $carried): bool
	{
		return $stored instanceof DateTime && $stored->getTimestamp() === $carried->getTimestamp();
	}

	/**
	 * The read of the files of the phase being served.
	 *
	 * The stream of what is shared takes the window of its phase unchanged and adds the checkpoints
	 * the walk inside every storage of another person is narrowed to. Its key is the key of the files,
	 * so both windows apply to it as they stand and the merge tells the two apart in nothing.
	 *
	 * @param array<string, mixed> $session
	 */
	private function createObjectQuery(SnapshotToken $token, array $session): PhaseQuery
	{
		$userId = $token->getUserId();
		[$modifiedTo, $modifiedFrom] = self::windowOfPhase($token->getPhase(), $session);

		return $token->getStream() === SnapshotToken::STREAM_SHARED
			? PhaseQuery::overSubtreesOfCheckpoints(
				$userId,
				$modifiedTo,
				$modifiedFrom,
				$session['CHECKPOINTS_BY_STORAGE'],
			)
			: PhaseQuery::overObjects($userId, $modifiedTo, $modifiedFrom)
		;
	}

	/**
	 * The window of the modification date the given phase is read in.
	 *
	 * @param array<string, mixed> $session
	 * @return array{0: DateTime, 1: DateTime} the upper bound and the lower one
	 */
	private static function windowOfPhase(int $phase, array $session): array
	{
		$modifiedTo = $session['BOUND_MODIFIED_AT'];
		$depthFrom = self::shiftBack($modifiedTo, $session['EFFECTIVE_DEPTH_DAYS'] * self::SECONDS_IN_DAY);
		$freshFrom = self::shiftBack($modifiedTo, self::FRESH_WINDOW_DAYS * self::SECONDS_IN_DAY);

		if ($phase === SnapshotToken::PHASE_RECENT)
		{
			// The depth of history bounds the files of both phases. A session whose depth is shorter
			// than the fresh sub-window would otherwise hand over exactly what it answered it would
			// leave out, and the remainder would come back empty for the wrong reason.
			return [$modifiedTo, self::theLaterOf($freshFrom, $depthFrom)];
		}

		// The remainder starts one second below the fresh sub-window: the column keeps whole seconds,
		// so the two windows meet without a gap between them and without an overlap. A depth shorter
		// than the sub-window leaves the remainder with an empty range, which is what it should be.
		return [self::shiftBack($freshFrom, 1), $depthFrom];
	}

	private static function shiftBack(DateTime $from, int $seconds): DateTime
	{
		return DateTime::createFromTimestamp($from->getTimestamp() - $seconds);
	}

	private static function theLaterOf(DateTime $first, DateTime $second): DateTime
	{
		return $first->getTimestamp() >= $second->getTimestamp() ? $first : $second;
	}

	/**
	 * A link and the object it points at are one row of the page. The first row of a group keeps the
	 * position of the group: moving the position to the last one would step over rows of other
	 * objects lying between them.
	 *
	 * Which of the rows of a group is the first one is decided after the source of access behind them
	 * is confirmed, never before. A row whose source is gone stands for nothing, and kept as the first
	 * of its group it would silence the rows behind it: the object would leave the fetch although the
	 * reader still sees it through the link the next row carries.
	 *
	 * @param array<int, array<string, mixed>> $rows
	 * @param array<int, true> $collapsedRealObjectIds real object ids already taken into this page
	 * @return array<int, array<string, mixed>>
	 */
	private function collapseToRealObjects(array $rows, array &$collapsedRealObjectIds): array
	{
		$collapsed = [];
		foreach ($rows as $row)
		{
			$realObjectId = $row['realObjectId'];
			if (isset($collapsedRealObjectIds[$realObjectId]))
			{
				continue;
			}

			$collapsedRealObjectIds[$realObjectId] = true;
			$collapsed[] = $row;
		}

		return $collapsed;
	}

	/**
	 * ALG-05. Presence in the interaction set is not visibility by itself: the access a row was
	 * written for may have been revoked since, so the source of access behind every row of the page
	 * is confirmed before the row becomes an object of the fetch.
	 *
	 * @param array<int, array<string, mixed>> $rows
	 * @return array<int, array<string, mixed>>
	 */
	private function confirmInteractionSource(array $rows, int $userId): array
	{
		return $this->accessSourceConfirmer->confirm($rows, $userId);
	}

	/**
	 * @param array<int, int[]> $checkpointsByStorage
	 * @return int[]
	 */
	private static function flatten(array $checkpointsByStorage): array
	{
		return array_merge(...array_values($checkpointsByStorage));
	}

	/**
	 * @param int[] $storageIds
	 * @param int[] $exhaustedStorageIds
	 * @return int[]
	 */
	private static function without(array $storageIds, array $exhaustedStorageIds): array
	{
		if ($exhaustedStorageIds === [])
		{
			return $storageIds;
		}

		return array_values(array_diff($storageIds, $exhaustedStorageIds));
	}

	private function keepPosition(SnapshotToken $token, ?DateTime $freshnessAt, ?int $objectId): SnapshotToken
	{
		if ($freshnessAt === null || $objectId === null)
		{
			return $token;
		}

		return $token->withPosition($freshnessAt, $objectId);
	}

	/**
	 * The stream that came back empty is over. What follows it is the next stream of the same phase,
	 * then the next phase; after the last one the fetch is done and only then is there nothing more.
	 *
	 * @return array{0: SnapshotToken, 1: bool}
	 */
	private function advance(SnapshotToken $token): array
	{
		$streams = SnapshotToken::getStreamsOfPhase($token->getPhase());
		$current = array_search($token->getStream(), $streams, true);
		$next = $current === false ? null : ($streams[$current + 1] ?? null);

		if ($next !== null)
		{
			return [$token->withStream($next), true];
		}

		if ($token->getPhase() === SnapshotToken::PHASE_RECENT)
		{
			return [$token->withPhase(SnapshotToken::PHASE_REMAINDER), true];
		}

		return [$token, false];
	}

	/**
	 * The ceiling of a page depends on the cost of the sections that were asked for: the light one is
	 * what the portal publishes as its maximum, the heavy one half of it.
	 */
	private function resolveLimit(int $requested, SectionsStructure $sections): int
	{
		$ceiling = $this->hasHeavySection($sections)
			? $this->limits->getPageSizeMaxHeavy()
			: $this->limits->getPageSizeMax();

		return max(1, min($requested, $ceiling));
	}

	private function hasHeavySection(SectionsStructure $sections): bool
	{
		foreach (self::HEAVY_SECTIONS as $section)
		{
			if ($sections->has($section))
			{
				return true;
			}
		}

		return false;
	}
}
