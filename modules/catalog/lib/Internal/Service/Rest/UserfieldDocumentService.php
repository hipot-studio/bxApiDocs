<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Internal\Service\Rest;

use Bitrix\Catalog\Access\ActionDictionary;
use Bitrix\Catalog\Document\StoreDocumentTableManager;
use Bitrix\Catalog\Infrastructure\Rest\Dto\UserfieldDocumentDto;
use Bitrix\Catalog\Infrastructure\Rest\Support\FilterStructureSupport;
use Bitrix\Catalog\StoreDocumentTable;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Text\StringHelper;
use Bitrix\Rest\V3\Structure\Filtering\Condition;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Filtering\Operator;
use Bitrix\Rest\V3\Structure\Ordering\OrderStructure;
use Bitrix\Rest\V3\Structure\PaginationStructure;
use Bitrix\Rest\V3\Structure\SelectStructure;

final readonly class UserfieldDocumentService
{
	public function __construct(
		private AccessSupport $accessSupport,
	)
	{
	}

	public function update(int $documentId, UserfieldDocumentDto $dto): Result
	{
		$existing = $this->loadDocumentRow($documentId);
		if ($existing === null)
		{
			return ServiceResult::notFound($documentId);
		}

		$fields = $this->extractUpdateFields($dto);
		$clientDocType = (string)($fields['DOC_TYPE'] ?? '');
		unset($fields['DOC_TYPE']);

		// ACL + UF entity always follow stored DOC_TYPE; client docType is match-only.
		$documentType = $existing['DOC_TYPE'];
		if ($clientDocType !== '' && $clientDocType !== $documentType)
		{
			return ServiceResult::validation([
				new Error('The docType field does not match the document'),
			]);
		}

		$access = $this->ensureUpdatePermissionByType($documentType);
		if (!$access->isSuccess())
		{
			return $access;
		}

		$entityId = self::getEntityIdForType($documentType);
		if ($entityId !== '')
		{
			$fields = $this->prepareFileFieldsForUpdate($documentId, $documentType, $fields);

			global $USER_FIELD_MANAGER;
			$USER_FIELD_MANAGER->Update($entityId, $documentId, $fields);
		}

		return $this->loadMappedRow($documentId);
	}

	public function list(
		?SelectStructure $select,
		?FilterStructure $filter,
		?OrderStructure $order,
		?PaginationStructure $pagination,
	): Result {
		$selectList = $select?->getList() ?? [];

		$unsupportedFilter = FilterStructureSupport::findUnsupportedSemantics($filter);
		if ($unsupportedFilter !== null)
		{
			return ServiceResult::validation([$unsupportedFilter]);
		}

		$documentType = $this->extractDocType($filter);
		if ($documentType === null || $documentType === '')
		{
			return ServiceResult::validation([
				new Error('The docType field is not specified in the filter parameter'),
			]);
		}

		$access = $this->ensureReadPermissionByType($documentType);
		if (!$access->isSuccess())
		{
			return $access;
		}

		// the filter is one conjunction - FilterStructureSupport rejects OR and NOT - so an empty
		// IN list leaves it unsatisfiable; this shortcut has to go when OR groups get supported.
		// D7 would render the list as `IN ()` and the request would fail on the SQL syntax.
		if (FilterStructureSupport::hasEmptyInCondition($filter))
		{
			return ServiceResult::success(['items' => []]);
		}

		$tableClass = StoreDocumentTableManager::getTableClassByType($documentType);
		if ($tableClass === '')
		{
			return ServiceResult::success(['items' => []]);
		}

		$ormSelect = $this->toOrmSelect($selectList, $documentType);
		$ormFilter = $this->toOrmFilterWithoutDocType($filter);
		$ormOrder = $this->toOrmOrder($order);

		$rows = $tableClass::getList([
			'select' => $ormSelect,
			'filter' => $ormFilter,
			'order' => $ormOrder,
			'offset' => $pagination?->getOffset() ?? 0,
			'limit' => $pagination?->getLimit() ?? PaginationStructure::DEFAULT_LIMIT,
		])->fetchAll();

		foreach ($rows as &$row)
		{
			if (!isset($row['DOC_TYPE']))
			{
				$row['DOC_TYPE'] = $documentType;
			}
		}
		unset($row);

		return ServiceResult::success(['items' => $rows]);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function extractUpdateFields(UserfieldDocumentDto $dto): array
	{
		$fields = [];
		if (isset($dto->docType))
		{
			$fields['DOC_TYPE'] = $dto->docType;
		}

		if (isset($dto->userFields) && is_array($dto->userFields))
		{
			foreach ($dto->userFields as $key => $value)
			{
				$fields[(string)$key] = $value;
			}
		}

		return $this->canonicalizeUserFieldKeys($fields, (string)($fields['DOC_TYPE'] ?? ''));
	}

	/**
	 * Maps FIELD_{id} REST keys to UF FIELD_NAME (Engine DocumentUserField).
	 *
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed>
	 */
	private function canonicalizeUserFieldKeys(array $fields, string $documentType): array
	{
		if ($documentType === '')
		{
			return $fields;
		}

		$fieldMap = $this->getFieldIdToNameMap($documentType);
		$result = [];
		foreach ($fields as $key => $value)
		{
			$key = (string)$key;
			if ($key === 'DOC_TYPE')
			{
				$result[$key] = $value;

				continue;
			}

			if (preg_match('/^FIELD_(\d+)$/', $key, $matches) === 1)
			{
				$fieldId = (int)$matches[1];
				if (isset($fieldMap[$fieldId]))
				{
					$result[$fieldMap[$fieldId]] = $value;

					continue;
				}
			}

			$result[$key] = $value;
		}

		return $result;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function loadUserFields(string $documentType, int $documentId): array
	{
		$entityId = self::getEntityIdForType($documentType);
		if ($entityId === '')
		{
			return [];
		}

		global $USER_FIELD_MANAGER;

		return $USER_FIELD_MANAGER->GetUserFields($entityId, $documentId);
	}

	/**
	 * @return array<int, string>
	 */
	private function getFieldIdToNameMap(string $documentType): array
	{
		$map = [];
		foreach ($this->loadUserFields($documentType, 0) as $userField)
		{
			$map[(int)$userField['ID']] = (string)$userField['FIELD_NAME'];
		}

		return $map;
	}

	/**
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed>
	 */
	private function prepareFileFieldsForUpdate(int $documentId, string $documentType, array $fields): array
	{
		$preparedFields = $fields;
		$userFields = $this->loadUserFields($documentType, $documentId);
		foreach ($preparedFields as $fieldId => $field)
		{
			if (isset($userFields[$fieldId]) && $userFields[$fieldId]['USER_TYPE_ID'] === 'file')
			{
				if ($userFields[$fieldId]['MULTIPLE'] === 'Y')
				{
					$preparedFields[$fieldId][] = [
						'old_id' => $userFields[$fieldId]['VALUE'],
					];
				}
				else
				{
					$preparedFields[$fieldId]['old_id'] = $userFields[$fieldId]['VALUE'];
				}
			}
		}

		return $preparedFields;
	}

	private function loadMappedRow(int $id): Result
	{
		$row = StoreDocumentTable::getRow([
			'select' => ['DOC_TYPE'],
			'filter' => ['=ID' => $id],
		]);
		$documentType = (string)($row['DOC_TYPE'] ?? '');
		if ($documentType === '')
		{
			return ServiceResult::notFound($id);
		}

		$userFields = [];
		foreach ($this->loadUserFields($documentType, $id) as $value)
		{
			$userFields['FIELD_' . $value['ID']] = $value['VALUE'];
		}

		return ServiceResult::success([
			'item' => [
				'ID' => $id,
				'DOC_TYPE' => $documentType,
				'userFields' => $userFields,
			],
		]);
	}

	/**
	 * @param list<string> $selectList
	 * @return list<string>
	 */
	private function toOrmSelect(array $selectList, string $documentType): array
	{
		// id / docType are always fetched for internal mapping; response still honors client select.
		$ormSelect = ['ID', 'DOC_TYPE'];
		$includeUserFields = false;

		foreach ($selectList as $item)
		{
			if ($item === 'userFields')
			{
				$includeUserFields = true;
			}
		}

		if ($includeUserFields || $selectList === [])
		{
			foreach ($this->getFieldIdToNameMap($documentType) as $fieldName)
			{
				$ormSelect[] = $fieldName;
			}
		}

		return array_values(array_unique($ormSelect));
	}

	/**
	 * @return array<string, mixed>
	 */
	private function toOrmFilterWithoutDocType(?FilterStructure $filter): array
	{
		if ($filter === null)
		{
			return [];
		}

		$result = [];
		$this->appendFilterConditions($filter, $result);
		unset($result['=DOC_TYPE'], $result['DOC_TYPE']);

		return $result;
	}

	/**
	 * @param array<int|string, mixed> $result
	 */
	private function appendFilterConditions(FilterStructure $filter, array &$result): void
	{
		foreach ($filter->getConditions() as $condition)
		{
			if ($condition instanceof FilterStructure)
			{
				$nested = [];
				$this->appendFilterConditions($condition, $nested);
				if ($nested !== [])
				{
					$result[] = $nested;
				}

				continue;
			}

			if (!$condition instanceof Condition)
			{
				continue;
			}

			$field = (string)$condition->getLeftOperand();
			if ($field === 'docType')
			{
				$ormField = 'DOC_TYPE';
			}
			elseif ($field === 'id')
			{
				$ormField = 'ID';
			}
			elseif ($field === 'userFields')
			{
				continue;
			}
			else
			{
				$ormField = strtoupper(StringHelper::camel2snake($field));
			}

			$prefix = match ($condition->getOperator())
			{
				Operator::Equal => '=',
				Operator::NotEqual => '!',
				Operator::Greater => '>',
				Operator::GreaterOrEqual => '>=',
				Operator::Less => '<',
				Operator::LessOrEqual => '<=',
				Operator::In => '@',
				Operator::Between => '><',
			};

			$result[$prefix . $ormField] = $condition->getRightOperand();
		}
	}

	/**
	 * @return array<string, string>
	 */
	private function toOrmOrder(?OrderStructure $order): array
	{
		if ($order === null)
		{
			return [];
		}

		$result = [];
		foreach ($order->getList() as $property => $direction)
		{
			if ($property === 'userFields')
			{
				continue;
			}

			$ormField = match ($property)
			{
				'id' => 'ID',
				'docType' => 'DOC_TYPE',
				default => strtoupper(StringHelper::camel2snake((string)$property)),
			};
			$result[$ormField] = $direction;
		}

		return $result;
	}

	private function extractDocType(?FilterStructure $filter): ?string
	{
		if ($filter === null)
		{
			return null;
		}

		foreach ($filter->getConditions() as $condition)
		{
			if ($condition instanceof FilterStructure)
			{
				$nested = $this->extractDocType($condition);
				if ($nested !== null)
				{
					return $nested;
				}

				continue;
			}

			if (
				$condition instanceof Condition
				&& $condition->getLeftOperand() === 'docType'
				&& $condition->getOperator() === Operator::Equal
			)
			{
				return (string)$condition->getRightOperand();
			}
		}

		return null;
	}

	/**
	 * @return array{ID: int, DOC_TYPE: string}|null
	 */
	private function loadDocumentRow(int $documentId): ?array
	{
		$row = StoreDocumentTable::getRow([
			'select' => ['ID', 'DOC_TYPE'],
			'filter' => ['=ID' => $documentId],
		]);

		if (!$row)
		{
			return null;
		}

		return [
			'ID' => (int)$row['ID'],
			'DOC_TYPE' => (string)$row['DOC_TYPE'],
		];
	}

	private function ensureReadPermissionByType(string $documentType): Result
	{
		$accessController = $this->accessSupport->getAccessController();
		$basePermission
			= $accessController->check(ActionDictionary::ACTION_CATALOG_READ)
			&& $accessController->check(ActionDictionary::ACTION_INVENTORY_MANAGEMENT_ACCESS);
		$typePermission = $accessController->checkByValue(
			ActionDictionary::ACTION_STORE_DOCUMENT_VIEW,
			$documentType,
		);

		if (!($basePermission && $typePermission))
		{
			return ServiceResult::accessDenied();
		}

		return ServiceResult::success();
	}

	private function ensureUpdatePermissionByType(string $documentType): Result
	{
		$readAccess = $this->ensureReadPermissionByType($documentType);
		if (!$readAccess->isSuccess())
		{
			return $readAccess;
		}

		if (!$this->accessSupport->getAccessController()->checkByValue(
			ActionDictionary::ACTION_STORE_DOCUMENT_MODIFY,
			$documentType,
		))
		{
			return ServiceResult::accessDenied();
		}

		return ServiceResult::success();
	}

	private static function getEntityIdForType(string $docType): string
	{
		return StoreDocumentTableManager::getUfEntityIds()[$docType] ?? '';
	}
}
