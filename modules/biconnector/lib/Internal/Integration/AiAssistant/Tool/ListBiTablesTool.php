<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Internal\Feature\FlagGate;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Operation\OperationImpact;
use Bitrix\BIConnector\Internal\Operation\Builder\Actor;
use Bitrix\BIConnector\Internal\Operation\Builder\Argument\BuilderArgumentContract;
use Bitrix\BIConnector\Internal\Operation\Builder\Channel;
use Bitrix\BIConnector\Internal\Operation\Builder\Table\ListBiTablesOperation;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;

final class ListBiTablesTool extends BaseBiTool
{
	private const LIMIT_DEFAULT = 20;
	private const LIMIT_MAX = 50;

	public function getName(): string
	{
		return 'list_bi_tables';
	}

	public function getImpact(): OperationImpact
	{
		return OperationImpact::Read;
	}

	public function getDescription(): string
	{
		return 'Returns a paginated list of physical tables available in the BI database. '
			. 'Use this tool to discover which tables can be used as a source for a new virtual dataset. '
			. 'Each entry contains `name`, `type` and `schema`. '
			. 'After picking a table, call get_bi_table_schema to inspect its columns, '
			. 'and then create_virtual_dataset to project selected columns into a Superset dataset. '
			. 'Paginate with `limit` (default ' . self::LIMIT_DEFAULT . ', max ' . self::LIMIT_MAX
			. ') and `offset` (default 0); the response carries `total`, `limit`, `offset` and '
			. '`has_more`. When `has_more` is true, request the next page with `offset` += `limit`.';
	}

	public function getInputSchema(): array
	{
		return BuilderArgumentContract::schema(
			'biconnector.builder.table.list',
			'mcp',
			[
				'schema' => 'Optional schema name to filter tables. '
					. 'If omitted or empty, the default BI schema is used.',
				'limit' => 'Page size. Default ' . self::LIMIT_DEFAULT
					. ', minimum 1, maximum ' . self::LIMIT_MAX . '.',
				'offset' => 'Offset for pagination. Default 0. '
					. 'When `has_more` is true, send the next request with `offset` = previous `offset` + `limit`.',
			],
		);
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		if (!(new FlagGate())->isEnabled(FlagGate::BUILDER_MCP_OPERATIONS, $userId))
		{
			return $this->legacyExecuteStructured($userId, ...$args);
		}

		self::requireBiconnectorModule();

		$schema = isset($args['schema']) && is_string($args['schema']) && $args['schema'] !== ''
			? $args['schema']
			: null
		;

		$limit = self::LIMIT_DEFAULT;
		if (isset($args['limit']) && is_numeric($args['limit']))
		{
			$limit = (int)$args['limit'];
		}

		$offset = 0;
		if (isset($args['offset']) && is_numeric($args['offset']))
		{
			$offset = (int)$args['offset'];
		}

		// Resolved per call: aiassistant keeps a tool as a process-wide singleton.
		$result = ServiceLocator::getInstance()->get(ListBiTablesOperation::class)->execute(
			$schema,
			$limit,
			$offset,
			new Actor($userId, Channel::Mcp),
		);
		if (!$result->isSuccess())
		{
			throw self::toMcpException(OperationRefusal::forTool($result));
		}

		return $result->getData();
	}

	private function legacyExecuteStructured(int $userId, ...$args): array
	{
		Loader::includeModule('biconnector');

		if (!$this->getAccessController($userId)->check(ActionDictionary::ACTION_BIC_DASHBOARD_EDIT))
		{
			throw new McpException('Access denied: report editing permission (ACTION_BIC_DASHBOARD_EDIT) is required to browse BI tables.');
		}

		$schema = isset($args['schema']) && is_string($args['schema']) && $args['schema'] !== ''
			? $args['schema']
			: null
		;

		$limit = self::LIMIT_DEFAULT;
		if (isset($args['limit']) && is_numeric($args['limit']))
		{
			$limit = max(1, min(self::LIMIT_MAX, (int)$args['limit']));
		}

		$offset = 0;
		if (isset($args['offset']) && is_numeric($args['offset']))
		{
			$offset = max(0, (int)$args['offset']);
		}

		$response = IntegratorFactory::getInstance()->listTables(self::findActAsUser($userId), $schema);
		if ($response->hasErrors())
		{
			throw self::unavailableBiException(
				$response->getErrors(),
				['stage' => 'list_bi_tables', 'user_id' => $userId, 'schema' => $schema],
			);
		}

		$data = $response->getData();
		if (!is_array($data))
		{
			$data = [];
		}

		$rawTables = is_array($data['tables'] ?? null) ? $data['tables'] : [];
		$total = count($rawTables);

		$page = $offset > 0 ? array_slice($rawTables, $offset, $limit) : array_slice($rawTables, 0, $limit);

		$tables = [];
		foreach ($page as $table)
		{
			if (!is_array($table))
			{
				continue;
			}

			$entry = [
				'name' => (string)($table['name'] ?? ''),
			];
			if (isset($table['type']) && $table['type'] !== '' && $table['type'] !== null)
			{
				$entry['type'] = (string)$table['type'];
			}
			if (isset($table['schema']) && $table['schema'] !== '' && $table['schema'] !== null)
			{
				$entry['schema'] = (string)$table['schema'];
			}

			$tables[] = $entry;
		}

		return [
			'tables' => $tables,
			'database_id' => (int)($data['databaseId'] ?? 0),
			'total' => $total,
			'limit' => $limit,
			'offset' => $offset,
			'has_more' => ($offset + count($page)) < $total,
		];
	}
}
