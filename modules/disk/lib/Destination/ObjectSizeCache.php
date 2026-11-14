<?php

declare(strict_types=1);

namespace Bitrix\Disk\Destination;

use Bitrix\Main\Data\ManagedCache;

/**
 * How much the object of an operation weighs, either counted every time or kept between the requests of one
 * popup.
 *
 * The size of a folder is an aggregate over its whole subtree (@see \Bitrix\Disk\Folder::getSize), and it is
 * the same number for every level a popup opens: the object being moved or copied does not change while a
 * destination for it is being chosen. So the reader of a level counts it once and reads it back after that.
 *
 * Which of the two a caller gets is the caller's own decision, and it follows from what its verdict is for.
 * A verdict of a level is a hint the client draws with, and a stale hint costs a row drawn as allowed that
 * the operation then refuses. A verdict of the operation itself is the permission to write, and below it the
 * object model weighs no free space of its own: copyTo() clones the files and asks nobody
 * (@see \Bitrix\Disk\File::copyTo). An answer kept for a minute would let a folder filled up within that
 * minute through, so everything that permits a write counts anew (@see countingEveryTime).
 *
 * Nothing invalidates a kept answer, on purpose: the size of a subtree changes with any file written
 * anywhere inside it, and an event for that would cost more than the aggregate it saves. The lifetime is the
 * only way such an answer goes stale, and it is short for that reason.
 */
class ObjectSizeCache
{
	/** Longer than the levels of one popup take, shorter than the popup itself is likely to stay open. */
	private const TTL = 60;

	private const CACHE_SCOPE = 'disk_destination_object_size';

	/**
	 * Holding the cache of the portal is what tells the two halves apart: the half that keeps its answers is
	 * given one by whoever composes the reading, and the half that counts every time has none to reach for.
	 */
	protected function __construct(
		private readonly ?ManagedCache $managedCache,
	)
	{
	}

	/**
	 * For the reader of a level: the aggregate is counted on the first level of a popup and read back on
	 * every level after it. The cache of the portal comes from the caller, down from the action that opened
	 * the level.
	 */
	public static function keepingAnswers(ManagedCache $managedCache): static
	{
		return new static($managedCache);
	}

	/**
	 * For every caller whose verdict permits a write. The aggregate is counted anew each time and nothing is
	 * kept: this is the only place free space is weighed on that path. Nothing of the portal is asked for
	 * either - the path that applies an operation is not to pay even for reaching the cache of one.
	 */
	public static function countingEveryTime(): static
	{
		return new static(null);
	}

	/**
	 * The size of the object with the given id, counted by the fallback when there is no answer to give.
	 *
	 * Zero is an answer like any other here: a folder whose subtree holds no file weighs nothing, and
	 * counting that again on every level is exactly what a kept answer is here to stop.
	 *
	 * @param callable(): int $fallback
	 */
	public function get(int $objectId, callable $fallback): int
	{
		$managedCache = $this->managedCache;
		if ($managedCache === null)
		{
			return $fallback();
		}

		$key = $this->buildKey($objectId);

		if ($managedCache->read(self::TTL, $key, $this->buildDir($objectId)))
		{
			return (int)$managedCache->get($key);
		}

		$size = $fallback();
		$managedCache->set($key, $size);

		return $size;
	}

	/**
	 * The sizes of the objects with the given ids, by id. The ones there is no answer for are counted by the
	 * fallback at once, so a selection pays one count for all of them rather than one per object.
	 *
	 * @param int[] $objectIds
	 * @param callable(int[]): array<int, int> $fallback sizes by id for the ids given to it
	 * @return array<int, int>
	 */
	public function getMany(array $objectIds, callable $fallback): array
	{
		$managedCache = $this->managedCache;
		$sizes = [];
		$missingIds = [];
		foreach ($objectIds as $objectId)
		{
			$key = $this->buildKey($objectId);
			if ($managedCache?->read(self::TTL, $key, $this->buildDir($objectId)))
			{
				$sizes[$objectId] = (int)$managedCache->get($key);
			}
			else
			{
				$missingIds[] = $objectId;
			}
		}

		if (empty($missingIds))
		{
			return $sizes;
		}

		$counted = $fallback($missingIds);
		foreach ($missingIds as $objectId)
		{
			//an object the count found no file for weighs nothing, and that is an answer like any other
			$size = (int)($counted[$objectId] ?? 0);
			$managedCache?->set($this->buildKey($objectId), $size);
			$sizes[$objectId] = $size;
		}

		return $sizes;
	}

	/**
	 * The id of the object of the operation, not of the folder a link points at: the size of a link is
	 * counted against the storage of the link itself (@see \Bitrix\Disk\Folder::getSize), so a key of the
	 * real object would answer with a number that belongs to somebody else.
	 *
	 * Nothing of the user goes into the key either - how much an object weighs is a property of the object.
	 *
	 * The scope is a part of the key and not of the directory alone: within one request the managed cache
	 * knows its entries by the key by itself (@see ManagedCache::read), and the directory tells apart only
	 * the files it leaves between requests. A key as common as a size by an id would meet somebody else's
	 * one day, and the answer of that meeting would be a number of the wrong thing.
	 */
	private function buildKey(int $objectId): string
	{
		return self::CACHE_SCOPE . '_' . $objectId;
	}

	/**
	 * A directory per two characters of the hash of the id. A single directory for every object of a portal
	 * would hold a file per object anybody opened the popup for.
	 */
	private function buildDir(int $objectId): string
	{
		return self::CACHE_SCOPE . '/' . substr(md5((string)$objectId), 2, 2);
	}
}
