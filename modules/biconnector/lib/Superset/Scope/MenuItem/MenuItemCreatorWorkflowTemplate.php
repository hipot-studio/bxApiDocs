<?php

namespace Bitrix\BIConnector\Superset\Scope\MenuItem;

use Bitrix\BIConnector\Integration\Superset\Model\EO_SupersetDashboard_Collection;
use Bitrix\BIConnector\Superset\MarketAccessManager;
use Bitrix\BIConnector\Superset\Scope\ScopeService;

final class MenuItemCreatorWorkflowTemplate extends BaseMenuItemCreator
{
	protected function getScopeCode(): string
	{
		return ScopeService::BIC_SCOPE_WORKFLOW_TEMPLATE;
	}

	public function getMenuItemData(EO_SupersetDashboard_Collection $dashboards, array $params = []): array
	{
		$menuItems = [];
		foreach ($dashboards as $dashboard)
		{
			$isMarketAvailable = MarketAccessManager::getInstance()->isDashboardAvailableByType($dashboard->getType());

			$onClick = $this->createDashboardOpenEventFromMenu($dashboard, $params, $isMarketAvailable);

			$menuItems[] = [
				'ID' => "BIC_WORKFLOW_DASHBOARD_{$dashboard->getId()}",
				'TEXT' => $dashboard->getTitle(),
				'IS_LOCKED' => $this->isDashboardLocked(),
				'ON_CLICK' => $onClick,
				'URL' => $this->getDetailUrl(
					$dashboard,
					$params,
					['openFrom' => $this->getOpenFrom()]
				),
				'IS_AVAILABLE_WITHOUT_MARKET_SUB' => $isMarketAvailable,
			];
		}

		return $menuItems;
	}

	protected function getOpenFormCode(): string
	{
		return 'workflow_template';
	}
}
