<?php

namespace Bitrix\BIConnector\Superset\Scope\MenuItem;

use Bitrix\BIConnector\Integration\Superset\Model\EO_SupersetDashboard_Collection;
use Bitrix\BIConnector\Superset\MarketAccessManager;
use Bitrix\BIConnector\Superset\Scope\ScopeService;

final class MenuItemCreatorBizproc extends BaseMenuItemCreator
{
	protected function getScopeCode(): string
	{
		return ScopeService::BIC_SCOPE_BIZPROC;
	}

	public function getMenuItemData(EO_SupersetDashboard_Collection $dashboards, array $params = []): array
	{
		$menuItems = [];
		foreach ($dashboards as $dashboard)
		{
			$isMarketAvailable = MarketAccessManager::getInstance()->isDashboardAvailableByType($dashboard->getType());

			$onClick =
				!$isMarketAvailable
					? $this->getOpenTariffSliderScript()
					: $this->createDashboardOpenEventFromMenu($dashboard, $params)
			;

			$menuItems[] = [
				'ID' => "BIC_DASHBOARD_{$dashboard->getId()}",
				'TEXT' => $dashboard->getTitle(),
				'ON_CLICK' => $onClick,
				'IS_LOCKED' => !$this->isAvailableByTariff() || !$isMarketAvailable,
			];
		}

		if (!empty($menuItems))
		{
			$menuItems[] = [
				'IS_DELIMITER' => true,
			];

			$menuItems = [...$menuItems, ...$this->getAdditionalItems()];
		}

		return [
			'id' => 'BIC_DASHBOARDS',
			'title' => $this->getMenuItemTitle(),
			'url' => '',
			'available' => true,
			'menuData' => [
				'sub_menu' => $menuItems,
			],
		];
	}

	protected function getOpenFormCode(): string
	{
		return 'bizproc';
	}
}
