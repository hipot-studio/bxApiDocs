<?php

namespace Bitrix\BIConnector\Superset\Grid\Row\Action\Dashboard;

use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Integration\Superset\Repository\DashboardGroupRepository;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\BIConnector\Superset\Grid\Settings\DashboardSettings;
use Bitrix\Main\Grid\Row\Action\BaseAction;
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

	/**
	 * @return BaseAction[]
	 */
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

	/**
	 * @return BaseAction[]
	 */
	public function prepareGroupActions(): array
	{
		return [
			new ShowGroupDetailPopupAction(),
			new DeleteGroupAction(),
		];
	}

	/**
	 * @param array $rawFields
	 *
	 * @return BaseAction[]
	 */
	public function prepareDashboardGroupActions(array $rawFields): array
	{
		$actions = $this->prepareDashboardActions();

		$settings = $this->getSettings();

		if ($rawFields['STATUS'] === SupersetDashboardTable::DASHBOARD_STATUS_NOT_INSTALLED)
		{
			return [
				new OpenAction(),
				new OpenSettingsAction(),
				new AddToTopMenuAction(),
				new DeleteFromTopMenuAction(),
				new DeleteAction(),
			];
		}

		if (SupersetInitializer::isSupersetLoading())
		{
			return [
				new OpenAction(),
				new OpenSettingsAction(),
				new AddToTopMenuAction(),
				new DeleteFromTopMenuAction(),
			];
		}

		if (
			($settings !== null && !$settings->isSupersetAvailable())
			|| $rawFields['STATUS'] === SupersetDashboardTable::DASHBOARD_STATUS_LOAD
			|| !$rawFields['IS_ACCESS_ALLOWED']
		)
		{
			return [];
		}

		if ($rawFields['EXTERNAL_ID'] && $rawFields['EDIT_URL'] === '')
		{
			return [
				new DeleteAction(),
			];
		}

		return $actions;
	}

	public function prepareControls(array $rawFields): array
	{
		$actions = [];
		if (!Feature::isBuilderEnabled())
		{
			return $actions;
		}

		if ($rawFields['ENTITY_TYPE'] === DashboardGroupRepository::TYPE_GROUP)
		{
			$actions = $this->prepareGroupActions();
		}
		elseif ($rawFields['ENTITY_TYPE'] === DashboardGroupRepository::TYPE_DASHBOARD)
		{
			$actions = $this->prepareDashboardGroupActions($rawFields);
		}

		$result = [];
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
