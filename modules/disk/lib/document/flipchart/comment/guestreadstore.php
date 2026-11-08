<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Flipchart\Comment;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Data\Storage\PersistentStorageInterface;

/**
 * Where a guest keeps what they have read.
 *
 * A guest has no row of their own anywhere: one document per board and guest holds the boundaries of
 * every thread they marked and the stamp of "everything read", and the whole document is rewritten
 * as a unit. Its key is derived only from the board and the guest of the verified token, hashed, so
 * it names nothing about them and cannot be enumerated; the state of one guest cannot be reached
 * from another token.
 *
 * Every write pushes the lifetime of the document to thirty days ahead, which is the upper bound of
 * this storage and not a promise: the identifier of a guest lives no longer than their session, so
 * the same visitor may well come back to an empty state long before it expires. Nothing here takes
 * part in the cascade of a deleted board for the same reason: the keys cannot be listed, and they go
 * away by themselves.
 */
final class GuestReadStore
{
	public const TTL = 2592000;

	private const KEY_PREFIX = 'disk.flipchart.comment.read.';
	private const FIELD_THREADS = 'threads';
	private const FIELD_READ_ALL = 'readAll';

	private readonly PersistentStorageInterface $storage;

	public function __construct(?PersistentStorageInterface $storage = null)
	{
		$this->storage = $storage ?? ServiceLocator::getInstance()->get(PersistentStorageInterface::class);
	}

	/**
	 * @param list<string> $threadIds
	 * @return array<string, int> The boundary of every thread that has one.
	 */
	public function getBoundaries(int $objectId, string $guestUserId, array $threadIds): array
	{
		$stored = $this->read($objectId, $guestUserId)[self::FIELD_THREADS];

		return array_intersect_key($stored, array_flip($threadIds));
	}

	public function getReadAllStamp(int $objectId, string $guestUserId): ?int
	{
		return $this->read($objectId, $guestUserId)[self::FIELD_READ_ALL];
	}

	/**
	 * @param bool $advanceOnly Whether a boundary already past the new one is left where it is: a
	 *     mark of everything read never takes a thread back, an addressed one is the position asked.
	 */
	public function setBoundary(
		int $objectId,
		string $guestUserId,
		string $threadId,
		int $boundary,
		bool $advanceOnly,
	): void
	{
		$document = $this->read($objectId, $guestUserId);
		$stored = $document[self::FIELD_THREADS][$threadId] ?? null;
		if ($advanceOnly && $stored !== null && $stored >= $boundary)
		{
			return;
		}

		$document[self::FIELD_THREADS][$threadId] = $boundary;
		$this->write($objectId, $guestUserId, $document);
	}

	/**
	 * Takes the boundary of one thread away and, with it, the mark of everything read: the board is
	 * not read through any more.
	 */
	public function removeBoundary(int $objectId, string $guestUserId, string $threadId): void
	{
		$document = $this->read($objectId, $guestUserId);
		unset($document[self::FIELD_THREADS][$threadId]);
		$document[self::FIELD_READ_ALL] = null;

		$this->write($objectId, $guestUserId, $document);
	}

	/**
	 * @param array<string, int> $boundaries The boundary of every thread that has a live comment.
	 * @param int $stamp The last comment row of the board at the moment of the mark.
	 */
	public function markAllRead(int $objectId, string $guestUserId, array $boundaries, int $stamp): void
	{
		$document = $this->read($objectId, $guestUserId);
		foreach ($boundaries as $threadId => $boundary)
		{
			$stored = $document[self::FIELD_THREADS][$threadId] ?? null;
			if ($stored === null || $stored < $boundary)
			{
				$document[self::FIELD_THREADS][$threadId] = $boundary;
			}
		}
		$document[self::FIELD_READ_ALL] = $stamp;

		$this->write($objectId, $guestUserId, $document);
	}

	/**
	 * @return array{threads: array<string, int>, readAll: ?int}
	 */
	private function read(int $objectId, string $guestUserId): array
	{
		$stored = $this->storage->get(self::key($objectId, $guestUserId));
		$threads = [];
		foreach (is_array($stored[self::FIELD_THREADS] ?? null) ? $stored[self::FIELD_THREADS] : [] as $threadId => $boundary)
		{
			if (is_string($threadId) && is_numeric($boundary))
			{
				$threads[$threadId] = (int)$boundary;
			}
		}

		$readAll = $stored[self::FIELD_READ_ALL] ?? null;

		return [
			self::FIELD_THREADS => $threads,
			self::FIELD_READ_ALL => is_numeric($readAll) ? (int)$readAll : null,
		];
	}

	/**
	 * @param array{threads: array<string, int>, readAll: ?int} $document
	 */
	private function write(int $objectId, string $guestUserId, array $document): void
	{
		$this->storage->set(self::key($objectId, $guestUserId), $document, self::TTL);
	}

	/**
	 * Only what the token proved goes into the key, and only as a hash: neither the board nor the
	 * guest can be read back out of it.
	 */
	private static function key(int $objectId, string $guestUserId): string
	{
		return self::KEY_PREFIX . hash('sha256', $objectId . "\0" . $guestUserId);
	}
}
