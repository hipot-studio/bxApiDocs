<?php

namespace Bitrix\BIConnector\Superset\Grid\Row\Action\Dashboard;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Access\Model\DashboardAccessItem;
use Bitrix\BIConnector\Superset\MarketDashboardManager;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

final class ExportAction extends BaseDashboardAction
{

	public static function getId(): ?string
	{
		return 'export';
	}

	public function processRequest(HttpRequest $request): ?Result
	{
		return null;
	}

	protected function getText(): string
	{
		return Loc::getMessage('BICONNECTOR_DASHBOARD_GRID_ACTION_EXPORT') ?? '';
	}

	public function getControl(array $rawFields): ?array
	{
		if (!MarketDashboardManager::getInstance()->isExportEnabled())
		{
			return null;
		}

		$accessItem = DashboardAccessItem::createFromArray([
			'ID' => (int)$rawFields['ID'],
			'TYPE' => $rawFields['TYPE'],
			'STATUS' => $rawFields['STATUS'],
		]);
		if (!AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_DASHBOARD_EXPORT, $accessItem))
		{
			return null;
		}

		$dashboardId = (int)$rawFields['ID'];
		$onClickHandler = <<<JS
			/** @see BX.BIConnector.SupersetDashboardGridManager.exportDashboard */
			BX.BIConnector.SupersetDashboardGridManager.Instance.exportDashboard({$dashboardId});
		JS;

		$this->onclick = $onClickHandler;

		$result = parent::getControl($rawFields);

		$result['html'] = $this->getMenuItem('--o-share');

		return $result;
	}
}
