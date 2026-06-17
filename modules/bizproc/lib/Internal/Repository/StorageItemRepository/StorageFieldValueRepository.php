<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Repository\StorageItemRepository;

use Bitrix\Bizproc\Internal\Entity;
use Bitrix\Bizproc\Internal\Exception\StorageItem\CreateStorageItemException;
use Bitrix\Bizproc\Internal\Model\StorageRecordFieldTable;
use Bitrix\Bizproc\Internal\Repository\StorageFieldRepository\StorageFieldRepositoryInterface;
use Bitrix\Bizproc\Internal\Service\StorageField\Converter\ValueFieldConverter;
use Bitrix\Bizproc\Public\Service\StorageField\FieldService;

class StorageFieldValueRepository
{
	private const QUERY_CHUNK = 500;
	private array $fieldMapCache = [];

	public function __construct(
		private readonly StorageFieldRepositoryInterface $fieldRepository,
	)
	{
	}

	public function add(Entity\StorageItem\StorageItem $item): void
	{
		$recordId = $item->getId();
		$data = [];

		foreach ($this->buildFieldRows($item) as $fieldId => $rows)
		{
			foreach ($rows as $row)
			{
				$data[] = ['RECORD_ID' => $recordId, 'FIELD_ID' => $fieldId] + $row;
			}
		}

		if (!$data)
		{
			return;
		}

		$result = StorageRecordFieldTable::addMulti($data, true);
		if (!$result->isSuccess())
		{
			throw new CreateStorageItemException($result->getErrors()[0]->getMessage());
		}
	}

	public function sync(Entity\StorageItem\StorageItem $item): void
	{
		$this->deleteByRecordId($item->getId());
		$this->add($item);
	}

	public function deleteByRecordIds(array $recordIds): void
	{
		foreach (array_chunk($recordIds, self::QUERY_CHUNK) as $chunk)
		{
			StorageRecordFieldTable::deleteByFilter(['=RECORD_ID' => $chunk]);
		}
	}

	public function deleteByRecordId(int $recordId): void
	{
		StorageRecordFieldTable::deleteByFilter(['=RECORD_ID' => $recordId]);
	}

	/**
	 * Loads field values grouped by record ID.
	 *
	 * @param int[] $recordIds
	 * @return array<int, array<string, mixed>>
	 */
	public function loadFieldValues(array $recordIds, int $storageTypeId = 0, ?array $fieldCodeFilter = null): array
	{
		if (!$recordIds)
		{
			return [];
		}

		$fieldCodes = [];
		if ($storageTypeId > 0)
		{
			$fieldMap = $this->getFieldMap($storageTypeId);
			foreach ($recordIds as $recordId)
			{
				foreach ($fieldMap as $code => $field)
				{
					$fieldCodes[$recordId][$code] = $field->getMultiple() ? [] : null;
				}
			}
		}

		if ($fieldCodeFilter !== null && empty($fieldCodeFilter))
		{
			return $fieldCodes;
		}

		$query = StorageRecordFieldTable::query()
			->setSelect([
				'RECORD_ID',
				'VALUE',
				'VALUE_NUM',
				'CODE' => 'FIELD.CODE',
				'MULTIPLE' => 'FIELD.MULTIPLE',
				'TYPE' => 'FIELD.TYPE',
			])
			->whereIn('RECORD_ID', $recordIds)
			->setOrder([
				'RECORD_ID' => 'ASC',
				'FIELD.CODE' => 'ASC',
			])
		;

		if ($fieldCodeFilter !== null)
		{
			$query->whereIn('FIELD.CODE', $fieldCodeFilter);
		}

		$result = $query->exec();

		while ($row = $result->fetchObject())
		{
			$recordId = $row->getRecordId();
			$field = $row->getField();
			if (!$field)
			{
				continue;
			}

			$code = $field->getCode();

			$value = FieldService::isNumericFieldType($field->getType())
				? $row->getValueNum()
				: $row->getValue()
			;

			$value = ValueFieldConverter::fromStorage($value, $field->getType());

			if ($field->getMultiple())
			{
				$fieldCodes[$recordId][$code] ??= [];
				if (!\CBPHelper::isEmptyValue($value))
				{
					$fieldCodes[$recordId][$code][] = $value;
				}
			}
			else
			{
				$fieldCodes[$recordId][$code] = $value ?? null;
			}
		}

		return $fieldCodes;
	}

	private function buildFieldRows(Entity\StorageItem\StorageItem $item): array
	{
		$fieldMap = $this->getFieldMap($item->getStorageId());
		$rows = [];

		foreach ($item->getValueFields() as $code => $value)
		{
			$field = $fieldMap[$code] ?? null;
			if (!$field)
			{
				continue;
			}

			$fieldId = $field->getId();
			$storedValues = ValueFieldConverter::toStorageValues($value, $field->getType(), $field->getMultiple());

			foreach ($storedValues as $storedValue)
			{
				$rows[$fieldId][] = [
					'VALUE' => $storedValue,
					'VALUE_NUM' => FieldService::isNumericFieldType($field->getType()) ? $storedValue : null,
				];
			}
		}

		return $rows;
	}

	public function getFieldMap(int $storageTypeId): array
	{
		if (isset($this->fieldMapCache[$storageTypeId]))
		{
			return $this->fieldMapCache[$storageTypeId];
		}

		$fields = $this->fieldRepository->getByStorageId(
			$storageTypeId,
			[
				'ID',
				'CODE',
				'TYPE',
				'MULTIPLE',
			],
			true,
		);

		$map = [];
		foreach ($fields as $field)
		{
			$map[$field->getCode()] = $field;
		}

		return $this->fieldMapCache[$storageTypeId] = $map;
	}
}
