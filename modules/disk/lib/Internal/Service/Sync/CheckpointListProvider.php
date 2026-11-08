<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync;

use Bitrix\Disk\Driver;
use Bitrix\Disk\Internals\ObjectPathTable;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\Internals\SimpleRightTable;
use Bitrix\Disk\Internals\StorageTable;
use Bitrix\Disk\Security\DiskSecurityContext;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\SystemException;

/**
 * The checkpoints of visibility a user holds in storages of other people, read in one statement.
 *
 * A folder shared with a person lies in the personal storage of whoever shared it, and the root of that
 * storage is not readable by them - so {@see StorageListProvider} leaves the whole storage out and
 * everything shared reaches the client through the journal alone. These are the second kind of unit the
 * initial fetch merges by: a storage of another person, walked not whole but by the subtrees of the
 * checkpoints found here.
 *
 * The read is cheap because b_disk_simple_right is an index of checkpoints rather than of rights per
 * object: a row exists only for an object carrying rights of its own, and the list predicate of the
 * module resolves any other object into its nearest such ancestor. So "everything I may read" is found
 * over a small indexed table instead of over the objects of the portal.
 *
 * Two properties of the answer are deliberate:
 *
 *  - the kinds of storage are the same three the rest of the contour synchronises. Read without that,
 *    the service storages of neighbouring modules - chat files above all, whose root is readable by any
 *    authorised user - would come back as storages of other people and undo a narrowing the contour
 *    makes on purpose.
 *  - a checkpoint may be a file. Sharing a single file with a person makes one, and dropping checkpoints
 *    by the type of the object would lose exactly that file, so the type is not looked at here and the
 *    walk of a subtree has to include the checkpoint itself.
 */
class CheckpointListProvider
{
	/**
	 * The object the row of the index stands for. The table declares no relations of its own, so the
	 * join is registered for this read.
	 */
	private const OBJECT_FIELD = 'CHECKPOINT_OBJECT';

	private const FOREIGN_STORAGE_FIELD = 'IN_FOREIGN_STORAGE';

	/**
	 * @return array<int, int[]> storage id => the checkpoints of that storage, both ordered
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getCheckpointsByStorage(int $userId): array
	{
		if ($userId <= 0)
		{
			return [];
		}

		$storageIdByCheckpoint = $this->readCheckpoints($userId);
		if ($storageIdByCheckpoint === [])
		{
			return [];
		}

		foreach ($this->findNestedCheckpoints(array_keys($storageIdByCheckpoint)) as $nestedCheckpointId)
		{
			unset($storageIdByCheckpoint[$nestedCheckpointId]);
		}

		return $this->groupByStorage($storageIdByCheckpoint);
	}

	/**
	 * The rights check is the root-object flavour rather than the general one: every candidate row here
	 * names an object with rights of its own, and for such an object the nearest ancestor the general
	 * expression would climb to is the object itself.
	 *
	 * An object whose own rights carry no readable task is left out, and nothing is lost by it: what
	 * makes such an object visible is an ancestor, that ancestor is a checkpoint of its own, and its
	 * subtree covers the descendant.
	 *
	 * @return array<int, int> checkpoint id => the storage it lies in
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function readCheckpoints(int $userId): array
	{
		$checkpointObject = new Reference(
			self::OBJECT_FIELD,
			ObjectTable::class,
			Join::on('this.OBJECT_ID', 'ref.ID'),
		);
		$checkpointObject->configureJoinType(Join::TYPE_INNER);

		$parameters = [
			'select' => ['OBJECT_ID', 'STORAGE_ID' => self::OBJECT_FIELD . '.STORAGE_ID'],
			'filter' => Query::filter()->where(self::FOREIGN_STORAGE_FIELD, true),
			'runtime' => [$checkpointObject, $this->buildForeignStorageField($userId)],
		];

		$parameters = Driver::getInstance()->getRightsManager()->addRightsCheckForRootObjects(
			new DiskSecurityContext($userId),
			$parameters,
			[self::OBJECT_FIELD . '.ID', self::OBJECT_FIELD . '.CREATED_BY'],
		);

		$storageIdByCheckpoint = [];
		$rows = SimpleRightTable::getList($parameters);
		while ($row = $rows->fetch())
		{
			$storageIdByCheckpoint[(int)$row['OBJECT_ID']] = (int)$row['STORAGE_ID'];
		}

		return $storageIdByCheckpoint;
	}

	/**
	 * The storages of other people, said as a condition on the storage of the object instead of as a
	 * list of identifiers gathered in php: a portal with hundreds of storages would degenerate such a
	 * list into a query of its own size.
	 *
	 * What the condition holds is exactly what {@see StorageListProvider} decides visibility by - the
	 * kind of the storage, the root being a root, the root being readable - with the last of the three
	 * negated. So the two lists cannot overlap and cannot leave a storage out between them.
	 *
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	private function buildForeignStorageField(int $userId): ExpressionField
	{
		$storageTable = StorageTable::getTableName();
		$objectTable = ObjectTable::getTableName();

		$kindOfStorage = (new SqlExpression(
			'checkpoint_storage.MODULE_ID = ?s AND checkpoint_storage.ENTITY_TYPE IN (?@)',
			Driver::INTERNAL_MODULE_ID,
			StorageListProvider::SYNCHRONISED_ENTITY_TYPES,
		))->compile();

		$readableRoot = (new DiskSecurityContext($userId))->getSqlExpressionForRootObjectList(
			'checkpoint_storage_root.ID',
			'checkpoint_storage_root.CREATED_BY',
		);

		return new ExpressionField(
			self::FOREIGN_STORAGE_FIELD,
			"CASE WHEN EXISTS (
				SELECT 1
				FROM {$storageTable} checkpoint_storage
				WHERE checkpoint_storage.ID = %s
				  AND {$kindOfStorage}
				  AND NOT EXISTS (
					  SELECT 1
					  FROM {$objectTable} checkpoint_storage_root
					  WHERE checkpoint_storage_root.ID = checkpoint_storage.ROOT_OBJECT_ID
						AND checkpoint_storage_root.PARENT_ID IS NULL
						AND {$readableRoot}
				  )
			) THEN 1 ELSE 0 END",
			[self::OBJECT_FIELD . '.STORAGE_ID'],
			['data_type' => 'boolean'],
		);
	}

	/**
	 * The checkpoints lying under another checkpoint of the same user: the subtree of the ancestor
	 * covers the subtree of the descendant, so keeping both would walk the same objects twice and store
	 * a list several times longer than the answer needs.
	 *
	 * The depth of zero is excluded and that is not an optimisation: every object is its own ancestor in
	 * the table of closure, so without it the whole set would drop itself.
	 *
	 * @param int[] $checkpointIds
	 * @return int[]
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function findNestedCheckpoints(array $checkpointIds): array
	{
		$rows = ObjectPathTable::query()
			->setSelect(['OBJECT_ID'])
			->whereIn('OBJECT_ID', $checkpointIds)
			->whereIn('PARENT_ID', $checkpointIds)
			->where('DEPTH_LEVEL', '>', 0)
			->fetchAll()
		;

		return array_map(static fn (array $row): int => (int)$row['OBJECT_ID'], $rows);
	}

	/**
	 * Grouped by storage and not handed over flat: the stream of what is shared merges by storages and
	 * narrows the walk inside each of them by the checkpoints of that storage, and a flat list would
	 * have it rebuild the grouping on every page. Both halves are ordered so that the same set of
	 * rights always writes the same row of the session.
	 *
	 * @param array<int, int> $storageIdByCheckpoint
	 * @return array<int, int[]>
	 */
	private function groupByStorage(array $storageIdByCheckpoint): array
	{
		$grouped = [];
		foreach ($storageIdByCheckpoint as $checkpointId => $storageId)
		{
			$grouped[$storageId][] = $checkpointId;
		}

		ksort($grouped);
		foreach ($grouped as &$checkpointIds)
		{
			sort($checkpointIds);
		}
		unset($checkpointIds);

		return $grouped;
	}
}
