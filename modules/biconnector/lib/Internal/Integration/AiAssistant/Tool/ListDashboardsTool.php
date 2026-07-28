<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool;

use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\UrlParameter\UrlParameters;
use Bitrix\BIConnector\Internal\Model\SupersetDashboardInfoTable;
use Bitrix\Main\Entity\ReferenceField;
use Bitrix\Main\Loader;

final class ListDashboardsTool extends BaseBiTool
{
	private const PAGE_SIZE = 50;

	public function getName(): string
	{
		return 'list_dashboards';
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
			. 'Returns up to ' . self::PAGE_SIZE . ' dashboards per page along with a `has_more` flag; '
			. 'when `has_more` is true, repeat the call with `offset` advanced by ' . self::PAGE_SIZE
			. ' to fetch the next page.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'query' => [
					'type' => 'string',
					'description' => 'Optional search query to filter dashboards by title or description. '
						. 'If omitted, returns all available dashboards.',
				],
				'offset' => [
					'type' => 'integer',
					'description' => 'Number of dashboards to skip before returning, for paging. '
						. 'Default 0. Advance by ' . self::PAGE_SIZE . ' on each call while `has_more` '
						. 'is true.',
					'minimum' => 0,
				],
			],
			'additionalProperties' => false,
		];
	}

	protected function executeStructured(int $userId, ...$args): array
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
				return ['dashboards' => [], 'has_more' => false];
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

		$rows = SupersetDashboardTable::getList([
			'select' => ['ID', 'TITLE', 'TYPE', 'FILTER_PERIOD', 'DESCRIPTION' => 'INFO.DESCRIPTION'],
			'filter' => $filter,
			'order' => ['TITLE' => 'ASC', 'ID' => 'ASC'],
			'limit' => self::PAGE_SIZE + 1,
			'offset' => $offset,
			'runtime' => [
				new ReferenceField(
					'INFO',
					SupersetDashboardInfoTable::class,
					['=this.ID' => 'ref.DASHBOARD_ID'],
					['join_type' => 'LEFT'],
				),
			],
		])->fetchAll();

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
				'defaultFilterPeriod' => $row['FILTER_PERIOD'] ?? null,
				'requiresScope' => $requiresScope[$id] ?? false,
			];
		}

		return ['dashboards' => $dashboards, 'has_more' => $hasMore];
	}
}
