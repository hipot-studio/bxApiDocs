<?php

namespace Bitrix\BIConnector\Superset\Scope\MenuItem;

use Bitrix\BIConnector\Integration\Superset\Model\EO_SupersetDashboard_Collection;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Superset\Scope\ScopeService;

final class MenuItemCreatorProfile extends BaseMenuItemCreator
{
	protected function getScopeCode(): string
	{
		return ScopeService::BIC_SCOPE_PROFILE;
	}

	public function getMenuItemData(EO_SupersetDashboard_Collection $dashboards, array $params = []): array
	{
		$menuItems = [];
		foreach ($dashboards as $dashboard)
		{
			$dashboardId = $dashboard->getId();

			$onClick = $this->createDashboardOpenEventFromMenu($dashboard, $params);
			if ($dashboard->getStatus() === SupersetDashboardTable::DASHBOARD_STATUS_NOT_INSTALLED)
			{
				$this->loadDashboardManagerExtension();
				$fallBackUrl = \CUtil::JSEscape($this->getDetailUrl($dashboard, $params, ['openFrom' => 'menu']));
				$onClick = "
					const instance = new BX.BIConnector.DashboardManager();
					instance.createEventOpenNotInstalledDashboard({$dashboardId}, '{$fallBackUrl}');
				";
			}

			$menuItems[] = [
				'ID' => "BIC_DASHBOARD_{$dashboardId}",
				'TEXT' => $dashboard->getTitle(),
				'ON_CLICK' => $onClick,
				'IS_ACTIVE' => false,
			];
		}

		if (!empty($menuItems))
		{
			$menuItems[] = [
				"IS_DELIMITER" => true,
			];

			$menuItems = [...$menuItems, ...$this->getAdditionalItems()];
		}

		return [
			'ID' => 'BIC_DASHBOARDS',
			'TEXT' => $this->getMenuItemTitle(),
			'IS_ACTIVE' => false,
			'ITEMS' => $menuItems,
		];
	}

	private function loadDashboardManagerExtension(): void
	{
		static $loaded = false;
		if (!$loaded)
		{
			\Bitrix\Main\UI\Extension::load('biconnector.apache-superset-dashboard-manager');
			$loaded = true;
		}
	}
}
