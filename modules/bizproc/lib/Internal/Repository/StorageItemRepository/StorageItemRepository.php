<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Repository\StorageItemRepository;

use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\Internal\Entity;
use Bitrix\Bizproc\Internal\Entity\DataView\DataView;
use Bitrix\Bizproc\Internal\Entity\DataView\RowIdentity;
use Bitrix\Bizproc\Internal\Exception\StorageItem\CreateStorageItemException;
use Bitrix\Bizproc\Internal\Exception\StorageItem\DeleteStorageItemException;
use Bitrix\Bizproc\Internal\Container;
use Bitrix\Bizproc\Internal\Repository\Mapper\StorageItemMapper;
use Bitrix\Bizproc\Internal\Service\DataView\Dto\CombineRow;
use Bitrix\Bizproc\Internal\Service\StorageField\StorageFieldValidatorService;
use Bitrix\Bizproc\Public\Provider\Params\StorageItem\StorageItemFilter;
use Bitrix\Bizproc\Internal\Service\StorageItem\StorageItemQueryBuilder;
use Bitrix\Bizproc\Internal\Entity\StorageItem\StorageItemQueryDto;
use Bitrix\Main\ORM\Data\AddResult;
use Bitrix\Main\ORM\Data\UpdateResult;
use Bitrix\Main\ORM\Fields\ArrayField;
use Bitrix\Main\ORM\Query\QueryHelper;
use Bitrix\Main\Provider\Params\FilterInterface;
use Bitrix\Bizproc\Internal\Entity\StorageItem\StorageEavMigrationPhase;
use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;

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

	public function getCountsByStorageTypeIds(array $storageTypeIds): array
	{
		$ids = array_map('intval', $storageTypeIds);
		$ids = array_values(array_unique(array_filter($ids, static fn(int $id) => $id > 0)));
		if (!$ids)
		{
			return [];
		}

		$dataManager = Container::getStorageRecordDataManager();

		$counts = array_fill_keys($ids, 0);
		foreach (array_chunk($ids, self::QUERY_CHUNK) as $chunk)
		{
			$result = $dataManager::query()
				->setSelect(['STORAGE_ID', new \Bitrix\Main\ORM\Fields\ExpressionField('CNT', 'COUNT(1)')])
				->whereIn('STORAGE_ID', $chunk)
				->setGroup(['STORAGE_ID'])
				->exec()
			;

			while ($row = $result->fetch())
			{
				$counts[(int)$row['STORAGE_ID']] = (int)$row['CNT'];
			}
		}

		return $counts;
	}

	public function getStorageTypeIdsByItemIds(array $itemIds): array
	{
		$ids = array_values(array_unique(array_filter(array_map('intval', $itemIds), static fn(int $id) => $id > 0)));
		if (!$ids)
		{
			return [];
		}

		$dataManager = Container::getStorageRecordDataManager();

		$storageTypeIds = [];
		foreach (array_chunk($ids, self::QUERY_CHUNK) as $chunk)
		{
			$result = $dataManager::query()
				->setSelect(['STORAGE_ID'])
				->whereIn('ID', $chunk)
				->setGroup(['STORAGE_ID'])
				->exec()
			;

			while ($row = $result->fetch())
			{
				$storageTypeIds[(int)$row['STORAGE_ID']] = (int)$row['STORAGE_ID'];
			}
		}

		return array_values($storageTypeIds);
	}

	public function exists(int $id): bool
	{
		$dataManager = Container::getStorageRecordDataManager();

		return (bool)$dataManager::getByPrimary($id, ['select' => ['ID']])->fetch();
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

	public function replaceDataViewItems(DataView $view, array $computedRows, int $actorId): void
	{
		$storageTypeId = $view->getStorageTypeId();
		$documentId = RowIdentity::documentId((int)$view->getId());

		[$systemIdsByCode, $frozenCodes, $orphanIds, $storedStateByCode]
			= $this->readDataViewOwnedRows($storageTypeId);

		$suppressed = $frozenCodes + $this->collectDeletionCodes($view);

		$rowsToUpdate = [];
		$rowsToInsert = [];
		foreach ($computedRows as $row)
		{
			$code = $row->getIdentity()->serialize();
			if (isset($suppressed[$code]) || isset($rowsToUpdate[$code]) || isset($rowsToInsert[$code]))
			{
				continue;
			}

			if (isset($systemIdsByCode[$code]))
			{
				$rowsToUpdate[$code] = $row;
			}
			else
			{
				$rowsToInsert[$code] = $row;
			}
		}

		$idsToDelete = $orphanIds;
		foreach ($systemIdsByCode as $code => $id)
		{
			if (!isset($rowsToUpdate[$code]))
			{
				$idsToDelete[] = $id;
			}
		}

		$this->deleteDataViewRows($idsToDelete);
		$this->updateDataViewItems($storageTypeId, $rowsToUpdate, $systemIdsByCode, $storedStateByCode);
		$this->insertDataViewItems($storageTypeId, $documentId, $rowsToInsert, $actorId);
	}

	private function readDataViewOwnedRows(int $storageTypeId): array
	{
		$dualWrite = $this->isDualWriteEnabled();

		$select = ['ID', 'CODE', 'WORKFLOW_ID', 'UPDATED_TIME'];
		if ($dualWrite)
		{
			$select[] = 'VALUE';
			$select[] = 'DOCUMENT_ID';
			$select[] = 'TEMPLATE_ID';
			$select[] = 'CREATED_BY';
			$select[] = 'UPDATED_BY';
			$select[] = 'CREATED_TIME';
		}

		$dataManager = Container::getStorageRecordDataManager();
		$result = $dataManager::getList([
			'select' => $select,
			'filter' => [
				'=STORAGE_ID' => $storageTypeId,
				'=WORKFLOW_ID' => [RowIdentity::WORKFLOW_SYSTEM, RowIdentity::WORKFLOW_FROZEN],
			],
			'order' => ['ID' => 'ASC'],
		]);

		$systemIdsByCode = [];
		$frozenCodes = [];
		$orphanIds = [];
		$storedStateByCode = [];
		while ($row = $result->fetch())
		{
			$identity = RowIdentity::fromSerialized($row['CODE'] !== null ? (string)$row['CODE'] : null);

			if ((string)$row['WORKFLOW_ID'] === RowIdentity::WORKFLOW_FROZEN)
			{
				if ($identity !== null)
				{
					$frozenCodes[$identity->serialize()] = true;
				}

				continue;
			}

			if ($identity === null || isset($systemIdsByCode[$identity->serialize()]))
			{
				$orphanIds[] = (int)$row['ID'];

				continue;
			}

			$code = $identity->serialize();
			$systemIdsByCode[$code] = (int)$row['ID'];
			$storedStateByCode[$code] = [
				'values' => $dualWrite && is_array($row['VALUE']) ? $row['VALUE'] : null,
				'updatedTime' => $row['UPDATED_TIME'],
				'header' => $dualWrite
					? [
						'DOCUMENT_ID' => (string)$row['DOCUMENT_ID'],
						'TEMPLATE_ID' => (int)$row['TEMPLATE_ID'],
						'CREATED_BY' => (int)$row['CREATED_BY'],
						'UPDATED_BY' => (int)$row['UPDATED_BY'],
						'CREATED_TIME' => $row['CREATED_TIME'],
					]
					: null,
				'storedValues' => [],
			];
		}

		$storedValuesByRecordId = $this->fieldValueRepository->readStorageValues(array_values($systemIdsByCode));
		foreach ($systemIdsByCode as $code => $id)
		{
			$storedStateByCode[$code]['storedValues'] = $storedValuesByRecordId[$id] ?? [];
		}

		return [$systemIdsByCode, $frozenCodes, $orphanIds, $storedStateByCode];
	}

	private function deleteDataViewRows(array $ids): void
	{
		if (!$ids)
		{
			return;
		}

		$this->fieldValueRepository->deleteByRecordIds($ids);

		$dataManager = Container::getStorageRecordDataManager();
		foreach (array_chunk($ids, self::QUERY_CHUNK) as $chunk)
		{
			$dataManager::deleteByFilter(['=ID' => $chunk]);
		}
	}

	/**
	 * @param DataView $view
	 * @return array
	 */
	private function collectDeletionCodes(DataView $view): array
	{
		$codes = [];
		foreach ($view->getDeletionMarks() as $mark)
		{
			$identity = RowIdentity::fromArray((array)$mark);
			if ($identity !== null)
			{
				$codes[$identity->serialize()] = true;
			}
		}

		return $codes;
	}

	private function updateDataViewItems(
		int $storageTypeId,
		array $rowsByCode,
		array $systemIdsByCode,
		array $storedStateByCode,
	): void
	{
		if (!$rowsByCode)
		{
			return;
		}

		$dualWrite = $this->isDualWriteEnabled();

		$items = [];
		$matchedIds = [];
		$mergeRows = [];
		foreach ($rowsByCode as $code => $row)
		{

			$this->fieldValueRepository->assertKnownFieldCodes($storageTypeId, $row->values);

			$id = $systemIdsByCode[$code];
			$stored = $storedStateByCode[$code] ?? null;

			$item = (new Entity\StorageItem\StorageItem())
				->setId($id)
				->setStorageId($storageTypeId)
				->setValueFields($row->values)
			;

			if ($this->isDataViewRowUnchanged($item, $stored, $dualWrite))
			{
				continue;
			}

			$matchedIds[] = $id;
			$items[] = $item;

			if ($dualWrite)
			{
				$mergeRows[] = $this->buildHeaderMergeRow($storageTypeId, (string)$code, $id, $row, $stored);
			}
		}

		$this->upsertDataViewHeaders($mergeRows);

		if (!$matchedIds)
		{
			return;
		}

		$this->fieldValueRepository->deleteByRecordIds($matchedIds);
		$this->fieldValueRepository->addMultiple($items);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function buildHeaderMergeRow(int $storageTypeId, string $code, int $id, CombineRow $row, ?array $stored): array
	{
		$header = $stored['header'] ?? [];

		return [
			'ID' => $id,
			'STORAGE_ID' => $storageTypeId,
			'CODE' => $code,
			'DOCUMENT_ID' => (string)($header['DOCUMENT_ID'] ?? ''),
			'WORKFLOW_ID' => RowIdentity::WORKFLOW_SYSTEM,
			'TEMPLATE_ID' => (int)($header['TEMPLATE_ID'] ?? 0),
			'CREATED_BY' => (int)($header['CREATED_BY'] ?? 0),
			'UPDATED_BY' => (int)($header['UPDATED_BY'] ?? 0),
			'CREATED_TIME' => $header['CREATED_TIME'] instanceof DateTime ? $header['CREATED_TIME'] : new DateTime(),
			'UPDATED_TIME' => $stored['updatedTime'] instanceof DateTime ? $stored['updatedTime'] : new DateTime(),
			'VALUE' => $this->encodeHeaderValue($row->values),
		];
	}

	private function encodeHeaderValue(array $values): string
	{
		$valueField = Container::getStorageRecordDataManager()::getEntity()->getField('VALUE');
		if (!$valueField instanceof ArrayField)
		{
			throw new CreateStorageItemException('Storage record VALUE field is not serializable.');
		}

		return (string)$valueField->encode($values);
	}

	private function upsertDataViewHeaders(array $mergeRows): void
	{
		if (!$mergeRows)
		{
			return;
		}

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();
		$tableName = Container::getStorageRecordDataManager()::getTableName();

		foreach (array_chunk($mergeRows, self::QUERY_CHUNK) as $chunk)
		{
			try
			{
				$connection->queryExecute(
					$helper->prepareMergeValues($tableName, ['ID'], $chunk, ['VALUE', 'UPDATED_TIME']),
				);
			}
			catch (\Throwable $exception)
			{
				throw new CreateStorageItemException($exception->getMessage());
			}
		}
	}

	private function isDataViewRowUnchanged(Entity\StorageItem\StorageItem $item, ?array $stored, bool $dualWrite): bool
	{
		if ($stored === null)
		{
			return false;
		}

		if ($dualWrite && $stored['values'] !== $item->getValueFields())
		{
			return false;
		}

		return $stored['storedValues'] === $this->fieldValueRepository->buildStorageValues($item);
	}

	private function insertDataViewItems(int $storageTypeId, string $documentId, array $rows, int $actorId): void
	{
		if (!$rows)
		{
			return;
		}

		$dualWrite = $this->isDualWriteEnabled();
		$now = new DateTime();

		$headerRows = [];
		foreach ($rows as $row)
		{

			$this->fieldValueRepository->assertKnownFieldCodes($storageTypeId, $row->values);

			$header = [
				'STORAGE_ID' => $storageTypeId,
				'CODE' => $row->getIdentity()->serialize(),
				'DOCUMENT_ID' => $documentId,
				'WORKFLOW_ID' => RowIdentity::WORKFLOW_SYSTEM,
				'TEMPLATE_ID' => 0,
				'CREATED_BY' => $actorId,
				'UPDATED_BY' => $actorId,
				'CREATED_TIME' => $now,
				'UPDATED_TIME' => $now,
			];
			if ($dualWrite)
			{
				$header['VALUE'] = $row->values;
			}

			$headerRows[] = $header;
		}

		$dataManager = Container::getStorageRecordDataManager();
		foreach (array_chunk($headerRows, self::QUERY_CHUNK) as $chunk)
		{
			$result = $dataManager::addMulti($chunk, true);
			if (!$result->isSuccess())
			{
				throw new CreateStorageItemException($result->getErrors()[0]->getMessage());
			}
		}

		$insertedIds = $this->readDataViewRowIdsByCodes($storageTypeId, array_keys($rows));

		$items = [];
		foreach ($rows as $code => $row)
		{
			$id = $insertedIds[$code] ?? null;
			if ($id === null)
			{
				throw new CreateStorageItemException(ErrorMessage::GET_DATA_ERROR->get());
			}

			$items[] = (new Entity\StorageItem\StorageItem())
				->setId($id)
				->setStorageId($storageTypeId)
				->setValueFields($row->values)
			;
		}

		$this->fieldValueRepository->addMultiple($items);
	}

	private function readDataViewRowIdsByCodes(int $storageTypeId, array $codes): array
	{
		if (!$codes)
		{
			return [];
		}

		$dataManager = Container::getStorageRecordDataManager();

		$ids = [];
		foreach (array_chunk($codes, self::QUERY_CHUNK) as $chunk)
		{
			$result = $dataManager::getList([
				'select' => ['ID', 'CODE'],
				'filter' => [
					'=STORAGE_ID' => $storageTypeId,
					'=WORKFLOW_ID' => RowIdentity::WORKFLOW_SYSTEM,
					'=CODE' => $chunk,
				],
			]);

			while ($row = $result->fetch())
			{
				$ids[(string)$row['CODE']] = (int)$row['ID'];
			}
		}

		return $ids;
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
