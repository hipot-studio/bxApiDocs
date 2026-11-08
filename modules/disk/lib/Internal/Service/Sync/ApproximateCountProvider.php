<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync;

use Bitrix\Disk\Internal\Service\Sync\Snapshot\SkipRightsProbe;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;

/**
 * How many objects the initial fetch is roughly about to hand over, counted only where counting is
 * free of the rights check.
 *
 * The number exists for one purpose: the client draws the progress of its first synchronisation
 * against it. That is why it is a lower bound with the incompleteness said out loud, and never a
 * sum over part of the storages passed off as the whole. A total that is quietly short makes the
 * fetch run past "everything", which reads to a user as a broken client.
 *
 * A storage is counted only when the rights check can be dropped for it altogether. That question
 * is not answered here: it is the same question the initial fetch asks before it drops the rights
 * predicate of a storage, and {@see SkipRightsProbe} owns the answer for both.
 *
 * The storages of other people the fetch walks by the checkpoints of the session are outside the
 * number for the same reason - their rights check is what narrows them - and outside its completeness
 * too: a boundary called complete while the fetch goes on delivering past it is worse than a boundary
 * that says it is a lower one.
 */
class ApproximateCountProvider
{
	private SkipRightsProbe $skipRightsProbe;

	public function __construct(?SkipRightsProbe $skipRightsProbe = null)
	{
		$this->skipRightsProbe = $skipRightsProbe ?? new SkipRightsProbe();
	}

	/**
	 * Counts what can be counted without checking rights per object.
	 *
	 * @param array<int, int> $storages storage id => root object id, as {@see StorageListProvider}
	 *        returns it
	 * @param DateTime $fileModifiedFrom the boundary of the effective history depth
	 * @param DateTime $fileModifiedTo the boundary of state the session fixed, the same one the pages
	 *        of the fetch are cut by
	 * @param array<int, int[]> $checkpointsByStorage the checkpoints of visibility the session fixed,
	 *        as {@see CheckpointListProvider} groups them. Nothing of them is counted - what lies under
	 *        a checkpoint is exactly what the rights check cannot be dropped for - but a fetch that
	 *        walks them hands over more than this number, so a list that is not empty is what makes the
	 *        number incomplete
	 * @return array{count: int, complete: bool}|null null when no storage of the list can be
	 *         counted cheaply, which is what makes the caller leave the number out of the answer
	 *         entirely
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function countCheaply(
		int $userId,
		array $storages,
		DateTime $fileModifiedFrom,
		DateTime $fileModifiedTo,
		array $checkpointsByStorage = [],
	): ?array
	{
		if ($userId <= 0 || $storages === [])
		{
			return null;
		}

		$countableStorageIds = $this->skipRightsProbe->findStoragesWithoutRightsCheck($userId, $storages);
		if ($countableStorageIds === [])
		{
			return null;
		}

		return [
			'count' => $this->countObjects($countableStorageIds, $fileModifiedFrom, $fileModifiedTo),
			'complete' => $checkpointsByStorage === []
				&& count($countableStorageIds) === count($storages)
			,
		];
	}

	/**
	 * What the pages of the fetch will certainly carry out of these storages, as one aggregate.
	 *
	 * The window is the one the pages are read in, closed at both ends: a file changed after the
	 * boundary the session fixed moves above the cut and arrives through the journal instead, so
	 * counting it here would promise a page that never comes.
	 *
	 * Only files, and only the real ones. Folders are not counted at all - the fetch hands over those
	 * that are ancestors of the files it selected, in a section of its own that does not count towards
	 * the size of a page, and every folder of a storage is far more than that. A link is not counted
	 * either: it is a placement of an object that is delivered under the identifier of that object, so
	 * a link counted beside it would be the same card twice. Both exclusions keep the number below
	 * what the fetch delivers rather than above it, which is what a lower bound has to be.
	 *
	 * @param int[] $storageIds
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function countObjects(array $storageIds, DateTime $fileModifiedFrom, DateTime $fileModifiedTo): int
	{
		$row = ObjectTable::query()
			->whereIn('STORAGE_ID', $storageIds)
			->where('TYPE', ObjectTable::TYPE_FILE)
			->where('UPDATE_TIME', '>=', $fileModifiedFrom)
			->where('UPDATE_TIME', '<=', $fileModifiedTo)
			->whereColumn('ID', 'REAL_OBJECT_ID')
			->registerRuntimeField(new ExpressionField('OBJECT_COUNT', 'COUNT(1)'))
			->addSelect('OBJECT_COUNT')
			->fetch()
		;

		return is_array($row) ? (int)$row['OBJECT_COUNT'] : 0;
	}
}
