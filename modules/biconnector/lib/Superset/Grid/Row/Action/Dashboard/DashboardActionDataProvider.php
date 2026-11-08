<?php

namespace Bitrix\BIConnector\Superset\Grid\Row\Action\Dashboard;

use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Integration\Superset\Repository\DashboardGroupRepository;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\BIConnector\Internal\Digest\Service\DigestSetupAvailabilityService;
use Bitrix\BIConnector\Superset\Grid\Settings\DashboardSettings;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Grid\Row\Action\BaseAction;
use Bitrix\Main\Grid\Row\Action\DataProvider;

/**
 * @method DashboardSettings getSettings()
 */
class DashboardActionDataProvider extends DataProvider
{
	private ?DigestSetupAvailabilityService $digestSetupAvailabilityService = null;

	public function __construct(?DashboardSettings $settings = null)
	{
		parent::__construct($settings);
	}

	/**
	 * Every row of the grid asks the same service, so the portal half of its answer is resolved once per
	 * request instead of once per row.
	 */
	protected function createDigestSetupAvailabilityService(): DigestSetupAvailabilityService
	{
		return ServiceLocator::getInstance()->get(DigestSetupAvailabilityService::class);
	}

	private function createConfigureDigestAction(): ConfigureDigestAction
	{
		$this->digestSetupAvailabilityService ??= $this->createDigestSetupAvailabilityService();

		return new ConfigureDigestAction($this->digestSetupAvailabilityService);
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
			new OpenSettingsAction(),
			$this->createConfigureDigestAction(),
			new CopyAction(),
			new ExportAction(),
			new SetDraftAction(),
			new PublishAction(),
			new AddToTopMenuAction(),
			new DeleteFromTopMenuAction(),
			new DeleteAction(),
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
		return [
			new DemoAction(),
			...$this->prepareAllowedDashboardActions($rawFields),
		];
	}

	public function prepareControls(array $rawFields): array
	{
		if (!Feature::isBuilderEnabled())
		{
			return $this->buildControls($this->prepareBuilderDisabledActions(), $rawFields);
		}

		$actions = [];
		if ($rawFields['ENTITY_TYPE'] === DashboardGroupRepository::TYPE_GROUP)
		{
			$actions = $this->prepareGroupActions();
		}
		elseif ($rawFields['ENTITY_TYPE'] === DashboardGroupRepository::TYPE_DASHBOARD)
		{
			$actions = $this->prepareDashboardGroupActions($rawFields);
		}

		return $this->buildControls($actions, $rawFields);
	}

	/**
	 * @return BaseAction[]
	 */
	private function prepareAllowedDashboardActions(array $rawFields): array
	{
		if ($rawFields['STATUS'] === SupersetDashboardTable::DASHBOARD_STATUS_NOT_INSTALLED)
		{
			return [
				new OpenSettingsAction(),
				new AddToTopMenuAction(),
				new DeleteFromTopMenuAction(),
				new DeleteAction(),
			];
		}

		if (SupersetInitializer::isSupersetLoading())
		{
			return [
				new OpenSettingsAction(),
				new AddToTopMenuAction(),
				new DeleteFromTopMenuAction(),
			];
		}

		if (
			!$this->getSettings()->isSupersetAvailable()
			|| $rawFields['STATUS'] === SupersetDashboardTable::DASHBOARD_STATUS_LOAD
			|| !$rawFields['IS_ACCESS_ALLOWED']
		)
		{
			return [];
		}

		// A synced report without an edit link is broken: it can only be deleted, a digest of it would never run.
		if ($rawFields['EXTERNAL_ID'] && $rawFields['EDIT_URL'] === '')
		{
			return [
				new DeleteAction(),
			];
		}

		return $this->prepareDashboardActions();
	}

	/**
	 * @return BaseAction[]
	 */
	private function prepareBuilderDisabledActions(): array
	{
		return [
			new DemoAction(),
		];
	}

	/**
	 * @param BaseAction[] $actions
	 */
	private function buildControls(array $actions, array $rawFields): array
	{
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
