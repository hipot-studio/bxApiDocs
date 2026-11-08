<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\Sale\Location;

use Bitrix\Crm\V2\Internal\Service\Location\EffectiveScope;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;
use Bitrix\Main\UI\Filter\NumberType;
use Bitrix\Sale\Location;
use Bitrix\Sale\Location\Admin\LocationHelper;

class LocationAdapter
{
	private const LEGACY_PATH_TYPE_CODES = ['COUNTRY', 'REGION', 'CITY'];
	private const NAME_RUNTIME = 'NAME_CURRENT';
	private const LOCALIZED_NAME_FIELDS = ['NAME', 'SHORT_NAME'];
	private const NUMERIC_FILTER_TYPES = ['integer', 'float'];
	private const NUMERIC_FILTER_OPERATORS = ['>=', '<=', '=', '>', '<'];

	public function __construct()
	{
		Loader::requireModule('sale');
	}

	public function isIntegrityValid(): bool
	{
		return Location\LocationTable::checkIntegrity();
	}

	public function getFilterColumns(): array
	{
		return LocationHelper::getFilterColumns();
	}

	public function getListGridColumns(): array
	{
		return LocationHelper::getListGridColumns();
	}

	public function getTypeList(): array
	{
		return LocationHelper::getTypeList();
	}

	public function performGridOperations(array $parameters): array
	{
		return LocationHelper::performGridOperations($parameters);
	}

	public function deleteRoot(int $id, bool $batch = false): Result
	{
		$result = new Result();
		try
		{
			$deleteResult = LocationHelper::delete($id, $batch);
		}
		catch (SystemException $exception)
		{
			if ($this->isMissingLocationException($exception))
			{
				return $result;
			}

			throw $exception;
		}

		if ($deleteResult['success'] ?? false)
		{
			return $result;
		}

		foreach (($deleteResult['errors'] ?? []) as $errorMessage)
		{
			$result->addError(new Error((string)$errorMessage, 'LOCATION_DELETE_FAILED'));
		}

		if ($result->isSuccess())
		{
			$result->addError(new Error('Location cannot be deleted.', 'LOCATION_DELETE_FAILED'));
		}

		return $result;
	}

	public function requiresLegacyPathReset(int $id): ?bool
	{
		$location = Location\LocationTable::getList([
			'filter' => ['=ID' => $id],
			'select' => ['TYPE_CODE' => 'TYPE.CODE'],
			'limit' => 1,
		])->fetch();
		if (!is_array($location))
		{
			return null;
		}

		return in_array((string)($location['TYPE_CODE'] ?? ''), self::LEGACY_PATH_TYPE_CODES, true);
	}

	public function finalizeBatchDelete(): void
	{
		Location\LocationTable::resetLegacyPath();
	}

	private function isMissingLocationException(SystemException $exception): bool
	{
		return $exception instanceof Location\Tree\NodeNotFoundException
			|| (
				$exception::class === SystemException::class
				&& $exception->getMessage() === Loc::getMessage('SALE_LOCATION_TREE_ENTITY_NODE_NOT_FOUND_EXCEPTION')
			);
	}

	public function normalize(array $rawFilter, ?int $parentId, string $find, string $languageId): array
	{
		$languageId = mb_strtoupper($languageId);
		$allowedFields = LocationHelper::getFilterColumns();
		$rawFilter += NumberType::getLogicFilter($rawFilter, array_keys($allowedFields));
		$filter = [];
		$numericFilter = [];

		foreach ($rawFilter as $fieldId => $value)
		{
			$fieldId = (string)$fieldId;
			if (
				$fieldId === 'PARENT_ID'
				|| $fieldId === 'FIND'
				|| $value === ''
				|| $value === null
			)
			{
				continue;
			}

			$numericFilterField = $this->getNumericFilterField($fieldId, $allowedFields);
			if ($numericFilterField !== null)
			{
				if (isset($allowedFields[$numericFilterField]))
				{
					$numericFilter[$fieldId] = $value;
				}

				continue;
			}
			if (!isset($allowedFields[$fieldId]))
			{
				continue;
			}

			$filter[$fieldId] = $value;
		}

		if ($parentId !== null)
		{
			$filter['PARENT_ID'] = $parentId;
		}

		$parameters = LocationHelper::getParametersForList([
			'FILTER' => $filter,
			'ORDER' => [],
		]);
		$domainFilter = $parameters['filter'] ?? [];
		foreach ($numericFilter as $fieldId => $value)
		{
			$domainFilter[$fieldId] = $value;
		}
		$nameAlias = 'NAME__' . $languageId;

		foreach ($domainFilter as $fieldId => $value)
		{
			$normalizedFieldId = str_replace(
				$nameAlias . '.',
				self::NAME_RUNTIME . '.',
				(string)$fieldId,
			);
			if ($normalizedFieldId !== $fieldId)
			{
				unset($domainFilter[$fieldId]);
				$domainFilter[$normalizedFieldId] = $value;
			}
		}
		if ($parentId !== null)
		{
			$domainFilter['=PARENT_ID'] = $parentId;
		}
		if ($find !== '')
		{
			$domainFilter['%=' . self::NAME_RUNTIME . '.NAME_UPPER'] = mb_strtoupper($find) . '%';
		}

		return $domainFilter;
	}

	private function getNumericFilterField(string $fieldId, array $allowedFields): ?string
	{
		foreach (self::NUMERIC_FILTER_OPERATORS as $operator)
		{
			if (!str_starts_with($fieldId, $operator))
			{
				continue;
			}

			$baseFieldId = substr($fieldId, strlen($operator));
			if ($baseFieldId === 'PARENT_ID')
			{
				return null;
			}
			$dataType = (string)($allowedFields[$baseFieldId]['data_type'] ?? '');

			return in_array($dataType, self::NUMERIC_FILTER_TYPES, true) ? $baseFieldId : null;
		}

		return null;
	}

	public function allowSort(array $savedSort, string $languageId): array
	{
		$allowedFields = LocationHelper::getListGridColumns();
		$sort = [];
		foreach ($savedSort as $fieldId => $direction)
		{
			$fieldId = (string)$fieldId;
			if (!isset($allowedFields[$fieldId]))
			{
				continue;
			}

			$localizedNameRuntimeField = $this->getLocalizedNameRuntimeField($fieldId, $languageId);
			if ($this->isLocalizedNameField($fieldId) && $localizedNameRuntimeField === null)
			{
				continue;
			}

			$direction = mb_strtoupper((string)$direction);
			if (!in_array($direction, ['ASC', 'DESC'], true))
			{
				continue;
			}

			$sortFieldId = $localizedNameRuntimeField ?? match ($fieldId)
			{
				'TYPE_ID' => 'TYPE.SORT',
				default => $fieldId,
			};
			$sort[$sortFieldId] = $direction;
		}

		return $sort !== [] ? $sort : ['ID' => 'ASC'];
	}

	public function allowSelect(array $requestedSelect, string $languageId): array
	{
		$allowedFields = LocationHelper::getListGridColumns();
		$select = [];

		foreach ($requestedSelect as $fieldId)
		{
			$fieldId = (string)$fieldId;
			if (!isset($allowedFields[$fieldId]))
			{
				continue;
			}

			$localizedNameRuntimeField = $this->getLocalizedNameRuntimeField($fieldId, $languageId);
			if ($this->isLocalizedNameField($fieldId))
			{
				if ($localizedNameRuntimeField !== null)
				{
					$select[$fieldId] = $localizedNameRuntimeField;
				}

				continue;
			}

			$select[] = $fieldId;
		}

		if (!in_array('ID', $select, true))
		{
			array_unshift($select, 'ID');
		}

		return $select;
	}

	private function getLocalizedNameRuntimeField(string $fieldId, string $languageId): ?string
	{
		$currentLanguageId = mb_strtoupper($languageId);
		foreach (self::LOCALIZED_NAME_FIELDS as $nameField)
		{
			$prefix = $nameField . '_';
			if (!str_starts_with($fieldId, $prefix))
			{
				continue;
			}

			$fieldLanguageId = mb_strtoupper(substr($fieldId, strlen($prefix)));
			if ($fieldLanguageId === '')
			{
				return null;
			}
			$runtimeId = $fieldLanguageId === $currentLanguageId
				? self::NAME_RUNTIME
				: 'NAME__' . $fieldLanguageId;

			return $runtimeId . '.' . $nameField;
		}

		return null;
	}

	private function isLocalizedNameField(string $fieldId): bool
	{
		foreach (self::LOCALIZED_NAME_FIELDS as $nameField)
		{
			if (str_starts_with($fieldId, $nameField . '_'))
			{
				return true;
			}
		}

		return false;
	}

	public function nameRuntime(string $languageId): array
	{
		return [
			self::NAME_RUNTIME => $this->createNameRuntime(self::NAME_RUNTIME, $languageId),
		];
	}

	public function fetchRows(EffectiveScope $scope, int $limit, int $offset): array
	{
		$query = $this->createQuery($scope, true)
			->setSelect($scope->select)
			->setOrder($scope->sort)
			->setLimit($limit)
			->setOffset($offset)
		;

		return $query->fetchAll();
	}

	public function fetchCount(EffectiveScope $scope): int
	{
		return (int)$this->createQuery($scope)->queryCountTotal();
	}

	public function fetchMaxId(EffectiveScope $scope): int
	{
		$row = $this->createQuery($scope)
			->setSelect(['ID'])
			->setOrder(['ID' => 'DESC'])
			->setLimit(1)
			->fetch()
		;

		return (int)($row['ID'] ?? 0);
	}

	public function fetchIds(EffectiveScope $scope, int $limit): array
	{
		if ($limit <= 0)
		{
			return [];
		}

		$rows = $this->createQuery($scope)
			->setSelect(['ID'])
			->setOrder(['ID' => 'ASC'])
			->setLimit($limit)
			->fetchAll()
		;

		return array_map(static fn(array $row): int => (int)$row['ID'], $rows);
	}

	public function fetchAllIds(EffectiveScope $scope): array
	{
		$rows = $this->createQuery($scope)
			->setSelect(['ID'])
			->fetchAll()
		;

		return array_map(static fn(array $row): int => (int)$row['ID'], $rows);
	}

	private function createQuery(EffectiveScope $scope, bool $includeProjection = false): Query
	{
		$query = Location\LocationTable::query();
		foreach ($this->getRequestedNameRuntimes($scope, $includeProjection) as $runtimeId => $languageId)
		{
			if (array_key_exists($runtimeId, $scope->runtime))
			{
				$query->registerRuntimeField($scope->runtime[$runtimeId]);

				continue;
			}
			if ($runtimeId !== self::NAME_RUNTIME)
			{
				$query->registerRuntimeField($this->createNameRuntime($runtimeId, $languageId));
			}
		}

		return $query->setFilter($scope->filter);
	}

	private function getRequestedNameRuntimes(EffectiveScope $scope, bool $includeProjection): array
	{
		$fieldIds = array_keys($scope->filter);
		if ($includeProjection)
		{
			$fieldIds = array_merge($fieldIds, array_keys($scope->sort), array_values($scope->select));
		}

		$availableRuntimes = [self::NAME_RUNTIME => ''];
		foreach (LocationHelper::getLanguageList() as $languageId)
		{
			$languageId = (string)$languageId;
			$availableRuntimes['NAME__' . mb_strtoupper($languageId)] = mb_strtolower($languageId);
		}

		$requestedRuntimes = [];
		foreach ($fieldIds as $fieldId)
		{
			$fieldId = (string)$fieldId;
			foreach ($availableRuntimes as $runtimeId => $languageId)
			{
				if (str_contains($fieldId, $runtimeId . '.'))
				{
					$requestedRuntimes[$runtimeId] = $languageId;
				}
			}
		}

		return $requestedRuntimes;
	}

	private function createNameRuntime(string $runtimeId, string $languageId): Reference
	{
		return (new Reference(
			$runtimeId,
			Location\Name\LocationTable::class,
			Join::on('this.ID', 'ref.LOCATION_ID')
				->where('ref.LANGUAGE_ID', mb_strtolower($languageId)),
		))->configureJoinType(Join::TYPE_LEFT);
	}
}
