<?php

namespace Bitrix\BIConnector\Superset\Grid\Row\Action\Dashboard;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Access\Model\DashboardAccessItem;
use Bitrix\Main;
use Bitrix\Main\Localization\Loc;

final class OpenSettingsAction extends BaseDashboardAction
{

	public static function getId(): ?string
	{
		return 'settings';
	}

	public function processRequest(Main\HttpRequest $request): ?Main\Result
	{
		return null;
	}

	protected function getText(): string
	{
		return Loc::getMessage('BICONNECTOR_DASHBOARD_GRID_ACTION_OPEN_SETTINGS') ?? '';
	}

	public function getControl(array $rawFields): ?array
	{
		$accessItem = DashboardAccessItem::createFromArray([
			'ID' => (int)$rawFields['ID'],
			'TYPE' => $rawFields['TYPE'],
			'STATUS' => $rawFields['STATUS'],
		]);

		if (!AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_DASHBOARD_MODIFY_SETTINGS, $accessItem))
		{
			return null;
		}

		$dashboardId = (int)$rawFields['ID'];
		$dashboardType = (string)($rawFields['TYPE']);
		$type = mb_strtolower($dashboardType);
		$this->onclick = "
			BX.BIConnector.ApacheSupersetAnalytics.sendAnalytics(
				'edit',
				'editing_card_report',
				{type: '{$type}', c_element: 'context_menu', status: 'success'}
			);
			BX.BIConnector.DashboardManager.openSettingsSlider({$dashboardId}, '{$dashboardType}')
		";

		$result = parent::getControl($rawFields);

		$result['html'] = $this->getMenuItem('--o-settings');

		return $result;
	}
}