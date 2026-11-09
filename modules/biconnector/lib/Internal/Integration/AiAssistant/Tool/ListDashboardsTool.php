<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool;

use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Internal\Feature\FlagGate;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Operation\OperationImpact;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\UrlParameter\UrlParameters;
use Bitrix\BIConnector\Internal\Model\SupersetDashboardInfoTable;
use Bitrix\BIConnector\Internal\Operation\Builder\Argument\BuilderArgumentContract;
use Bitrix\BIConnector\Internal\Operation\Builder\Actor;
use Bitrix\BIConnector\Internal\Operation\Builder\Channel;
use Bitrix\BIConnector\Internal\Operation\Builder\Dashboard\ListDashboardsOperation;
use Bitrix\BIConnector\Internal\Operation\Builder\Dashboard\ListDashboardsQuery;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Entity\ReferenceField;
use Bitrix\Main\Loader;

final class ListDashboardsTool extends BaseBiTool
{
	private const PAGE_SIZE = 50;

	public function getName(): string
	{
		return 'list_dashboards';
	}

	public function getImpact(): OperationImpact
	{
		return OperationImpact::Read;
	}

	public function getDescription(): string
	{
		return 'Returns a list of BI dashboards available to the current user. '
			. 'Each dashboard includes id, title, type, an optional description, and a `requiresScope` flag. '
			. 'Use this tool to find the right dashboard before calling get_dashboard_meta. '
			. 'When a dashboard has `requiresScope: true`, it is scoped to a specific entity '
			. '(a task flow, a workflow template, ...) that must be chosen first — call '
			. 'search_dashboard_parameters for it before get_dashboard_meta. When false, call '
			. 'get_dashboard_meta directly. '
			. 'Returns up to ' . self::PAGE_SIZE . ' dashboards per page along with a `has_more` flag '
			. 'and `total` (overall number of dashboards available to the user); '
			. 'when `has_more` is true, repeat the call with `offset` advanced by ' . self::PAGE_SIZE
			. ' to fetch the next page.';
	}

	public function getInputSchema(): array
	{
		return BuilderArgumentContract::schema(
			BuilderArgumentContract::methodForTool($this->getName()),
			'mcp',
			[
				'query' => 'Optional search query to filter dashboards by title or description. '
					. 'If omitted, returns all available dashboards.',
				'offset' => 'Number of dashboards to skip before returning, for paging. Default 0. Advance by '
					. self::PAGE_SIZE . ' on each call while `has_more` is true.',
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

		$offset = 0;
		if (isset($args['offset']) && is_numeric($args['offset']))
		{
			$offset = (int)$args['offset'];
		}

		// Resolved per call: aiassistant keeps a tool as a process-wide singleton.
		$result = ServiceLocator::getInstance()->get(ListDashboardsOperation::class)->execute(
			new ListDashboardsQuery(trim($args['query'] ?? ''), $offset),
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

		$accessController = $this->getAccessController($userId);
		$allowedIds = $accessController->getAllowedDashboardValue(
			ActionDictionary::ACTION_BIC_DASHBOARD_VIEW,
		);

		$filter = [];

		if ($allowedIds !== null)
		{
			if (empty($allowedIds))
			{
				return ['dashboards' => [], 'has_more' => false, 'total' => 0];
			}
			$filter['=ID'] = $allowedIds;
		}

		$query = trim($args['query'] ?? '');
		if ($query !== '')
		{
			$filter[] = [
				'LOGIC' => 'OR',
				'%TITLE' => $query,
				'%INFO.DESCRIPTION' => $query,
			];
		}

		$offset = 0;
		if (isset($args['offset']) && is_numeric($args['offset']))
		{
			$offset = max(0, (int)$args['offset']);
		}

		$query = SupersetDashboardTable::getList([
			'select' => ['ID', 'TITLE', 'TYPE', 'DESCRIPTION' => 'INFO.DESCRIPTION'],
			'filter' => $filter,
			'order' => ['TITLE' => 'ASC', 'ID' => 'ASC'],
			'limit' => self::PAGE_SIZE + 1,
			'offset' => $offset,
			'count_total' => true,
			'runtime' => [
				new ReferenceField(
					'INFO',
					SupersetDashboardInfoTable::class,
					['=this.ID' => 'ref.DASHBOARD_ID'],
					['join_type' => 'LEFT'],
				),
			],
		]);
		$rows = $query->fetchAll();
		$total = (int)$query->getCount();

		$hasMore = count($rows) > self::PAGE_SIZE;
		if ($hasMore)
		{
			array_pop($rows);
		}

		$pageIds = array_map(static fn(array $row): int => (int)$row['ID'], $rows);
		$requiresScope = UrlParameters::requiresScopeBatch($pageIds);

		$dashboards = [];
		foreach ($rows as $row)
		{
			$id = (int)$row['ID'];
			$description = (string)($row['DESCRIPTION'] ?? '');
			$dashboards[] = [
				'id' => $id,
				'title' => $row['TITLE'],
				'type' => $row['TYPE'] ?? null,
				'description' => $description !== '' ? $description : null,
				'requiresScope' => $requiresScope[$id] ?? false,
			];
		}

		return ['dashboards' => $dashboards, 'has_more' => $hasMore, 'total' => $total];
	}
}
