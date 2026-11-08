<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync\Position;

use Bitrix\Disk\Infrastructure\Rest\V3\Exception\CursorInvalidException;
use Bitrix\Disk\Infrastructure\Rest\V3\Exception\SnapshotTokenExpiredException;
use Bitrix\Disk\Infrastructure\Rest\V3\Exception\SyncRestException;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\Type\DateTime;

/**
 * The position of the initial fetch: the boundaries of the state both of its phases are read
 * against, the phase and the stream being read now, the place the previous page stopped at, and
 * which storages of that stream still have something to give.
 *
 * The boundaries are carried by the token rather than looked up, because a page has to be
 * repeatable: without a boundary fixed when the session was created, an object that changed
 * between two reads would move between pages in the freshness order and could fall out of the
 * fetch entirely.
 */
final class SnapshotToken extends SignedPosition
{
	/** Recently created, recently changed and recently interacted with. */
	public const PHASE_RECENT = 1;

	/** Everything else visible to the user. */
	public const PHASE_REMAINDER = 2;

	/**
	 * The objects the user has touched, ordered by when they touched them and bounded by
	 * boundInteractedAt.
	 */
	public const STREAM_INTERACTION = 1;

	/** The files, ordered by when they changed and bounded by boundModifiedAt. */
	public const STREAM_FILES = 2;

	/**
	 * The files under the checkpoints of visibility the user holds in storages of other people,
	 * ordered and bounded exactly as the stream above.
	 *
	 * A folder shared with the user lies in the personal storage of whoever shared it, and the root of
	 * that storage is not readable by them, so the storages of the session do not carry it and nothing
	 * shared reaches the fetch through them. The unit this stream merges by is such a storage of
	 * another person, walked not whole but by the subtrees of the checkpoints the session fixed.
	 */
	public const STREAM_SHARED = 3;

	/**
	 * @param int[]|null $exhaustedStorageIds the storages of the stream being read already read to
	 *        their end, null when the position says nothing about exhaustion
	 * @param int[]|null $remainingStorageIds the same fact in its complementary form, carried instead
	 *        of the list above when it is the shorter of the two; never both at once
	 */
	private function __construct(
		int $userId,
		string $appId,
		DateTime $expiresAt,
		private readonly DateTime $boundModifiedAt,
		private readonly DateTime $boundInteractedAt,
		private readonly int $phase,
		private readonly ?int $stream,
		private readonly ?DateTime $lastFreshnessAt,
		private readonly ?int $lastObjectId,
		private readonly ?array $exhaustedStorageIds,
		private readonly ?array $remainingStorageIds,
	)
	{
		parent::__construct($userId, $appId, $expiresAt);
	}

	/**
	 * The token a freshly created session hands out: the first phase, no page read yet.
	 */
	public static function start(
		int $userId,
		string $appId,
		DateTime $expiresAt,
		DateTime $boundModifiedAt,
		DateTime $boundInteractedAt,
	): self
	{
		return new self(
			$userId,
			$appId,
			$expiresAt,
			$boundModifiedAt,
			$boundInteractedAt,
			self::PHASE_RECENT,
			null,
			null,
			null,
			null,
			null,
		);
	}

	/**
	 * The token of the next page of the stream being read now.
	 */
	public function withPosition(DateTime $lastFreshnessAt, int $lastObjectId): self
	{
		return new self(
			$this->getUserId(),
			$this->getAppId(),
			$this->getExpiresAt(),
			$this->boundModifiedAt,
			$this->boundInteractedAt,
			$this->phase,
			$this->stream,
			$lastFreshnessAt,
			$lastObjectId,
			$this->exhaustedStorageIds,
			$this->remainingStorageIds,
		);
	}

	/**
	 * The token of the first page of the next phase: a phase starts its own traversal, so the stream
	 * and the position of the finished one are dropped.
	 */
	public function withPhase(int $phase): self
	{
		if (!in_array($phase, self::getPhases(), true))
		{
			throw new ArgumentOutOfRangeException('phase', self::getPhases());
		}

		return new self(
			$this->getUserId(),
			$this->getAppId(),
			$this->getExpiresAt(),
			$this->boundModifiedAt,
			$this->boundInteractedAt,
			$phase,
			null,
			null,
			null,
			null,
			null,
		);
	}

	/**
	 * The token of the first page of the next stream of the same phase. A stream carries its own key
	 * and its own boundary, so the position of the finished one means nothing to it.
	 */
	public function withStream(int $stream): self
	{
		if (!in_array($stream, self::getStreamsOfPhase($this->phase), true))
		{
			throw new ArgumentOutOfRangeException('stream', self::getStreamsOfPhase($this->phase));
		}

		return new self(
			$this->getUserId(),
			$this->getAppId(),
			$this->getExpiresAt(),
			$this->boundModifiedAt,
			$this->boundInteractedAt,
			$this->phase,
			$stream,
			null,
			null,
			null,
			null,
		);
	}

	/**
	 * @return int[]
	 */
	public static function getPhases(): array
	{
		return [self::PHASE_RECENT, self::PHASE_REMAINDER];
	}

	public function getBoundModifiedAt(): DateTime
	{
		return $this->boundModifiedAt;
	}

	public function getBoundInteractedAt(): DateTime
	{
		return $this->boundInteractedAt;
	}

	/**
	 * The streams a phase is read as, in the order they are read.
	 *
	 * The first phase carries the interaction set as a stream of its own: freshness is measured by
	 * when the object changed, interaction by when the user touched it, and under a single key a file
	 * opened yesterday and changed three years ago would travel to the tail of the fetch - the object
	 * personal memory needs most would arrive last.
	 *
	 * What is shared is read in both phases, because its key is the key of the files and both windows
	 * apply to it unchanged. It follows the files rather than leading them: it is a tail of the
	 * traversal, and a position issued before it existed goes on being read as standing at the first
	 * stream of its phase.
	 *
	 * @return int[]
	 */
	public static function getStreamsOfPhase(int $phase): array
	{
		return $phase === self::PHASE_RECENT
			? [self::STREAM_INTERACTION, self::STREAM_FILES, self::STREAM_SHARED]
			: [self::STREAM_FILES, self::STREAM_SHARED];
	}

	public function getPhase(): int
	{
		return $this->phase;
	}

	/**
	 * The stream of the phase being read now.
	 *
	 * Absent in a token issued before the field existed, and that is what the default is for: a
	 * position already handed out has to keep working, so it is read as standing at the first stream
	 * of its phase.
	 */
	public function getStream(): int
	{
		return $this->stream ?? self::getStreamsOfPhase($this->phase)[0];
	}

	/**
	 * The freshness of the last object of the previous page, null on the first page of a phase.
	 */
	public function getLastFreshnessAt(): ?DateTime
	{
		return $this->lastFreshnessAt;
	}

	/**
	 * The id of the last object of the previous page, null on the first page of a phase.
	 */
	public function getLastObjectId(): ?int
	{
		return $this->lastObjectId;
	}

	/**
	 * The storages the stream being read still has to ask.
	 *
	 * A storage whose stream came back empty at a position of this traversal has nothing below that
	 * position either - the key is common to every stream of the phase and moves one way - so it is
	 * left out of the reads that follow instead of being asked again on every page.
	 *
	 * The unit is the same whichever stream is read, a storage, so the accounting serves them all;
	 * which storages they are is not - the streams of the session against the storages it fixed, the
	 * stream of what is shared against the storages of other people it holds checkpoints in. Nothing
	 * of one stream reaches another: a stream that begins drops the position and this list with it.
	 *
	 * @param int[] $storageIds the units the stream being read merges by
	 * @return int[] in the order they were given in
	 */
	public function getStoragesToRead(array $storageIds): array
	{
		if ($this->exhaustedStorageIds !== null)
		{
			return array_values(array_diff($storageIds, $this->exhaustedStorageIds));
		}

		if ($this->remainingStorageIds !== null)
		{
			return array_values(array_intersect($storageIds, $this->remainingStorageIds));
		}

		// A position issued before the field existed carries no exhaustion, and nothing exhausted is
		// what it has to mean: asking a storage that has nothing left costs a query, while skipping
		// one that still has rows would lose them.
		return array_values($storageIds);
	}

	/**
	 * The same position, remembering which storages are still worth asking.
	 *
	 * The shorter of the two lists is what travels - the exhausted storages or the ones left - because
	 * the number of storages of a session is bounded by nothing: a member of hundreds of workgroups
	 * sees hundreds of storages, and the position goes out and comes back with every page of the fetch.
	 *
	 * @param int[] $storageIds the units the stream being read merges by
	 * @param int[] $storagesToRead
	 */
	public function withStoragesToRead(array $storageIds, array $storagesToRead): self
	{
		$remaining = array_values(array_intersect($storageIds, $storagesToRead));
		$exhausted = array_values(array_diff($storageIds, $remaining));
		$carryExhausted = count($exhausted) <= count($remaining);

		return new self(
			$this->getUserId(),
			$this->getAppId(),
			$this->getExpiresAt(),
			$this->boundModifiedAt,
			$this->boundInteractedAt,
			$this->phase,
			$this->stream,
			$this->lastFreshnessAt,
			$this->lastObjectId,
			$carryExhausted ? $exhausted : null,
			$carryExhausted ? null : $remaining,
		);
	}

	public static function getKind(): string
	{
		return self::KIND_SNAPSHOT;
	}

	protected static function createExpiredException(): SyncRestException
	{
		return new SnapshotTokenExpiredException();
	}

	protected function getPayload(): array
	{
		$payload = [
			'boundModifiedAt' => self::encodeTime($this->boundModifiedAt),
			'boundInteractedAt' => self::encodeTime($this->boundInteractedAt),
			'phase' => $this->phase,
			'stream' => $this->stream,
			'lastFreshnessAt' => $this->lastFreshnessAt === null ? null : self::encodeTime($this->lastFreshnessAt),
			'lastObjectId' => $this->lastObjectId,
		];

		// Only one of the two forms is ever carried, and the absent one is left out of the payload
		// rather than written as null: the ids are packed as one string for the same reason.
		if ($this->exhaustedStorageIds !== null)
		{
			$payload['exhaustedStorages'] = self::packIds($this->exhaustedStorageIds);
		}
		elseif ($this->remainingStorageIds !== null)
		{
			$payload['remainingStorages'] = self::packIds($this->remainingStorageIds);
		}

		return $payload;
	}

	protected static function createFromPayload(
		int $userId,
		string $appId,
		DateTime $expiresAt,
		array $payload,
	): static
	{
		$phase = self::decodeInt($payload['phase'] ?? null);
		if (!in_array($phase, self::getPhases(), true))
		{
			throw new CursorInvalidException();
		}

		$stream = self::decodeOptionalInt($payload['stream'] ?? null);
		if ($stream !== null && !in_array($stream, self::getStreamsOfPhase($phase), true))
		{
			throw new CursorInvalidException();
		}

		$exhaustedStorageIds = self::unpackIds($payload['exhaustedStorages'] ?? null);
		$remainingStorageIds = self::unpackIds($payload['remainingStorages'] ?? null);
		if ($exhaustedStorageIds !== null && $remainingStorageIds !== null)
		{
			throw new CursorInvalidException();
		}

		return new self(
			$userId,
			$appId,
			$expiresAt,
			self::decodeTime($payload['boundModifiedAt'] ?? null),
			self::decodeTime($payload['boundInteractedAt'] ?? null),
			$phase,
			$stream,
			self::decodeOptionalTime($payload['lastFreshnessAt'] ?? null),
			self::decodeOptionalInt($payload['lastObjectId'] ?? null),
			$exhaustedStorageIds,
			$remainingStorageIds,
		);
	}

	/**
	 * @param int[] $ids
	 */
	private static function packIds(array $ids): string
	{
		return implode(',', $ids);
	}

	/**
	 * @return int[]|null
	 */
	private static function unpackIds(mixed $value): ?array
	{
		if ($value === null)
		{
			return null;
		}

		if (!is_string($value))
		{
			throw new CursorInvalidException();
		}

		if ($value === '')
		{
			return [];
		}

		$ids = [];
		foreach (explode(',', $value) as $id)
		{
			if (preg_match('/^[0-9]+$/D', $id) !== 1)
			{
				throw new CursorInvalidException();
			}

			$ids[] = (int)$id;
		}

		return $ids;
	}
}
