<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync\Snapshot;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;

/**
 * One read of a phase: the ordered streams of the storages of the user, merged into one ordered
 * sequence.
 *
 * The order is not built over the portal as a whole. There is no index for it, and the rights
 * predicate is correlated, so it cannot be applied before the sorting either - a page of a hundred
 * objects at a visibility share of p would cost about 100/p evaluations of the predicate. Reading
 * each storage by its own index and merging what comes back keeps every read on the index of the
 * stream and the predicate on rows already narrowed to a storage.
 *
 * Every stream is ordered by the same key here, so the merge is a comparison of like with like. The
 * streams of the first phase that carry different keys - files against the interaction set - are
 * never given to this merger: they are read one after another, each against its own boundary.
 *
 * A storage whose stream came back empty is named as exhausted, so that the caller may stop asking
 * it: the key is common to all the streams and moves one way, so a storage with nothing below this
 * position has nothing below any position after it either. A short stream - fewer rows than asked
 * for, but not none - is deliberately not counted as exhausted. Its rows may be cut off by the head
 * of the merge or left behind by a page that stopped above them, and both are handed over by a later
 * read of the same storage; dropping the storage on the strength of a short stream would lose them
 * with nothing to show for it.
 */
final class StorageStreamMerger
{
	/**
	 * @param int[] $storageIds the storages still worth asking, in the order of the session
	 * @param int[] $storageIdsWithoutRightsCheck
	 * @return array{
	 *     rows: array<int, array{objectId: int, realObjectId: int, freshnessAt: DateTime}>,
	 *     exhaustedStorageIds: int[]
	 * }
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function read(
		PhaseQuery $query,
		array $storageIds,
		array $storageIdsWithoutRightsCheck,
		?DateTime $afterFreshnessAt,
		?int $afterObjectId,
		int $take,
	): array
	{
		if ($storageIds === [] || $take <= 0)
		{
			return ['rows' => [], 'exhaustedStorageIds' => []];
		}

		$withoutRightsCheck = array_flip($storageIdsWithoutRightsCheck);

		$rows = [];
		$exhaustedStorageIds = [];
		foreach ($storageIds as $storageId)
		{
			// Every stream is asked for the whole page: the merged head may come from one storage
			// alone, and a stream asked for less would leave a hole in the order.
			$stream = $query->readStorage(
				$storageId,
				isset($withoutRightsCheck[$storageId]),
				$afterFreshnessAt,
				$afterObjectId,
				$take,
			);

			if ($stream === [])
			{
				$exhaustedStorageIds[] = $storageId;

				continue;
			}

			foreach ($stream as $row)
			{
				$rows[] = $row;
			}
		}

		return ['rows' => self::takeHead($rows, $take), 'exhaustedStorageIds' => $exhaustedStorageIds];
	}

	/**
	 * @param array<int, array{objectId: int, realObjectId: int, freshnessAt: DateTime}> $rows
	 * @return array<int, array{objectId: int, realObjectId: int, freshnessAt: DateTime}>
	 */
	private static function takeHead(array $rows, int $take): array
	{
		usort(
			$rows,
			static fn(array $left, array $right): int => [$right['freshnessAt']->getTimestamp(), $right['objectId']]
				<=> [$left['freshnessAt']->getTimestamp(), $left['objectId']]
			,
		);

		return array_slice($rows, 0, $take);
	}
}
