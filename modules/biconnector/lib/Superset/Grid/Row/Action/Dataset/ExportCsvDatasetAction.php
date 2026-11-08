<?php

namespace Bitrix\BIConnector\Superset\Grid\Row\Action\Dataset;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Access\Model\DashboardAccessItem;
use Bitrix\BIConnector\Superset\MarketDashboardManager;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\BIConnector\Superset\Grid\Row\Action\FormatMenuAction;


final class ExportCsvDatasetAction extends FormatMenuAction
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
		return Loc::getMessage('BICONNECTOR_DASHBOARD_GRID_ACTION_EXPORT_DATASET') ?? '';
	}

	public function getControl(array $rawFields): ?array
	{
		$dashboardId = (int)($rawFields['ID'] ?? 0);
		$dashboardTitle = \CUtil::JSEscape($rawFields['NAME'] ?? '');
		$onClickHandler = <<<JS
			/** @see BX.BIConnector.ExternalDatasetManager.exportDataset */
			BX.BIConnector.ExternalDatasetManager.Instance.exportDataset({id: $dashboardId, title: '{$dashboardTitle}'});
		JS;

		$this->onclick = $onClickHandler;

		$result = parent::getControl($rawFields);

		$result['html'] = $this->getMenuItem('--o-share');

		return $result;
	}
}
