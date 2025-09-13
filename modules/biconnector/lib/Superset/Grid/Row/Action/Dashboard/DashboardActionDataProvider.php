<?php

namespace Bitrix\BIConnector\Superset\Grid\Row\Action\Dashboard;

use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Integration\Superset\Repository\DashboardGroupRepository;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\BIConnector\Superset\Grid\Settings\DashboardSettings;
use Bitrix\Main\Grid\Row\Action\DataProvider;

/**
 * @method DashboardSettings getSettings()
 */
class DashboardActionDataProvider extends DataProvider
{
	public function __construct(?DashboardSettings $settings = null)
	{
		parent::__construct($settings);
	}

	public function prepareActions(): array
	{
		return [];
	}

	public function prepareDashboardActions(): array
	{
		return [
			new OpenAction(),
			new EditAction(),
			new CopyAction(),
			new DeleteAction(),
			new PublishAction(),
			new SetDraftAction(),
			new OpenSettingsAction(),
			new ExportAction(),
			new AddToTopMenuAction(),
			new DeleteFromTopMenuAction(),
		];
	}

	public function prepareGroupActions(): array
	{
		return [
			new ShowGroupDetailPopupAction(),
			new DeleteGroupAction(),
		];
	}

	public function prepareControls(array $rawFields): array
	{
		$result = [];

		$actions = [];
		if ($rawFields['ENTITY_TYPE'] === DashboardGroupRepository::TYPE_GROUP)
		{
			$actions = $this->prepareGroupActions();
		}
		elseif ($rawFields['ENTITY_TYPE'] === DashboardGroupRepository::TYPE_DASHBOARD)
		{
			$settings = $this->getSettings();
			if (
				($settings !== null && !$settings->isSupersetAvailable())
				|| SupersetInitializer::isSupersetLoading()
				|| $rawFields['STATUS'] === SupersetDashboardTable::DASHBOARD_STATUS_LOAD
				|| !$rawFields['IS_ACCESS_ALLOWED']
			)
			{
				return [];
			}

			if ($rawFields['EDIT_URL'] === '')
			{
				$config = (new DeleteAction())->getControl($rawFields);

				return isset($config) ? [$config] : [];
			}

			$actions = $this->prepareDashboardActions();
		}

		foreach ($actions as $actionsItem)
		{
			$actionConfig = $actionsItem->getControl($rawFields);
			if (isset($actionConfig))
			{
				$result[] = $actionConfig;
			}
		}

		return $result;
	}
}
