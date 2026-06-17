<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Repository\StorageItemRepository;

use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\Internal\Entity;
use Bitrix\Bizproc\Internal\Exception\StorageItem\CreateStorageItemException;
use Bitrix\Bizproc\Internal\Exception\StorageItem\DeleteStorageItemException;
use Bitrix\Bizproc\Internal\Container;
use Bitrix\Bizproc\Internal\Repository\Mapper\StorageItemMapper;
use Bitrix\Bizproc\Internal\Service\StorageField\StorageFieldValidatorService;
use Bitrix\Bizproc\Public\Provider\Params\StorageItem\StorageItemFilter;
use Bitrix\Bizproc\Internal\Service\StorageItem\StorageItemQueryBuilder;
use Bitrix\Bizproc\Internal\Entity\StorageItem\StorageItemQueryDto;
use Bitrix\Main\ORM\Data\AddResult;
use Bitrix\Main\ORM\Data\UpdateResult;
use Bitrix\Main\ORM\Query\QueryHelper;
use Bitrix\Main\Provider\Params\FilterInterface;
use Bitrix\Bizproc\Internal\Entity\StorageItem\StorageEavMigrationPhase;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Application;

class StorageItemRepository implements StorageItemRepositoryInterface
{
	private const QUERY_CHUNK = 500;

	public function __construct(
		private readonly StorageItemMapper $mapper,
		private readonly StorageFieldValueRepository $fieldValueRepository,
		private readonly StorageFieldValidatorService $validator,
	)
	{
	}

	public function getItem(int $storageTypeId, int $itemId, array $select): ?Entity\StorageItem\StorageItem
	{
		return $this->getList(
			storageTypeId: $storageTypeId,
			filter: new StorageItemFilter(['ID' => $itemId]),
			select: $select,
		)?->getFirstCollectionItem();
	}

	public function getItems(int $storageTypeId, array $parameters = []): Entity\StorageItem\StorageItemCollection
	{
		$defaults = [
			'select' => ['*'],
			'order' => [],
			'group' => [],
			'limit' => null,
			'offset' => null,
			'filter' => [],
		];

		$params = array_merge($defaults, array_intersect_key($parameters, $defaults));
		$params['filter'] = new StorageItemFilter($params['filter']);

		return $this->findItems($storageTypeId, $params);
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
		$parameters = [
			'select' => $select ?? ['*'],
			'order' => $sort ?? [],
			'group' => [],
			'limit' => $limit,
			'offset' => $offset,
			'filter' => $filter,
		];

		return $this->findItems($storageTypeId, $parameters);
	}

	public function getCount(int $storageTypeId, array $filter = []): int
	{
		$fieldMap = $this->fieldValueRepository->getFieldMap($storageTypeId);

		$dto = new StorageItemQueryDto(
			select: [new \Bitrix\Main\ORM\Fields\ExpressionField('CNT', 'COUNT(1)')],
			filter: new StorageItemFilter($filter),
		);

		[$query] = (new StorageItemQueryBuilder($fieldMap))->build($storageTypeId, $dto);

		$result = $query->exec()->fetch();

		return (int)$result['CNT'];
	}

	public function exists(int $id): bool
	{
		$dataManager = Container::getStorageRecordDataManager();

		return (bool)$dataManager::getByPrimary($id, ['select' => ['ID']])->fetch();
	}

	public function findOldStorageItemIds(DateTime $createdTime, ?int $limit = null): array
	{
		$dataManager = Container::getStorageRecordDataManager();
		$result = $dataManager::getList([
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

	public function saveItem(
		int $storageTypeId,
		Entity\StorageItem\StorageItem $item,
		?string $exceptionClass = null,
	): AddResult|UpdateResult
	{
		$exceptionClass ??= CreateStorageItemException::class;

		$this->assertValidInput($storageTypeId, $item, $exceptionClass);
		$this->validateFields($storageTypeId, $item, $exceptionClass);

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$result = $this->saveRecord($storageTypeId, $item, $exceptionClass);
			$this->saveFieldValues($item);

			$connection->commitTransaction();

			return $result;
		}
		catch (\Throwable $exception)
		{
			$connection->rollbackTransaction();
			throw new $exceptionClass($exception->getMessage());
		}
	}

	public function deleteItem(int $itemId): void
	{
		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$this->fieldValueRepository->deleteByRecordId($itemId);

			$dataManager = Container::getStorageRecordDataManager();
			$result = $dataManager::delete($itemId);
			if (!$result->isSuccess())
			{
				throw new DeleteStorageItemException(implode("\n", $result->getErrorMessages()));
			}

			$connection->commitTransaction();
		}
		catch (\Throwable $e)
		{
			$connection->rollbackTransaction();
			throw new DeleteStorageItemException($e->getMessage());
		}
	}

	public function deleteByIds(array $ids): void
	{
		$ids = array_map('intval', $ids);
		$ids = array_filter($ids, static fn(int $id) => $id > 0);

		if (!$ids)
		{
			return;
		}

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$this->fieldValueRepository->deleteByRecordIds($ids);

			$dataManager = Container::getStorageRecordDataManager();
			foreach (array_chunk($ids, self::QUERY_CHUNK) as $chunk)
			{
				$dataManager::deleteByFilter(['=ID' => $chunk]);
			}

			$connection->commitTransaction();
		}
		catch (\Throwable $e)
		{
			$connection->rollbackTransaction();
			throw new DeleteStorageItemException($e->getMessage());
		}
	}

	private function findItems(int $storageTypeId, array $parameters): Entity\StorageItem\StorageItemCollection
	{
		$fieldMap = $this->fieldValueRepository->getFieldMap($storageTypeId);

		$select = $parameters['select'] ?: ['*'];
		if (
			$this->isReadFromJsonEnabled()
			&& !in_array('*', $select, true)
			&& !in_array('VALUE', $select, true)
		)
		{
			$select[] = 'VALUE';
		}

		$dto = new StorageItemQueryDto(
			select: $select,
			filter: $parameters['filter'] ?: null,
			order:  $parameters['order'] ?? [],
			group:  $parameters['group'] ?? [],
			limit:  $parameters['limit'] ?? null,
			offset: $parameters['offset'] ?? null,
		);

		[$query, $fieldCodes] = (new StorageItemQueryBuilder($fieldMap))->build($storageTypeId, $dto);

		$ormItems = QueryHelper::decompose($query);

		if ($ormItems->isEmpty())
		{
			return new Entity\StorageItem\StorageItemCollection();
		}

		return $this->fillCollection($ormItems, $storageTypeId, $fieldCodes);
	}

	private function fillCollection(
		iterable $ormItems,
		int $storageTypeId,
		?array $fieldCodes,
	): Entity\StorageItem\StorageItemCollection
	{
		if ($this->isReadFromJsonEnabled())
		{
			return $this->fillCollectionFromJson($ormItems, $fieldCodes);
		}

		return $this->fillCollectionFromEav($ormItems, $storageTypeId, $fieldCodes);
	}

	private function fillCollectionFromJson(
		iterable $ormItems,
		?array $fieldCodes,
	): Entity\StorageItem\StorageItemCollection
	{
		$storageItems = [];
		foreach ($ormItems as $ormItem)
		{
			$entity = $this->mapper->convertFromOrm($ormItem);
			$values = $ormItem->getValue() ?? [];

			if ($fieldCodes !== null)
			{
				$values = array_intersect_key($values, array_flip($fieldCodes));
			}

			$entity->setValueFields($values);
			$storageItems[] = $entity;
		}

		return new Entity\StorageItem\StorageItemCollection(...$storageItems);
	}

	private function fillCollectionFromEav(
		iterable $ormItems,
		int $storageTypeId,
		?array $fieldCodes,
	): Entity\StorageItem\StorageItemCollection
	{
		$recordIds = [];
		foreach ($ormItems as $ormItem)
		{
			$recordIds[] = $ormItem->getId();
		}

		if (!$recordIds)
		{
			return new Entity\StorageItem\StorageItemCollection();
		}

		$fieldValues = $this->fieldValueRepository->loadFieldValues($recordIds, $storageTypeId, $fieldCodes);

		$storageItems = [];
		foreach ($ormItems as $ormItem)
		{
			$entity = $this->mapper->convertFromOrm($ormItem);
			$entity->setValueFields($fieldValues[$ormItem->getId()] ?? []);
			$storageItems[] = $entity;
		}

		return new Entity\StorageItem\StorageItemCollection(...$storageItems);
	}

	private function assertValidInput(
		int $storageTypeId,
		Entity\StorageItem\StorageItem $item,
		string $exceptionClass,
	): void
	{
		if ($storageTypeId <= 0 || $item->getStorageId() !== $storageTypeId)
		{
			throw new $exceptionClass(ErrorMessage::INVALID_PARAM_ARG->get([
				'#PARAM#' => 'STORAGE_ID',
				'#VALUE#' => $storageTypeId,
			]));
		}

		if (empty($item->getValueFields()))
		{
			throw new $exceptionClass(ErrorMessage::GET_DATA_ERROR->get());
		}
	}

	private function validateFields(
		int $storageTypeId,
		Entity\StorageItem\StorageItem $item,
		string $exceptionClass,
	): void
	{
		$errors = $this->validator->validate($storageTypeId, $item);
		if ($errors)
		{
			throw new $exceptionClass(implode("\n", array_column($errors, 'message')));
		}
	}

	private function saveRecord(
		int $storageTypeId,
		Entity\StorageItem\StorageItem $item,
		string $exceptionClass,
	): AddResult|UpdateResult
	{
		$ormStorageItem = $this->mapper->convertToOrm($storageTypeId, $item);
		if (!$ormStorageItem)
		{
			throw new $exceptionClass(ErrorMessage::ENTITY_NOT_EXISTS->get());
		}

		if ($this->isDualWriteEnabled())
		{
			$ormStorageItem->setValue($item->getValueFields());
		}

		$result = $ormStorageItem->save();
		if (!$result->isSuccess())
		{
			throw new $exceptionClass($result->getErrors()[0]->getMessage());
		}

		if ($item->isNew())
		{
			$item->setId($result->getId());
		}

		return $result;
	}

	private function saveFieldValues(Entity\StorageItem\StorageItem $item): void
	{
		$item->isNew()
			? $this->fieldValueRepository->add($item)
			: $this->fieldValueRepository->sync($item)
		;
	}

	private function isDualWriteEnabled(): bool
	{
		$phase = StorageEavMigrationPhase::getCurrent();

		return $phase === StorageEavMigrationPhase::DualWriteJsonRead
			|| $phase === StorageEavMigrationPhase::DualWriteEavRead
		;
	}

	private function isReadFromJsonEnabled(): bool
	{
		return StorageEavMigrationPhase::getCurrent() === StorageEavMigrationPhase::DualWriteJsonRead;
	}
}
