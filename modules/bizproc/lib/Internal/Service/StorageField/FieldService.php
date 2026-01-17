<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\StorageField;

use Bitrix\Bizproc\Internal\Container;
use Bitrix\Bizproc\Internal\Repository\StorageFieldRepository\StorageFieldRepositoryInterface;
use Bitrix\Bizproc\Public\Provider\Params\StorageField\StorageFieldFilter;
use Bitrix\Bizproc\Internal\Model\StorageRecordTable;
use Bitrix\Bizproc\FieldType;
use Bitrix\Main\ORM\Fields\Field;

class FieldService
{
	private array $cache = [];
	private StorageFieldRepositoryInterface $fieldRepository;
	private ?int $storageTypeId;

	public function __construct(?int $storageTypeId = null)
	{
		$this->storageTypeId = $storageTypeId;
		$this->fieldRepository = Container::getStorageFieldRepository();
	}

	public function getEntityFields(): array
	{
		$fields = [];
		foreach (StorageRecordTable::getEntity()->getFields() as $field)
		{
			$fields[] = [
				'ID' => $field->getName(),
				'NAME' => $field->getTitle(),
				'TYPE' => $this->normalizeFieldType($field),
			];
		}

		return $fields;
	}

	public function getDynamicFields(): array
	{
		if (!$this->storageTypeId)
		{
			return [];
		}

		if (isset($this->cache[$this->storageTypeId]))
		{
			return $this->cache[$this->storageTypeId];
		}

		$fieldCodes = [];

		if (isset($this->fieldRepository))
		{
			$fields = $this->fieldRepository->getList(filter: new StorageFieldFilter([
				'STORAGE_ID' => $this->storageTypeId
			]));

			foreach ($fields as $field)
			{
				$fieldCodes[] = $field;
			}

			$this->cache[$this->storageTypeId] = $fieldCodes;
		}

		return $fieldCodes;
	}

	private function normalizeFieldType(Field $field): string
	{
		if (in_array($field->getName(), ['CREATED_BY', 'UPDATED_BY'], true))
		{
			return FieldType::USER;
		}

		return match ($field->getDataType())
		{
			'integer' => FieldType::INT,
			'datetime' => FieldType::DATETIME,
			default => is_string($field->getDataType())
				? $field->getDataType()
				: FieldType::STRING
			,
		};
	}
}
