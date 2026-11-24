<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Repository;

use Bitrix\Disk\Internal\Entity\FavoriteMark;
use Bitrix\Disk\Internal\Model\FavoriteObjectTable;
use Bitrix\Disk\Internals\ObjectPathTable;
use Bitrix\Main\DB\DuplicateEntryException;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\DateTime;

/**
 * Storage of the personal favorite marks. A mark is unique per user and object, so adding and removing are
 * idempotent and answer whether the row actually changed.
 */
final class FavoriteMarkRepository
{
	private const IDS_PER_QUERY = 500;

	public function add(int $userId, int $objectId): bool
	{
		$connection = FavoriteObjectTable::getEntity()->getConnection();
		// A duplicate swallowed here would leave an outer pgsql transaction aborted, so the attempt runs
		// in a transaction (a savepoint when nested) of its own.
		$shouldUseTransaction = $connection->getType() === 'pgsql';
		if ($shouldUseTransaction)
		{
			$connection->startTransaction();
		}

		try
		{
			FavoriteObjectTable::add([
				'USER_ID' => $userId,
				'OBJECT_ID' => $objectId,
				'CREATE_TIME' => new DateTime(),
			]);
		}
		catch (DuplicateEntryException)
		{
			if ($shouldUseTransaction)
			{
				$connection->rollbackTransaction();
			}

			return false;
		}
		catch (\Throwable $exception)
		{
			if ($shouldUseTransaction)
			{
				$connection->rollbackTransaction();
			}

			throw $exception;
		}

		if ($shouldUseTransaction)
		{
			$connection->commitTransaction();
		}

		return true;
	}

	public function remove(int $userId, int $objectId): bool
	{
		FavoriteObjectTable::deleteByFilter([
			'=USER_ID' => $userId,
			'=OBJECT_ID' => $objectId,
		]);

		return FavoriteObjectTable::getEntity()->getConnection()->getAffectedRowsCount() > 0;
	}

	/**
	 * @param int[] $objectIds
	 * @return int[] the given object ids marked by the user, in the given order
	 */
	public function filterMarked(int $userId, array $objectIds): array
	{
		$objectIds = array_values(array_unique(array_map('intval', $objectIds)));
		if ($objectIds === [])
		{
			return [];
		}

		$marked = [];
		foreach (array_chunk($objectIds, self::IDS_PER_QUERY) as $chunk)
		{
			$rows = FavoriteObjectTable::query()
				->setSelect(['OBJECT_ID'])
				->where('USER_ID', $userId)
				->whereIn('OBJECT_ID', $chunk)
				->fetchAll()
			;
			foreach ($rows as $row)
			{
				$marked[(int)$row['OBJECT_ID']] = true;
			}
		}

		return array_values(array_filter($objectIds, static fn (int $objectId): bool => isset($marked[$objectId])));
	}

	public function deleteByObjectId(int $objectId): void
	{
		FavoriteObjectTable::deleteByFilter(['=OBJECT_ID' => $objectId]);
	}

	public function deleteByUserId(int $userId): void
	{
		FavoriteObjectTable::deleteByFilter(['=USER_ID' => $userId]);
	}

	/**
	 * @param int[] $ids
	 */
	public function deleteByIds(array $ids): void
	{
		$ids = array_values(array_unique(array_map('intval', $ids)));
		foreach (array_chunk($ids, self::IDS_PER_QUERY) as $chunk)
		{
			FavoriteObjectTable::deleteByFilter(['@ID' => $chunk]);
		}
	}

	/**
	 * @return list<FavoriteMark> marks with an id greater than the given one, ascending by id
	 */
	public function findChunkAfter(int $lastId, int $limit): array
	{
		return $this->fetchMarks(
			$this->markQuery()
				->where('ID', '>', $lastId)
				->setLimit($limit)
		);
	}

	/**
	 * @return list<FavoriteMark> marks of the user on objects of the storage
	 */
	public function findByUserInStorage(int $userId, int $storageId): array
	{
		return $this->fetchMarks(
			$this->markQuery()
				->where('USER_ID', $userId)
				->where('OBJECT.STORAGE_ID', $storageId)
		);
	}

	/**
	 * @return list<FavoriteMark> marks of every user on the object and its descendants
	 */
	public function findByObjectSubtree(int $rootObjectId): array
	{
		return $this->fetchMarks(
			$this->markQuery()
				->registerRuntimeField(
					(new Reference(
						'PATH',
						ObjectPathTable::class,
						Join::on('this.OBJECT_ID', 'ref.OBJECT_ID'),
					))->configureJoinType(Join::TYPE_INNER)
				)
				->where('PATH.PARENT_ID', $rootObjectId)
		);
	}

	private function markQuery(): Query
	{
		return FavoriteObjectTable::query()
			->setSelect(['ID', 'USER_ID', 'OBJECT_ID'])
			->setOrder(['ID' => 'ASC'])
		;
	}

	/**
	 * @return list<FavoriteMark>
	 */
	private function fetchMarks(Query $query): array
	{
		$marks = [];
		foreach ($query->fetchAll() as $row)
		{
			$marks[] = new FavoriteMark((int)$row['ID'], (int)$row['USER_ID'], (int)$row['OBJECT_ID']);
		}

		return $marks;
	}
}
