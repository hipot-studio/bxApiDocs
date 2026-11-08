<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync\Snapshot;

use Bitrix\Disk\Driver;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\Security\DiskSecurityContext;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\SystemException;

/**
 * The folders a page of the initial fetch drags along with it.
 *
 * A folder joins the fetch by kinship and not by its own freshness. The date a folder changed says
 * nothing about the activity inside it, so a folder untouched for three years holding files changed
 * yesterday would fall outside a history of 90-180 days, while the paths of those very files would
 * name it. What the client would then hold is a path node pointing at a folder it has no card for.
 *
 * Read the way every large list of Disk is read - the closure table instead of a walk of the tree,
 * whole pages at a time, and the same predicate of rights - so that the cost grows with the depth of
 * the tree rather than with the number of objects of the page.
 *
 * The trashcan is not filtered out here either (F19): a folder in the trashcan is still the folder
 * the path of its files names, and hiding it would leave those paths hanging.
 */
final class AncestorFolderCollector
{
	/**
	 * How many identifiers one statement carries. A page is smaller than this; the chunking keeps a
	 * caller that assembles several pages at once from handing the database a list it plans badly.
	 */
	private const IDS_PER_QUERY = 300;

	/**
	 * Every folder above the objects of the page that the reader may read, and the checkpoints of the
	 * page that are folders themselves.
	 *
	 * An object of the page is never answered as a folder of it, even when it is the ancestor of
	 * another object of the same page: it already travels in the page itself, and a card repeated in
	 * both sections would be applied twice for nothing.
	 *
	 * @param int[] $realObjectIds real objects of the page, as the fetch collapsed them
	 * @param int[] $checkpointIds the checkpoints of visibility the fetch hands over with this page.
	 *        A folder shared with the reader that holds nothing they may read is nobody's ancestor, so
	 *        kinship alone would leave it out of the fetch for good. A checkpoint that is a file is no
	 *        folder of the page - it travels in the page itself, as an object of the stream
	 * @return int[] deduplicated, in ascending order of the identifier
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function collect(int $userId, array $realObjectIds, array $checkpointIds = []): array
	{
		$realObjectIds = self::normaliseIds($realObjectIds);
		$checkpointIds = self::normaliseIds($checkpointIds);
		if ($userId <= 0 || ($realObjectIds === [] && $checkpointIds === []))
		{
			return [];
		}

		$onThePage = array_fill_keys($realObjectIds, true);

		$read = [];
		foreach (array_chunk($realObjectIds, self::IDS_PER_QUERY) as $chunk)
		{
			$read[] = $this->readAncestors($userId, $chunk);
		}
		foreach (array_chunk($checkpointIds, self::IDS_PER_QUERY) as $chunk)
		{
			$read[] = $this->readFoldersAmong($userId, $chunk);
		}

		$folderIds = [];
		foreach (array_merge(...$read) as $folderId)
		{
			if (!isset($onThePage[$folderId]))
			{
				$folderIds[$folderId] = $folderId;
			}
		}

		ksort($folderIds);

		return array_values($folderIds);
	}

	/**
	 * @param int[] $objectIds
	 * @return int[] one row per pair "ancestor, descendant", so the caller deduplicates
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function readAncestors(int $userId, array $objectIds): array
	{
		return $this->readFolders(
			$userId,
			Query::filter()
				->whereIn('PATH_PARENT.OBJECT_ID', $objectIds)
				// Zero is the object standing over itself, which is not an ancestor of anything.
				->where('PATH_PARENT.DEPTH_LEVEL', '>', 0)
			,
		);
	}

	/**
	 * The checkpoints that are folders, weighed by the rights as everything else here is.
	 *
	 * The list of checkpoints was fixed when the session was created and outlives the rights behind it,
	 * the way the list of storages does: a right taken away since would otherwise publish the name of a
	 * folder the reader may no longer read.
	 *
	 * @param int[] $objectIds
	 * @return int[]
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function readFoldersAmong(int $userId, array $objectIds): array
	{
		return $this->readFolders($userId, Query::filter()->whereIn('ID', $objectIds));
	}

	/**
	 * @return int[]
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function readFolders(int $userId, ConditionTree $filter): array
	{
		$parameters = [
			'select' => ['ID'],
			'filter' => $filter->where('TYPE', ObjectTable::TYPE_FOLDER),
		];
		$parameters = Driver::getInstance()->getRightsManager()->addRightsCheck(
			new DiskSecurityContext($userId),
			$parameters,
			['ID', 'CREATED_BY'],
		);

		$folderIds = [];
		$cursor = ObjectTable::getList($parameters);
		while ($row = $cursor->fetch())
		{
			$folderIds[] = (int)$row['ID'];
		}

		return $folderIds;
	}

	/**
	 * @param int[] $ids
	 * @return int[]
	 */
	private static function normaliseIds(array $ids): array
	{
		$normalised = [];
		foreach ($ids as $id)
		{
			$id = (int)$id;
			if ($id > 0)
			{
				$normalised[$id] = $id;
			}
		}

		return array_values($normalised);
	}
}
