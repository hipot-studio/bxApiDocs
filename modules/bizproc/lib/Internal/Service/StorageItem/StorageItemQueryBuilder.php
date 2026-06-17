<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\StorageItem;

use Bitrix\Bizproc\Internal\Model\StorageRecordFieldTable;
use Bitrix\Bizproc\Internal\Container;
use Bitrix\Bizproc\Public\Service\StorageField\FieldService;
use Bitrix\Bizproc\Public\Provider\Params\StorageItem\StorageItemFilter;
use Bitrix\Bizproc\Internal\Entity\StorageItem\StorageItemQueryDto;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Query;

final class StorageItemQueryBuilder
{
	private const FIELD_PREFIX = 'FIELD_';
	private const VALUE_COLUMN = 'VALUE';
	private const VALUE_NUM_COLUMN = 'VALUE_NUM';
	private const MAX_FILTER_DEPTH = 10;
	private const NEGATION_OPERATORS = ['!' => true, '!=' => true, '!%' => true, '!?' => true, '!@' => true];

	private readonly array $lowerFieldMap;
	private ?array $fieldById = null;

	public function __construct(private readonly array $fieldMap)
	{
		$this->lowerFieldMap = $this->buildLowerFieldMap();
	}

	public function build(int $storageTypeId, StorageItemQueryDto $query): array
	{
		$orBranches = $this->tryExtractOrBranches($query->filter);

		if (
			$orBranches !== null
			&& $this->isCrossFieldOr($orBranches, $this->lowerFieldMap)
			&& $this->canUseUnion($query, $this->lowerFieldMap)
		)
		{
			return $this->buildUnionPlan($storageTypeId, $query, $orBranches, $this->lowerFieldMap);
		}

		return $this->buildSingle($storageTypeId, $query);
	}

	private function buildSingle(int $storageTypeId, StorageItemQueryDto $query): array
	{
		[$ormSelect, $fieldCodes] = $this->splitSelect($query->select, $this->lowerFieldMap);

		$dataManager = Container::getStorageRecordDataManager();
		$ormQuery =
			$dataManager::query()
				->setSelect($ormSelect ?: ['ID'])
				->where('STORAGE_ID', $storageTypeId)
		;

		$order = $query->order;
		$group = $query->group;

		if ($this->fieldMap)
		{
			$order = $this->resolveFieldAliases($order, $this->lowerFieldMap);
			$group = $this->resolveFieldAliases($group, $this->lowerFieldMap);

			if ($query->filter !== null)
			{
				$this->registerFilterJoins($ormQuery, $query->filter, $this->lowerFieldMap);
			}

			$this->registerOrderGroupJoins($ormQuery, array_keys($order));
			$this->registerOrderGroupJoins($ormQuery, $group);
		}

		if ($query->filter !== null)
		{
			$ormQuery->where($query->filter->prepareFilter($this->fieldMap));
		}

		if ($query->limit !== null)
		{
			$ormQuery->setLimit($query->limit);
		}

		if ($query->offset !== null)
		{
			$ormQuery->setOffset($query->offset);
		}

		if ($order)
		{
			$ormQuery->setOrder($order);
		}

		if ($group)
		{
			$ormQuery->setGroup($group);
		}

		return [$ormQuery, $fieldCodes];
	}

	/**
	 * @return array[]|null Array of branch condition arrays, or null if not a top-level OR.
	 */
	private function tryExtractOrBranches(?StorageItemFilter $filter): ?array
	{
		if ($filter === null)
		{
			return null;
		}

		$raw = $filter->getRawFilter();

		if (($raw['LOGIC'] ?? null) !== 'OR')
		{
			return null;
		}

		$branches = [];
		foreach ($raw as $key => $value)
		{
			if (is_numeric($key) && is_array($value) && !empty($value))
			{
				$branches[] = $value;
			}
		}

		return count($branches) >= 2 ? $branches : null;
	}

	private function isCrossFieldOr(array $branches, array $lowerFieldMap): bool
	{
		if (empty($lowerFieldMap))
		{
			return false;
		}

		$firstFields = null;
		foreach ($branches as $branch)
		{
			$fields = array_keys($this->collectBranchFields($branch, $lowerFieldMap));
			sort($fields);

			if ($firstFields === null)
			{
				$firstFields = $fields;
			}
			elseif ($fields !== $firstFields)
			{
				return true;
			}
		}

		return false;
	}

	private function collectBranchFields(array $branch, array $lowerFieldMap): array
	{
		$fields = [];

		foreach ($branch as $key => $value)
		{
			if (is_string($key) && $key !== 'LOGIC')
			{
				$cleanKey = ltrim($key, '!<>=%@?');
				$lowerKey = mb_strtolower($cleanKey);
				if (isset($lowerFieldMap[$lowerKey]))
				{
					$fields[$lowerKey] = true;
				}
			}

			if (is_array($value) && is_numeric($key))
			{
				foreach ($this->collectBranchFields($value, $lowerFieldMap) as $fieldKey => $_)
				{
					$fields[$fieldKey] = true;
				}
			}
		}

		return $fields;
	}

	private function canUseUnion(StorageItemQueryDto $query, array $lowerFieldMap): bool
	{
		if (!empty($query->group))
		{
			return false;
		}

		foreach ($query->select as $item)
		{
			if ($item instanceof ExpressionField)
			{
				return false;
			}
		}

		foreach (array_keys($query->order) as $field)
		{
			if (isset($lowerFieldMap[mb_strtolower((string)$field)]))
			{
				return false;
			}
		}

		$commonRaw = $query->filter !== null
			? $this->extractCommonRawFilter($query->filter->getRawFilter())
			: []
		;
		if (!empty($commonRaw))
		{
			return false;
		}

		return true;
	}

	private function buildUnionPlan(
		int $storageTypeId,
		StorageItemQueryDto $query,
		array $branches,
		array $lowerFieldMap,
	): array
	{
		[$ormSelect, $fieldCodes] = $this->splitSelect($query->select, $lowerFieldMap);

		$branchSelect = ['ID'];
		foreach (array_keys($query->order) as $orderField)
		{
			$branchSelect[] = (string)$orderField;
		}
		$branchSelect = array_unique($branchSelect);

		$unionQuery = null;

		foreach ($branches as $branch)
		{
			$branchFilter = new StorageItemFilter([$branch]);

			$branchQuery =
				StorageRecordTable::query()
					->setSelect($branchSelect)
					->where('STORAGE_ID', $storageTypeId)
			;

			if ($this->fieldMap)
			{
				$this->registerFilterJoins($branchQuery, $branchFilter, $lowerFieldMap);
			}

			$branchQuery->where($branchFilter->prepareFilter($this->fieldMap));

			if ($unionQuery === null)
			{
				$unionQuery = $branchQuery;
			}
			else
			{
				$unionQuery->union($branchQuery);
			}
		}

		if ($query->order)
		{
			$unionQuery->setUnionOrder($query->order);
		}

		if ($query->limit !== null)
		{
			$unionQuery->setUnionLimit($query->limit);
		}

		if ($query->offset !== null)
		{
			$unionQuery->setUnionOffset($query->offset);
		}

		$ids = [];
		$result = $unionQuery->exec();
		while ($row = $result->fetch())
		{
			$ids[] = (int)$row['ID'];
		}

		$resultQuery =
			StorageRecordTable::query()
				->setSelect($ormSelect ?: ['ID'])
				->where('STORAGE_ID', $storageTypeId)
		;

		if (empty($ids))
		{
			$resultQuery->where('ID', 0);
		}
		else
		{
			$resultQuery->whereIn('ID', $ids);

			if ($query->order)
			{
				$resultQuery->setOrder($query->order);
			}
		}

		return [$resultQuery, $fieldCodes];
	}

	private function extractCommonRawFilter(array $rawFilter): array
	{
		$common = [];
		foreach ($rawFilter as $key => $value)
		{
			if ($key !== 'LOGIC' && !is_numeric($key))
			{
				$common[$key] = $value;
			}
		}

		return $common;
	}

	private function splitSelect(array $select, array $lowerFieldMap): array
	{
		if (empty($select) || in_array('*', $select, true))
		{
			return [$select, null];
		}

		$ormSelect = [];
		$fieldCodes = [];

		foreach ($select as $item)
		{
			if (!is_string($item))
			{
				$ormSelect[] = $item;

				continue;
			}

			$lowerItem = mb_strtolower($item);

			if (isset($lowerFieldMap[$lowerItem]))
			{
				$fieldCodes[] = $lowerFieldMap[$lowerItem]->getCode();
			}
			else
			{
				$ormSelect[] = $item;
			}
		}

		return [$ormSelect, $fieldCodes];
	}

	private function resolveFieldAliases(array $keys, array $lowerFieldMap): array
	{
		if (empty($keys) || empty($lowerFieldMap))
		{
			return $keys;
		}

		$resolved = [];

		foreach ($keys as $key => $value)
		{
			$isIndexed = is_int($key);
			$fieldCode = $isIndexed ? $value : $key;
			$lowerCode = mb_strtolower((string)$fieldCode);

			if (isset($lowerFieldMap[$lowerCode]))
			{
				$alias = $this->buildValueAlias($lowerFieldMap[$lowerCode]);

				$isIndexed ? ($resolved[] = $alias) : ($resolved[$alias] = $value);
			}
			else
			{
				$isIndexed ? ($resolved[] = $value) : ($resolved[$key] = $value);
			}
		}

		return $resolved;
	}

	private function buildValueAlias(object $field): string
	{
		$column =
			FieldService::isNumericFieldType($field->getType())
				? self::VALUE_NUM_COLUMN
				: self::VALUE_COLUMN
		;

		return self::FIELD_PREFIX . $field->getId() . '.' . $column;
	}

	private function registerFilterJoins(Query $query, StorageItemFilter $filter, array $lowerFieldMap): void
	{
		$this->walkFilterTree($query, $filter->getRawFilter(), $lowerFieldMap, 0);
	}

	private function walkFilterTree(Query $query, array $filter, array $lowerFieldMap, int $depth): void
	{
		if ($depth >= self::MAX_FILTER_DEPTH)
		{
			return;
		}

		foreach ($filter as $key => $value)
		{
			if (is_string($key))
			{
				$cleanKey = ltrim($key, '!<>=%@?');
				$lowerKey = mb_strtolower($cleanKey);

				if (isset($lowerFieldMap[$lowerKey]))
				{
					$operator = substr($key, 0, strlen($key) - strlen($cleanKey));
					$joinType = $this->needsLeftJoin($operator, $value)
						? Join::TYPE_LEFT
						: Join::TYPE_INNER
					;
					$this->registerJoin($query, $lowerFieldMap[$lowerKey], $joinType);
				}
			}

			if (is_array($value) && is_numeric($key))
			{
				$this->walkFilterTree($query, $value, $lowerFieldMap, $depth + 1);
			}
		}
	}

	private function needsLeftJoin(string $operator, mixed $value): bool
	{
		if (($operator === '' || $operator === '=') && \CBPHelper::isEmptyValue($value))
		{
			return true;
		}

		return $this->isNegationOperator($operator) && $value !== null;
	}

	private function isNegationOperator(string $operator): bool
	{
		return isset(self::NEGATION_OPERATORS[$operator]);
	}

	private function registerOrderGroupJoins(Query $query, array $fields): void
	{
		if (!$fields)
		{
			return;
		}

		$fieldById = $this->getFieldById();

		$prefixLength = strlen(self::FIELD_PREFIX);

		foreach ($fields as $field)
		{
			$field = (string)$field;

			if (!str_starts_with($field, self::FIELD_PREFIX))
			{
				continue;
			}

			$dotPos = strpos($field, '.', $prefixLength);
			if ($dotPos === false)
			{
				continue;
			}

			$fieldId = (int)substr($field, $prefixLength, $dotPos - $prefixLength);

			if (!isset($fieldById[$fieldId]))
			{
				continue;
			}

			$this->registerJoin($query, $fieldById[$fieldId], Join::TYPE_LEFT);
		}
	}

	private function getFieldById(): array
	{
		if ($this->fieldById === null)
		{
			$this->fieldById = [];
			foreach ($this->lowerFieldMap as $field)
			{
				$this->fieldById[$field->getId()] = $field;
			}
		}

		return $this->fieldById;
	}

	private function registerJoin(Query $query, object $field, string $joinType): void
	{
		$fieldId = $field->getId();
		$fieldAlias = self::FIELD_PREFIX . $fieldId;

		if ($query->getEntity()->hasField($fieldAlias))
		{
			return;
		}

		$query->registerRuntimeField(
			new Reference(
				$fieldAlias,
				StorageRecordFieldTable::class,
				Join::on('this.ID', 'ref.RECORD_ID')
					->where('ref.FIELD_ID', $fieldId),
				['join_type' => $joinType],
			)
		);
	}

	private function buildLowerFieldMap(): array
	{
		$result = [];
		foreach ($this->fieldMap as $code => $field)
		{
			$result[mb_strtolower((string)$code)] = $field;
		}

		return $result;
	}
}
