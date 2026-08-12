<?php

namespace Bitrix\Superset\Internal\Services;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Connector\SupersetInstance;
use Bitrix\Superset\Internal\Entities\Server;

class CreateFilterDataset
{
	private const ADMIN_OWNER_ID = 1;
	private const SCHEMA = 'bitrix24';

	private Server $server;
	private SupersetInstance $connector;

	public function __construct(Server $server, ?SupersetInstance $connector = null)
	{
		$this->server = $server;
		$this->connector = $connector ?? new SupersetInstance($server);
	}

	public function run(array $tables = []): Result
	{
		$result = new Result();
		$availableTablesMap = self::prepareAvailableTablesMap($tables);

		$databaseIdResult = $this->getDatabaseId();
		if (!$databaseIdResult->isSuccess())
		{
			$result->addErrors($databaseIdResult->getErrors());

			return $result;
		}

		$buildData = [];
		$databaseId = (int)$databaseIdResult->getData()['id'];

		foreach (self::getDatasets() as $dataset)
		{
			if (!self::isDatasetAvailableByTableList($dataset, $availableTablesMap))
			{
				continue;
			}

			$builder = new DatasetBuilder($this->connector);
			$buildResult = $builder
				->setDatabaseId($databaseId)
				->setSchema($dataset['schema'])
				->setTableName($dataset['table_name'])
				->setSql($dataset['sql'])
				->setOwners($dataset['owners'])
				->build()
			;

			if ($buildResult->isSuccess())
			{
				$buildData[$dataset['table_name']] = $buildResult->getData();
			}
			else
			{
				$result->addErrors($buildResult->getErrors());
			}
		}

		$result->setData($buildData);

		return $result;
	}

	private static function getDatasets(): array
	{
		return [
			[
				'schema' => self::SCHEMA,
				'table_name' => 'system_filter_tasks_flow',
				'sql' => 'select id as flow_id, name as flow_name from flow',
				'owners' => [self::ADMIN_OWNER_ID],
				'source_tables' => ['flow'],
			],
			[
				'schema' => self::SCHEMA,
				'table_name' => 'system_filter_bizproc_workflow_template',
				'sql' => 'select id as workflow_template_id, name as workflow_template_name from bizproc_workflow_template',
				'owners' => [self::ADMIN_OWNER_ID],
				'source_tables' => ['bizproc_workflow_template'],
			],
			[
				'schema' => self::SCHEMA,
				'table_name' => 'system_filter_department_users',
				'sql' => <<<SQL
					WITH list_department AS (
					SELECT rel.child_id, rel.parent_id, str.head_id
					FROM org_structure_relation rel
					JOIN org_structure str ON rel.parent_id = str.id
					WHERE str.active = 'Y' AND str.type='DEPARTMENT' AND
					{% if url_param('current_user') is not none %}
					(CONTAINS(str.head_id, CAST({{ url_param('current_user') }} AS VARCHAR))
					OR
					(NOT CONTAINS(str.head_id, CAST({{ url_param('current_user') }} AS VARCHAR))
					AND cast(rel.parent_id as VARCHAR) IN (SELECT department_id FROM user WHERE id = {{ url_param('current_user') }})
					AND rel.depth = 0
					)
					)
					{% else %}
					1=1
					{% endif %}
					)
					SELECT
						u.id,
						u.name,
						dept_value AS department_id,
						dep.parent_id AS parent_id
					FROM user u
					CROSS JOIN UNNEST(SPLIT(u.department_ids, ',')) AS t(dept_value)
					JOIN list_department dep
						ON CAST(TRIM(t.dept_value) AS INT) = dep.child_id
					WHERE
						u.active = 'Y'
						AND u.department_ids IS NOT NULL
						AND u.department_ids != ''
					AND TRIM(t.dept_value) != ''
					SQL,
				'owners' => [self::ADMIN_OWNER_ID],
				'source_tables' => ['org_structure_relation', 'org_structure', 'user'],
			],
			[
				'schema' => self::SCHEMA,
				'table_name' => 'system_filter_company_structure',
				'sql' => <<<SQL
					WITH list_department AS (
						SELECT rel.id, rel.child_id, rel.child_node_name, rel.depth, rel.parent_id, rel.parent_node_name, str.type
						FROM org_structure_relation rel
						LEFT JOIN org_structure str ON rel.parent_id = str.id
						WHERE str.active = 'Y' AND str.type = 'DEPARTMENT' AND
						{% if url_param('current_user') is not none %}
						(CONTAINS(str.head_id, CAST({{ url_param('current_user') }} AS VARCHAR))
						OR
						(NOT CONTAINS(str.head_id, CAST({{ url_param('current_user') }} AS VARCHAR))
						AND cast(rel.parent_id as VARCHAR) IN (SELECT department_id FROM user WHERE id = {{ url_param('current_user') }})
						AND rel.depth = 0)
						)
						{% else %}
						1=1
						{% endif %}
					)
					SELECT
					CONCAT('department_', CAST(id AS VARCHAR)) AS id,
					CASE
						WHEN (SELECT min(parent_id) FROM list_department WHERE depth = 0) = id THEN NULL
						WHEN parent_id IS NOT NULL THEN CONCAT('department_', CAST(parent_id AS VARCHAR))
						ELSE NULL
					END AS parent_id,
					name, 'department' AS type, id AS department_id_raw, NULL AS user_id_raw
					FROM org_structure
					WHERE type = 'DEPARTMENT' AND
					id IN (SELECT DISTINCT child_id FROM list_department)

					UNION ALL

					SELECT
					CONCAT('user_', CAST(id AS VARCHAR)) AS id,
					CONCAT('department_', CAST(trim(dept_value) AS VARCHAR)) AS parent_id,
					name,
					'user' AS type,
					NULL AS department_id_raw,
					id AS user_id_raw
					FROM user
					CROSS JOIN UNNEST(SPLIT(department_ids, ',')) AS t(dept_value)
					WHERE active = 'Y'
					AND name IS NOT NULL AND name != ''
					AND department_id IS NOT NULL AND department_id != ''
					AND trim(dept_value) != ''
					AND CAST(trim(dept_value) AS INT) IN (SELECT DISTINCT child_id FROM list_department)
					SQL,
				'owners' => [self::ADMIN_OWNER_ID],
				'source_tables' => ['org_structure_relation', 'org_structure', 'user'],
			],
		];
	}

	private static function isDatasetAvailableByTableList(array $dataset, array $availableTablesMap): bool
	{
		if (empty($availableTablesMap))
		{
			return true;
		}

		$requiredTables = $dataset['source_tables'] ?? [];
		foreach ($requiredTables as $requiredTable)
		{
			if (!isset($availableTablesMap[$requiredTable]))
			{
				return false;
			}
		}

		return true;
	}

	private static function prepareAvailableTablesMap(array $tables): array
	{
		$normalizedTables = array_values(
			array_filter(
				array_unique(array_map('strval', $tables)),
				static fn(string $tableName): bool => $tableName !== ''
			)
		);

		return array_fill_keys($normalizedTables, true);
	}

	private function getDatabaseId(): Result
	{
		return (new DatabaseService($this->server, $this->connector))->getTrinoDatabaseId();
	}
}
