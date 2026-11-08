<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync\Snapshot;

use Bitrix\Disk\Driver;
use Bitrix\Disk\Internals\ObjectPathTable;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\Security\DiskSecurityContext;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;

/**
 * One ordered read of the files of one phase of the initial fetch, over one storage.
 *
 * A stream is described by three things and nothing else: the key it is ordered by, the window of
 * that key it lives in, and the place the previous read stopped at. The interaction set of the first
 * phase differs in all three - it is ordered by when the user last touched the object and is read
 * for the user as a whole - which is exactly why it is never merged into one ordered sequence with
 * these and why it is read by a class of its own.
 *
 * The stream of what is shared, on the other hand, is read by this very class: its key, its window
 * and its order are those of the files, and all that tells it apart is one condition narrowing the
 * walk to the subtrees of the checkpoints the session fixed for the storage being read.
 *
 * Two properties of the read are deliberate and easy to lose:
 *
 *  - the trashcan is not filtered out. The visibility expression of the module says nothing about
 *    DELETED_TYPE; every other list of Disk adds that filter itself, and this one must not (F19).
 *  - the window is bounded from above by the boundary the session fixed. The sort key mutates, so
 *    without the upper bound an object that changed between two pages would move across pages
 *    already read and be lost.
 */
final class PhaseQuery
{
	/**
	 * The rights predicate is correlated and cannot be applied before the ordering, so the read is
	 * narrowed by the storage first and only then filtered by rights - the way every large list of
	 * Disk works.
	 *
	 * It is written over the real object and not over the row that was found, for the same reason the
	 * check of state writes it that way: a page is counted in real objects and a card describes one,
	 * while a link is an object of its own with rights of its own. Read by the rights of the row, the
	 * fetch would hand over the card of a file the reader may not open on the strength of a link
	 * standing in their own storage. For everything that is not a link the two are the same row, and
	 * the join is by the primary key.
	 */
	private const RIGHTS_COLUMNS = ['REAL_OBJECT.ID', 'REAL_OBJECT.CREATED_BY'];

	/**
	 * The name {@see \Bitrix\Disk\RightsManager::addRightsCheck()} registers the predicate under. It
	 * is needed here because this read places the predicate itself instead of taking it as the gate.
	 */
	private const RIGHTS_CHECK_FIELD = 'RIGHTS_CHECK';

	/** The name the narrowing to the subtrees of the checkpoints is registered under. */
	private const SUBTREE_CHECK_FIELD = 'CHECKPOINT_SUBTREE_CHECK';

	/**
	 * @param array<int, int[]>|null $checkpointsByStorage the checkpoints the walk of each storage is
	 *        narrowed to, null when the read is narrowed by the storage alone
	 */
	private function __construct(
		private readonly int $userId,
		private readonly DateTime $upperBound,
		private readonly DateTime $lowerBound,
		private readonly ?array $checkpointsByStorage,
	)
	{
	}

	/**
	 * The stream of files, ordered by the modification date of the object and read one storage at a
	 * time.
	 *
	 * The content-bearing date is the key rather than the synchronisation mark: moving a subtree
	 * rewrites the mark of thousands of objects with one statement, and they would all become
	 * "fresh" at once, crowding out what actually changed.
	 */
	public static function overObjects(int $userId, DateTime $modifiedTo, DateTime $modifiedFrom): self
	{
		return new self($userId, $modifiedTo, $modifiedFrom, null);
	}

	/**
	 * The same stream over the storages of other people: the same key, the same window and the same
	 * order, with the walk of every storage narrowed to the subtrees of the checkpoints of that
	 * storage. Read alike, the two merge into one order without the merge knowing them apart.
	 *
	 * @param array<int, int[]> $checkpointsByStorage storage id => the checkpoints of that storage
	 */
	public static function overSubtreesOfCheckpoints(
		int $userId,
		DateTime $modifiedTo,
		DateTime $modifiedFrom,
		array $checkpointsByStorage,
	): self
	{
		return new self($userId, $modifiedTo, $modifiedFrom, $checkpointsByStorage);
	}

	/**
	 * @return array<int, array{objectId: int, realObjectId: int, freshnessAt: DateTime}>
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function readStorage(
		int $storageId,
		bool $skipRightsCheck,
		?DateTime $afterFreshnessAt,
		?int $afterObjectId,
		int $take,
	): array
	{
		$checkpointIds = $this->checkpointsOfStorage($storageId);
		if ($checkpointIds === [])
		{
			// Narrowed to nothing: a read of a storage of another person the session holds no
			// checkpoint in would be a read of that whole storage.
			return [];
		}

		$filter = Query::filter()
			->where('STORAGE_ID', $storageId)
			->where('TYPE', ObjectTable::TYPE_FILE)
			->where('UPDATE_TIME', '<=', $this->upperBound)
			->where('UPDATE_TIME', '>=', $this->lowerBound)
		;
		$this->applyKeyset($filter, $afterFreshnessAt, $afterObjectId);

		$parameters = [
			'select' => ['ID', 'REAL_OBJECT_ID', 'UPDATE_TIME'],
			'filter' => $filter,
			'order' => ['UPDATE_TIME' => 'DESC', 'ID' => 'DESC'],
			'limit' => $take,
		];

		// Placed before the rights are asked for, because that is the order the two are worth in: the
		// subtree is a cheap narrowing read by one index, the predicate of rights a correlated question
		// asked of what survives it.
		if ($checkpointIds !== null)
		{
			$parameters['runtime'][] = self::buildSubtreeCheckField($checkpointIds);
			$filter->where(self::SUBTREE_CHECK_FIELD, true);
		}

		$parameters = $skipRightsCheck
			? $this->withTheRightsOfWhatLiesOutside($parameters, $filter, $storageId)
			: Driver::getInstance()->getRightsManager()->addRightsCheck(
				new DiskSecurityContext($this->userId),
				$parameters,
				self::RIGHTS_COLUMNS,
			)
		;

		$rows = [];
		$cursor = ObjectTable::getList($parameters);
		while ($row = $cursor->fetch())
		{
			$rows[] = [
				'objectId' => (int)$row['ID'],
				'realObjectId' => (int)$row['REAL_OBJECT_ID'],
				'freshnessAt' => $row['UPDATE_TIME'],
			];
		}

		return $rows;
	}

	/**
	 * The read of a storage the reader reads in full, with the rights weighed for what that promise
	 * does not cover.
	 *
	 * What {@see SkipRightsProbe} establishes is about the objects of one storage: the reader may read
	 * its root and nothing under that root takes the reading away. A link is a placement, and the
	 * object behind it may live anywhere - in a storage the reader was never in - so the promise says
	 * nothing about it. Left to the promise, a link into a storage of a workgroup the reader has left
	 * would hand over the card of every file of that group, which is exactly the reading the check of
	 * state refuses for the same object.
	 *
	 * The condition is written cheap half first. Every row that is not a link out of the storage is
	 * carried by an equality on the storage of the real object, and the correlated predicate is
	 * reached only for the few rows that are, so the storage keeps the saving the probe is there for.
	 *
	 * @param array<string, mixed> $parameters
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	private function withTheRightsOfWhatLiesOutside(
		array $parameters,
		ConditionTree $filter,
		int $storageId,
	): array
	{
		// The predicate is taken as the field the module builds it as, and placed as an alternative
		// rather than as the gate of the whole read - which is the one thing addRightsCheck() does not
		// leave to the caller.
		$rights = Driver::getInstance()->getRightsManager()->addRightsCheck(
			new DiskSecurityContext($this->userId),
			[],
			self::RIGHTS_COLUMNS,
		);

		$parameters['runtime'] = array_merge($parameters['runtime'] ?? [], $rights['runtime']);
		$filter->where(
			Query::filter()
				->logic('or')
				->where('REAL_OBJECT.STORAGE_ID', $storageId)
				->where(self::RIGHTS_CHECK_FIELD, true)
			,
		);

		return $parameters;
	}

	/**
	 * The checkpoints the walk of the given storage is narrowed to: an empty list when this read is
	 * narrowed by checkpoints and the storage holds none, null when it is narrowed by nothing but the
	 * storage itself.
	 *
	 * @return int[]|null
	 */
	private function checkpointsOfStorage(int $storageId): ?array
	{
		return $this->checkpointsByStorage === null
			? null
			: array_values($this->checkpointsByStorage[$storageId] ?? [])
		;
	}

	/**
	 * Membership in the subtree of any checkpoint of the storage, as a per row EXISTS instead of a join
	 * with the table of closure: the equality on the object and the entry on the ancestor are read by
	 * one index, and the question of duplicated rows does not arise at all.
	 *
	 * The depth of zero is not excluded, and that is the checkpoint itself. Sharing a single file with
	 * a person makes a checkpoint of that file, and a walk written to exclude the container it starts
	 * from would hand over everything under such a checkpoint except the one object there is.
	 *
	 * The condition is not a narrowing of the rights: it holds for everything under the checkpoint and
	 * knows nothing of a revocation deeper down, so the predicate of rights stays where it is and this
	 * one only cuts the rows it has to weigh.
	 *
	 * @param int[] $checkpointIds
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	private static function buildSubtreeCheckField(array $checkpointIds): ExpressionField
	{
		$pathTable = ObjectPathTable::getTableName();
		$underACheckpoint = (new SqlExpression(
			'checkpoint_path.PARENT_ID IN (?@)',
			$checkpointIds,
		))->compile();

		return new ExpressionField(
			self::SUBTREE_CHECK_FIELD,
			"CASE WHEN EXISTS(
				SELECT 1
				FROM {$pathTable} checkpoint_path
				WHERE checkpoint_path.OBJECT_ID = %s
				  AND {$underACheckpoint}
			) THEN 1 ELSE 0 END",
			['ID'],
			['data_type' => 'boolean'],
		);
	}

	/**
	 * Keyset by the pair "key of the phase, identifier", never an offset: an offset re-reads and
	 * throws away everything before it, and on a fetch of dozens of pages that is the whole cost.
	 *
	 * The range on the key column is kept as a range so that the index of the stream still serves the
	 * ordering; the tie on the identifier is a residual condition over the few rows sharing a second.
	 */
	private function applyKeyset(
		ConditionTree $filter,
		?DateTime $afterFreshnessAt,
		?int $afterObjectId,
	): void
	{
		if ($afterFreshnessAt === null || $afterObjectId === null)
		{
			return;
		}

		$filter
			->where('UPDATE_TIME', '<=', $afterFreshnessAt)
			->where(
				Query::filter()
					->logic('or')
					->where('UPDATE_TIME', '<', $afterFreshnessAt)
					->where('ID', '<', $afterObjectId),
			)
		;
	}
}
