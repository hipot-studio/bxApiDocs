<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool;

use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\Main\Loader;

final class ListDashboardsTool extends BaseBiTool
{
	public function getName(): string
	{
		return 'list_dashboards';
	}

	public function getDescription(): string
	{
		return 'Returns a list of BI dashboards available to the current user. '
			. 'Each dashboard includes id, title, and type. '
			. 'Use this tool to find the right dashboard before calling get_dashboard_meta.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'query' => [
					'type' => 'string',
					'description' => 'Optional search query to filter dashboards by title. '
						. 'If omitted, returns all available dashboards.',
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

		$filter = [
			'@STATUS' => [
				SupersetDashboardTable::DASHBOARD_STATUS_READY,
				SupersetDashboardTable::DASHBOARD_STATUS_DRAFT,
			],
		];

		if ($allowedIds !== null)
		{
			if (empty($allowedIds))
			{
				return ['dashboards' => []];
			}
			$filter['=ID'] = $allowedIds;
		}

		// TODO: also search by description once the description field is wired into SupersetDashboardTable
		$query = trim($args['query'] ?? '');
		if ($query !== '')
		{
			$filter['%TITLE'] = $query;
		}

		$rows = SupersetDashboardTable::getList([
			'select' => ['ID', 'TITLE', 'TYPE', 'FILTER_PERIOD'],
			'filter' => $filter,
			'order' => ['TITLE' => 'ASC'],
			'limit' => 50,
		])->fetchAll();

		$dashboards = [];
		foreach ($rows as $row)
		{
			$dashboards[] = [
				'id' => (int)$row['ID'],
				'title' => $row['TITLE'],
				'type' => $row['TYPE'] ?? null,
				'defaultFilterPeriod' => $row['FILTER_PERIOD'] ?? null,
			];
		}

		return ['dashboards' => $dashboards];
	}
}
