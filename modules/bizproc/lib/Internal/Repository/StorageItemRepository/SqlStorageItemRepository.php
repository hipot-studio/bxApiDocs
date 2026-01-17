<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Repository\StorageItemRepository;

use Bitrix\Bizproc\Internal\Entity;
use Bitrix\Bizproc\Internal\Container;
use Bitrix\Bizproc\Internal\Exception\StorageItem\CreateStorageItemException;
use Bitrix\Bizproc\Internal\Exception\StorageItem\DeleteStorageItemException;
use Bitrix\Bizproc\Internal\Repository\Mapper\StorageItemMapper;
use Bitrix\Bizproc\Internal\Model\StorageRecordTable;
use Bitrix\Bizproc\Public\Provider\Params\StorageItem\StorageItemFilter;
use Bitrix\Main\ORM\Data\AddResult;
use Bitrix\Main\ORM\Query\QueryHelper;
use Bitrix\Main\ORM\Data\UpdateResult;
use Bitrix\Main\Provider\Params\FilterInterface;
use Bitrix\Bizproc\Internal\Service\StorageField\FieldCodeService;
use Bitrix\Bizproc\Internal\Model\EO_StorageRecord;
use Bitrix\Main\Type\DateTime;

class SqlStorageItemRepository implements StorageItemRepositoryInterface
{
	public function __construct(private readonly StorageItemMapper $mapper)
	{
	}

	public function getItem(int $storageTypeId, int $itemId, array $select): ?Entity\StorageItem\StorageItem
	{
		return $this->getList(
			storageTypeId: $storageTypeId,
			filter: new StorageItemFilter(['ID' => $itemId]),
			select: $select
		)?->getFirstCollectionItem();
	}

	public function getItems(int $storageTypeId, array $parameters = []): ?Entity\StorageItem\StorageItemCollection
	{
		$query =
			StorageRecordTable::query()
				->setFilter($parameters['filter'] ?? [])
				->where('STORAGE_ID', $storageTypeId)
				->setSelect($parameters['select'] ?? [])
				->setOrder($parameters['order'] ?? [])
				->setGroup($parameters['group'] ?? [])
				->setLimit($parameters['limit'] ?? null)
				->setOffset($parameters['offset'] ?? null)
		;
		$items = $query->fetchCollection();
		if ($items)
		{
			$storageItems = $this->convertOrmItemsToCollection($storageTypeId, $items);

			return new Entity\StorageItem\StorageItemCollection(...$storageItems);
		}

		return null;
	}

	public function getCount(int $storageTypeId, array $filter = []): int
	{
		$parameters = [
			'filter' => $filter,
		];
		$parameters['select'] = [
			new \Bitrix\Main\ORM\Fields\ExpressionField('CNT', 'COUNT(1)')
		];

		$result = StorageRecordTable::getList($parameters)->fetch();

		return (int) $result['CNT'];
	}

	public function findOldStorageItemIds(DateTime $createdTime, ?int $limit = null): array
	{
		$result = StorageRecordTable::getList([
			'filter' => ['<CREATED_TIME' => $createdTime],
			'select' => ['ID'],
			'limit' => $limit,
		]);
		$ids = [];
		while ($row = $result->fetch())
		{
			$ids[] = (int)$row['ID'];
		}

		return $ids;
	}

	public function exists(int $id): bool
	{
		$result = StorageRecordTable::getByPrimary($id, ['select' => ['ID']])->fetch();
		if ($result)
		{
			return true;
		}

		return false;
	}

	public function getList(
		int $storageTypeId,
		?int $limit = null,
		?int $offset = null,
		?FilterInterface $filter = null,
		?array $sort = null,
		?array $select = null,
	): Entity\StorageItem\StorageItemCollection
	{
		$query =
			StorageRecordTable::query()
				->setSelect($select ?: ['*'])
				->where('STORAGE_ID', $storageTypeId)
		;

		if ($limit !== null)
		{
			$query->setLimit($limit);
		}

		if ($offset !== null)
		{
			$query->setOffset($offset);
		}

		if ($filter !== null)
		{
			$query->where($filter->prepareFilter());
		}

		if ($sort !== null)
		{
			$query->setOrder($sort);
		}

		$ormStorageFields = QueryHelper::decompose($query);
		$storageItems = $this->convertOrmItemsToCollection($storageTypeId, $ormStorageFields);

		return new Entity\StorageItem\StorageItemCollection(...$storageItems);
	}

	public function saveItem(
		int $storageTypeId, Entity\StorageItem\StorageItem $item,
		string $exceptionClass = null
	): AddResult|UpdateResult
	{
		$exceptionClass ??= CreateStorageItemException::class;

		try
		{
			$ormStorageItem = $this->mapper->convertToOrm($storageTypeId, $item);
			if (!$ormStorageItem)
			{
				throw new $exceptionClass('Storage type not found');
			}

			$validator = new \Bitrix\Bizproc\Internal\Service\StorageField\StorageFieldValidatorService(
				Container::getStorageFieldRepository()
			);
			$errors = $validator->validate($storageTypeId, $item);
			if ($errors)
			{
				$exceptionClass = $exceptionClass ?? CreateStorageItemException::class;

				throw new $exceptionClass(implode("\n", array_column($errors, 'message')));
			}

			$result = $ormStorageItem->save();
			if (!$result->isSuccess())
			{
				$exceptionClass = $exceptionClass ?? CreateStorageItemException::class;

				throw new $exceptionClass($result->getErrors()[0]->getMessage());
			}

			if ($result->isSuccess() && $item->isNew())
			{
				$item->setId($result->getId());
			}

			return $result;
		}
		catch (\Throwable $exception)
		{
			throw new $exceptionClass($exception->getMessage());
		}
	}

	public function deleteItem(int $itemId): void
	{
		try
		{
			$result = StorageRecordTable::delete($itemId);
		}
		catch (\Throwable $e)
		{
			throw new DeleteStorageItemException($e->getMessage());
		}

		if (!$result->isSuccess())
		{
			throw new DeleteStorageItemException($result->getErrors()[0]->getMessage());
		}
	}

	public function deleteByIds(array $ids): void
	{
		StorageRecordTable::deleteByFilter(['=ID' => $ids]);
	}

	private function convertOrmItemsToCollection(
		int $storageTypeId,
		iterable $ormItems
	): Entity\StorageItem\StorageItemCollection
	{
		$fieldCodes = (new FieldCodeService())->getFieldCodes($storageTypeId);

		$storageItems = [];
		foreach ($ormItems as $ormStorageItem)
		{
			$entity = $this->createEntityFromOrm($ormStorageItem, $fieldCodes);

			$storageItems[] = $entity;
		}

		return new Entity\StorageItem\StorageItemCollection(...$storageItems);
	}

	private function createEntityFromOrm(
		EO_StorageRecord $ormStorageItem,
		array $fieldCodes
	): Entity\StorageItem\StorageItem
	{
		$entity = $this->mapper->convertFromOrm($ormStorageItem);

		if ($fieldCodes)
		{
			$data = $ormStorageItem->getValue() ?? [];
			foreach ($fieldCodes as $code)
			{
				$entity->setValueField($code, $data[$code] ?? null);
			}
		}

		return $entity;
	}
}