<?php

declare(strict_types=1);

namespace Bitrix\BIConnectorMobile\Filter;

use Bitrix\BIConnector\Superset\Filter\Provider\DashboardDataProvider;
use Bitrix\BIConnector\Superset\Grid\DashboardGrid;
use Bitrix\BIConnectorMobile\AccessManager;
use Bitrix\Main\Filter\Filter;
use Bitrix\Main\Filter\Settings;
use Bitrix\Main\UI\Filter\Options;
use Bitrix\Mobile\Filter\ListContext;

/**
 * The root report list. BI ships no default presets, so the storage is addressed by the grid id alone.
 */
final class BiDashboardListContext implements ListContext
{
	public function canRead(): bool
	{
		return AccessManager::canViewDashboards();
	}

	public function getOptions(): Options
	{
		// The second argument must stay in sync with what the web list passes: BI passes none
		// (FILTER_PRESETS defaults to []), so an empty array here is the matching value, not a stub.
		return new Options(DashboardGrid::SUPERSET_DASHBOARD_GRID_ID, []);
	}

	public function getFieldArrays(): array
	{
		return $this->createFilter()->getFieldArrays();
	}

	public function getSections(): array
	{
		return [];
	}

	private function createFilter(): Filter
	{
		$gridId = DashboardGrid::SUPERSET_DASHBOARD_GRID_ID;

		return new Filter($gridId, new DashboardDataProvider(new Settings(['ID' => $gridId])));
	}
}
