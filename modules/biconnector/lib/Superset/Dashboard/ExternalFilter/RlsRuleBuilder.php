<?php

namespace Bitrix\BIConnector\Superset\Dashboard\ExternalFilter;

use Bitrix\Main\Loader;

/**
 * Builds RLS (Row-Level Security) rules from external filter values.
 *
 * These rules are included in guest tokens to restrict data access
 * for shared dashboards based on the filter values selected at share time.
 */
class RlsRuleBuilder
{
	/**
	 * Column name used for user-based filtering in datasets.
	 */
	private const USER_FILTER_COLUMN = 'RESPONSIBLE_ID';

	/**
	 * Column name for TasksFlow filter.
	 */
	private const TASKS_FLOW_COLUMN = 'flow_name';

	/**
	 * Column name for BPWorkflowTemplate filter.
	 */
	private const BP_WORKFLOW_TEMPLATE_COLUMN = 'workflow_template_name';

	/**
	 * Filter type constants.
	 */
	private const FILTER_TYPE_COMPANY_STRUCTURE = 'filter_company_structure';
	private const FILTER_TYPE_TASKS_FLOW = 'filter_tasks_flow';
	private const FILTER_TYPE_BP_WORKFLOW_TEMPLATE = 'filter_bp_workflow_template';

	public const ALLOWED_FILTER_TYPES = [
		self::FILTER_TYPE_COMPANY_STRUCTURE,
		self::FILTER_TYPE_TASKS_FLOW,
		self::FILTER_TYPE_BP_WORKFLOW_TEMPLATE,
	];

	/**
	 * Extracts default external filter values from dashboard's native filter config.
	 * Used as fallback when sharing from grid (no iframe context).
	 *
	 * For CompanyStructure: falls back to current user if no default configured.
	 * For other types: only includes if default value exists in config.
	 *
	 * @param array $nativeFilterConfig Dashboard's native_filter_configuration from Superset
	 * @param int|null $currentUserId Fallback user ID for CompanyStructure filter
	 * @return array|null Filter values in externalFilterValues format, or null if none
	 */
	public static function extractDefaultsFromConfig(array $nativeFilterConfig, ?int $currentUserId = null): ?array
	{
		$result = [];

		foreach ($nativeFilterConfig as $filterConfig)
		{
			$filterType = $filterConfig['filterType'] ?? '';
			if (!in_array($filterType, self::ALLOWED_FILTER_TYPES, true))
			{
				continue;
			}

			$filterId = $filterConfig['id'] ?? '';
			if (empty($filterId))
			{
				continue;
			}

			$defaultValues = $filterConfig['defaultDataMask']['filterState']['value'] ?? [];
			$extraFormData = $filterConfig['defaultDataMask']['extraFormData'] ?? null;
			$targets = $filterConfig['targets'] ?? null;

			// CompanyStructure: fallback to current user if no defaults
			if (empty($defaultValues) && $filterType === self::FILTER_TYPE_COMPANY_STRUCTURE && $currentUserId > 0)
			{
				$defaultValues = [
					[
						'id' => 'user_' . $currentUserId,
						'entityId' => 'user',
					],
				];
				$targetColumn = $targets[0]['column']['name'] ?? null;
				if ($targetColumn)
				{
					$extraFormData = [
						'filters' => [
							['col' => $targetColumn, 'op' => 'IN', 'val' => [$currentUserId]],
						],
					];
				}
			}

			if (empty($defaultValues))
			{
				continue;
			}

			$result[$filterId] = [
				'value' => $defaultValues,
				'filterType' => $filterType,
				'label' => self::buildLabelFromDefaults($defaultValues),
				'extraFormData' => is_array($extraFormData) ? $extraFormData : null,
				'targets' => is_array($targets) ? $targets : null,
			];
		}

		return empty($result) ? null : $result;
	}

	private static function buildLabelFromDefaults(array $values): string
	{
		$labels = [];
		foreach ($values as $value)
		{
			if (is_array($value) && !empty($value['label']))
			{
				$labels[] = $value['label'];
			}
		}

		return implode(', ', $labels);
	}

	/**
	 * Sanitizes external filter values for database storage.
	 * Removes unknown filter types and keeps only required fields.
	 *
	 * @param array|null $externalFilterValues
	 * @return array|null Sanitized values or null if empty
	 */
	public static function sanitizeForStorage(?array $externalFilterValues): ?array
	{
		if (empty($externalFilterValues))
		{
			return null;
		}

		$result = [];

		foreach ($externalFilterValues as $filterId => $filterData)
		{
			if (!is_array($filterData))
			{
				continue;
			}

			$filterType = $filterData['filterType'] ?? '';
			if (!in_array($filterType, self::ALLOWED_FILTER_TYPES, true))
			{
				continue;
			}

			$values = $filterData['value'] ?? [];
			if (empty($values) || !is_array($values))
			{
				continue;
			}

			$extraFormData = $filterData['extraFormData'] ?? null;
			$targets = $filterData['targets'] ?? null;

			$result[$filterId] = [
				'value' => $values,
				'filterType' => $filterType,
				'label' => (string)($filterData['label'] ?? ''),
				'extraFormData' => is_array($extraFormData) ? $extraFormData : null,
				'targets' => is_array($targets) ? $targets : null,
			];
		}

		return empty($result) ? null : $result;
	}

	/**
	 * Builds RLS rules from external filter values.
	 *
	 * @param array|null $externalFilterValues Format: [filterId => ['value' => [...], 'label' => '...', 'filterType' => '...', 'targets' => [...]], ...]
	 * @return array RLS rules: [['dataset' => datasetId|null, 'clause' => 'SQL WHERE clause'], ...]
	 */
	public static function buildRules(?array $externalFilterValues): array
	{
		if (empty($externalFilterValues))
		{
			return [];
		}

		$rules = [];

		foreach ($externalFilterValues as $filterData)
		{
			$values = $filterData['value'] ?? [];
			$filterType = $filterData['filterType'] ?? '';
			$targets = self::extractTargets($filterData);

			if (empty($values))
			{
				continue;
			}

			// Apply RLS only to the primary target dataset.
			// Secondary targets are protected by locked extraFormData (applied via SQLAlchemy).
			// Limiting RLS scope prevents type mismatches on datasets with different column types
			// and avoids restricting unrelated filters (range, date) that share a dataset.
			$primaryTarget = $targets[0] ?? null;
			if ($primaryTarget === null || $primaryTarget['datasetId'] === null)
			{
				continue;
			}

			$rule = self::buildRuleForFilterType(
				$filterType,
				$values,
				$primaryTarget['datasetId'],
				$primaryTarget['columnName'],
			);
			if ($rule !== null)
			{
				$rules[] = $rule;
			}
		}

		return $rules;
	}

	/**
	 * Extracts dataset ID and column name from filter targets.
	 *
	 * @param array $filterData
	 * @return array{datasetId: int|null, columnName: string|null}
	 */
	/**
	 * Extracts dataset ID and column name from filter data.
	 *
	 * Column name comes from extraFormData (Trino column alias, e.g. "Идентификатор стадии лида").
	 * Dataset ID comes from filter targets.
	 *
	 * @param array $filterData
	 * @return array{datasetId: int|null, columnName: string|null}
	 */
	/**
	 * Extracts all targets from filter data.
	 * Each target has a datasetId and column name (from Trino alias).
	 *
	 * @param array $filterData
	 * @return array<array{datasetId: int|null, columnName: string|null}>
	 */
	private static function extractTargets(array $filterData): array
	{
		$targets = $filterData['targets'] ?? null;
		if (!is_array($targets) || empty($targets))
		{
			return [['datasetId' => null, 'columnName' => null]];
		}

		$extraFormDataCol = $filterData['extraFormData']['filters'][0]['col'] ?? null;

		$result = [];
		foreach ($targets as $i => $target)
		{
			$datasetId = $target['datasetId'] ?? null;
			$datasetId = $datasetId !== null ? (int)$datasetId : null;

			// First target uses extraFormData column, others use target's own column
			if ($i === 0 && $extraFormDataCol !== null)
			{
				$columnName = $extraFormDataCol;
			}
			else
			{
				$col = $target['column'] ?? null;
				$columnName = is_array($col) ? ($col['name'] ?? null) : (is_string($col) ? $col : null);
			}

			$result[] = [
				'datasetId' => $datasetId,
				'columnName' => $columnName,
			];
		}

		return $result;
	}

	/**
	 * Builds RLS rule based on filter type.
	 *
	 * @param string $filterType
	 * @param array $values
	 * @param int|null $datasetId
	 * @return array|null
	 */
	private static function buildRuleForFilterType(string $filterType, array $values, ?int $datasetId = null, ?string $columnName = null): ?array
	{
		switch ($filterType)
		{
			case self::FILTER_TYPE_COMPANY_STRUCTURE:
				return self::buildCompanyStructureRule($values, $datasetId, $columnName);

			case self::FILTER_TYPE_TASKS_FLOW:
				return self::buildTasksFlowRule($values, $datasetId, $columnName);

			case self::FILTER_TYPE_BP_WORKFLOW_TEMPLATE:
				return self::buildBpWorkflowTemplateRule($values, $datasetId, $columnName);

			default:
				$userIds = self::extractDirectUserIds($values);
				if (!empty($userIds))
				{
					return self::buildUserFilterRule($userIds, $datasetId, $columnName);
				}

				return null;
		}
	}

	/**
	 * Builds RLS rule for CompanyStructure filter.
	 * Handles user IDs and department IDs (with/without sub-departments).
	 *
	 * @param array $values
	 * @param int|null $datasetId
	 * @return array|null
	 */
	private static function buildCompanyStructureRule(array $values, ?int $datasetId = null, ?string $columnName = null): ?array
	{
		$userIds = [];

		foreach ($values as $value)
		{
			if (!is_array($value))
			{
				continue;
			}

			$id = $value['id'] ?? '';
			$entityId = $value['entityId'] ?? '';

			if ($entityId === 'user')
			{
				// Direct user: user_123
				$userId = self::extractIdFromPrefix($id, 'user_');
				if ($userId > 0)
				{
					$userIds[] = $userId;
				}
			}
			elseif ($entityId === 'department')
			{
				// Department: all_department_123 or only_users_department_123
				$departmentUserIds = self::getUsersFromDepartmentValue($id);
				$userIds = array_merge($userIds, $departmentUserIds);
			}
		}

		// Also try simple format (string values like 'user_123')
		$simpleUserIds = self::extractDirectUserIds($values);
		$userIds = array_merge($userIds, $simpleUserIds);

		$userIds = array_unique(array_filter($userIds));

		if (empty($userIds))
		{
			return null;
		}

		return self::buildUserFilterRule($userIds, $datasetId, $columnName);
	}

	/**
	 * Gets user IDs from department value.
	 *
	 * @param string $departmentValue e.g., 'all_department_123' or 'only_users_department_123'
	 * @return array User IDs
	 */
	private static function getUsersFromDepartmentValue(string $departmentValue): array
	{
		$withSubDepartments = str_starts_with($departmentValue, 'all_');

		// Extract department ID
		$departmentId = 0;
		if (preg_match('/(\d+)$/', $departmentValue, $matches))
		{
			$departmentId = (int)$matches[1];
		}

		if ($departmentId <= 0)
		{
			return [];
		}

		return self::getUsersByDepartment($departmentId, $withSubDepartments);
	}

	/**
	 * Gets users by department ID.
	 *
	 * @param int $departmentId
	 * @param bool $includeSubDepartments
	 * @return array User IDs
	 */
	private static function getUsersByDepartment(int $departmentId, bool $includeSubDepartments): array
	{
		if (Loader::includeModule('humanresources'))
		{
			return self::getUsersByDepartmentHr($departmentId, $includeSubDepartments);
		}

		if (!Loader::includeModule('intranet'))
		{
			return [];
		}

		return self::getUsersByDepartmentIntranet($departmentId, $includeSubDepartments);
	}

	/**
	 * Gets users by department ID using humanresources module.
	 *
	 * @param int $departmentId
	 * @param bool $includeSubDepartments
	 * @return array User IDs
	 */
	private static function getUsersByDepartmentHr(int $departmentId, bool $includeSubDepartments): array
	{
		$accessCode = \Bitrix\HumanResources\Compatibility\Utils\DepartmentBackwardAccessCode::makeById($departmentId);
		$node = \Bitrix\HumanResources\Service\Container::getNodeRepository()->getByAccessCode($accessCode);

		if (!$node)
		{
			return [];
		}

		$employees = \Bitrix\HumanResources\Service\Container::getNodeMemberService()
			->getAllEmployees($node->id, $includeSubDepartments);

		$userIds = [];
		foreach ($employees as $employee)
		{
			$userIds[] = $employee->entityId;
		}

		return array_unique($userIds);
	}

	/**
	 * Gets users by department ID using intranet module (deprecated).
	 *
	 * @param int $departmentId
	 * @param bool $includeSubDepartments
	 * @return array User IDs
	 */
	private static function getUsersByDepartmentIntranet(int $departmentId, bool $includeSubDepartments): array
	{
		$departmentIds = [$departmentId];

		if ($includeSubDepartments)
		{
			$subDepartments = \CIntranetUtils::getSubDepartments($departmentId);
			if (is_array($subDepartments))
			{
				$departmentIds = array_merge($departmentIds, $subDepartments);
			}
		}

		$userIds = [];
		foreach ($departmentIds as $depId)
		{
			$depUsers = \CIntranetUtils::getDepartmentEmployees($depId, false, false, 'Y');
			if (is_array($depUsers))
			{
				$userIds = array_merge($userIds, array_map('intval', $depUsers));
			}
		}

		return array_unique($userIds);
	}

	/**
	 * Builds RLS rule for TasksFlow filter.
	 *
	 * @param array $values Flow names/IDs
	 * @param int|null $datasetId
	 * @return array|null
	 */
	private static function buildTasksFlowRule(array $values, ?int $datasetId = null, ?string $columnName = null): ?array
	{
		$flowNames = self::extractStringValues($values);

		if (empty($flowNames))
		{
			return null;
		}

		return self::buildStringInRule($columnName ?? self::TASKS_FLOW_COLUMN, $flowNames, $datasetId);
	}

	/**
	 * Builds RLS rule for BPWorkflowTemplate filter.
	 *
	 * @param array $values Template names/IDs
	 * @param int|null $datasetId
	 * @return array|null
	 */
	private static function buildBpWorkflowTemplateRule(array $values, ?int $datasetId = null, ?string $columnName = null): ?array
	{
		$templateNames = self::extractStringValues($values);

		if (empty($templateNames))
		{
			return null;
		}

		return self::buildStringInRule($columnName ?? self::BP_WORKFLOW_TEMPLATE_COLUMN, $templateNames, $datasetId);
	}

	/**
	 * Extracts string values from filter values.
	 *
	 * @param array $values
	 * @return array
	 */
	private static function extractStringValues(array $values): array
	{
		$result = [];

		foreach ($values as $value)
		{
			if (is_string($value) && $value !== '')
			{
				$result[] = $value;
			}
			elseif (is_array($value) && isset($value['id']))
			{
				$result[] = (string)$value['id'];
			}
			elseif (is_numeric($value))
			{
				$result[] = (string)$value;
			}
		}

		return array_unique($result);
	}

	/**
	 * Extracts direct user IDs from simple format values.
	 *
	 * @param array $values Filter values (e.g., ['user_123', 'user_456'])
	 * @return array User IDs as integers
	 */
	private static function extractDirectUserIds(array $values): array
	{
		$userIds = [];

		foreach ($values as $value)
		{
			if (is_string($value) && str_starts_with($value, 'user_'))
			{
				$userId = self::extractIdFromPrefix($value, 'user_');
				if ($userId > 0)
				{
					$userIds[] = $userId;
				}
			}
			elseif (is_int($value) || is_numeric($value))
			{
				$userIds[] = (int)$value;
			}
		}

		return array_unique($userIds);
	}

	/**
	 * Extracts numeric ID from prefixed string.
	 *
	 * @param string $value
	 * @param string $prefix
	 * @return int
	 */
	private static function extractIdFromPrefix(string $value, string $prefix): int
	{
		if (str_starts_with($value, $prefix))
		{
			return (int)substr($value, strlen($prefix));
		}

		return 0;
	}

	/**
	 * Builds RLS rule for user-based filtering.
	 *
	 * @param array $userIds
	 * @param int|null $datasetId
	 * @return array
	 */
	private static function buildUserFilterRule(array $userIds, ?int $datasetId = null, ?string $columnName = null): array
	{
		$column = self::quoteColumnName($columnName ?? self::USER_FILTER_COLUMN);

		$allValues = array_map(static fn(int $id): string => "'" . $id . "'", $userIds);

		// Include user full names so the clause matches both ID and name columns.
		// Trino is strictly typed: name columns contain 'Имя Фамилия', not numeric IDs.
		$userNames = self::resolveUserNames($userIds);
		foreach ($userNames as $name)
		{
			$escaped = str_replace("'", "''", $name);
			$allValues[] = "'" . $escaped . "'";
		}

		$valuesStr = implode(', ', $allValues);

		return [
			'dataset' => $datasetId,
			'clause' => "CAST({$column} AS VARCHAR) IN ({$valuesStr})",
		];
	}

	private static function resolveUserNames(array $userIds): array
	{
		if (empty($userIds))
		{
			return [];
		}

		$names = [];
		$rows = \Bitrix\Main\UserTable::getList([
			'filter' => ['@ID' => $userIds],
			'select' => ['ID', 'NAME', 'LAST_NAME'],
		]);

		while ($user = $rows->fetch())
		{
			$fullName = trim(($user['NAME'] ?? '') . ' ' . ($user['LAST_NAME'] ?? ''));
			if ($fullName !== '')
			{
				$names[] = $fullName;
			}
		}

		return $names;
	}

	/**
	 * Builds RLS rule for string IN clause.
	 *
	 * @param string $column
	 * @param array $values
	 * @param int|null $datasetId
	 * @return array
	 */
	private static function buildStringInRule(string $column, array $values, ?int $datasetId = null): array
	{
		$quotedColumn = self::quoteColumnName($column);
		$escapedValues = array_map(function ($val) {
			return "'" . str_replace("'", "''", $val) . "'";
		}, $values);

		$valuesStr = implode(', ', $escapedValues);

		return [
			'dataset' => $datasetId,
			'clause' => "CAST({$quotedColumn} AS VARCHAR) IN ({$valuesStr})",
		];
	}

	/**
	 * Wraps column name in double quotes for Trino SQL.
	 * Trino uses verbose_name aliases which may contain spaces, Cyrillic, etc.
	 */
	private static function quoteColumnName(string $column): string
	{
		$escaped = str_replace('"', '""', $column);

		return '"' . $escaped . '"';
	}

	/**
	 * Restores numeric types in extraFormData lost during form-data serialization.
	 * BX.ajax.runAction sends nested arrays as form fields, converting all values to strings.
	 * Trino is strictly typed: '123' (varchar) != 123 (integer) in IN expressions.
	 */
	private static function restoreNumericTypes(array $extraFormData): array
	{
		if (!isset($extraFormData['filters']) || !is_array($extraFormData['filters']))
		{
			return $extraFormData;
		}

		foreach ($extraFormData['filters'] as &$filter)
		{
			if (!isset($filter['val']) || !is_array($filter['val']))
			{
				continue;
			}

			foreach ($filter['val'] as &$val)
			{
				if (is_string($val) && is_numeric($val))
				{
					$val = str_contains($val, '.') ? (float)$val : (int)$val;
				}
			}
			unset($val);
		}
		unset($filter);

		return $extraFormData;
	}
}
