<?php

namespace Bitrix\BIConnector\Superset\Scope\MenuItem;

use Bitrix\BIConnector\Integration\Superset\Model\EO_SupersetDashboard_Collection;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Superset\MarketAccessManager;

final class MenuItemCreatorAutomatedSolution extends BaseMenuItemCreator
{
	private readonly string $automatedSolutionCode;

	public function __construct(string $automatedSolutionCode)
	{
		$this->automatedSolutionCode = $automatedSolutionCode;
	}

	public function getMenuItemData(EO_SupersetDashboard_Collection $dashboards, array $params = []): array
	{
		$items = [];

		foreach ($dashboards as $dashboard)
		{
			$isMarketAvailable = MarketAccessManager::getInstance()->isDashboardAvailableByType($dashboard->getType());

			$onClick =
				!$isMarketAvailable
					? $this->getOpenTariffSliderScript()
					: $this->createDashboardOpenEventFromMenu($dashboard, $params)
			;

			$items[] = [
				'ID' => "DASHBOARD_" . $dashboard->getId(),
				'TEXT' => $dashboard->getTitle(),
				'ON_CLICK' => $onClick,
				'IS_LOCKED' => !$this->isAvailableByTariff() || !$isMarketAvailable,
			];
		}

		if (!empty($items))
		{
			$items[] = [
				"IS_DELIMITER" => true,
			];
		}

		$items = [...$items, ...$this->getAdditionalItems()];

		return [
			'ID' => 'BIC_DASHBOARDS',
			'TEXT' => $this->getMenuItemTitle(),
			'URL' => '',
			'ITEMS' => $items,
		];
	}

	protected function getScopeCode(): string
	{
		return $this->automatedSolutionCode;
	}

	public function createMenuItem(array $urlParams = []): array
	{
		$menuItem = parent::createMenuItem($urlParams);

		if (!empty($menuItem))
		{
			return $menuItem;
		}

		return $this->getMenuItemData(SupersetDashboardTable::createCollection(), $urlParams);
	}

	protected function getOpenFormCode(): string
	{
		return 'automated_solution';
	}
}
