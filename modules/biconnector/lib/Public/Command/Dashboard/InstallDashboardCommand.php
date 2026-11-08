<?php

namespace Bitrix\BIConnector\Public\Command\Dashboard;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Integration\Superset\Model;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Integration\Superset\SupersetController;
use Bitrix\BIConnector\Superset\Error\IntegrationErrorPresenter;
use Bitrix\BIConnector\Superset\Error\UserFacingError;
use Bitrix\BIConnector\Superset\MarketDashboardManager;
use Bitrix\BIConnector\Superset\UI\DashboardManager;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

/**
 * Installs a dashboard that is still in the NOT_INSTALLED status: a custom one is created empty in
 * Superset, a system or market one is installed from the Marketplace. A dashboard that has already
 * left that status is a success with nothing to do. Runs on behalf of the current user and checks
 * the view right on the dashboard.
 */
class InstallDashboardCommand extends AbstractCommand
{
	public function __construct(
		public readonly int $dashboardId,
	)
	{
	}

	protected function execute(): Result
	{
		$result = new Result();

		try
		{
			$dashboard = (new SupersetController())->getDashboardRepository()->getById($this->dashboardId);
			if (!$dashboard)
			{
				return $result->addError(
					new UserFacingError(Loc::getMessage('BICONNECTOR_INSTALL_DASHBOARD_ERROR_NOT_FOUND')),
				);
			}

			if ($dashboard->getStatus() !== SupersetDashboardTable::DASHBOARD_STATUS_NOT_INSTALLED)
			{
				return $result;
			}

			if (!AccessController::getCurrent()->checkByEntity(ActionDictionary::ACTION_BIC_DASHBOARD_VIEW, $dashboard))
			{
				return $result->addError(
					new UserFacingError(Loc::getMessage('BICONNECTOR_INSTALL_DASHBOARD_ACCESS_ERROR_VIEW')),
				);
			}

			return $dashboard->isCustomDashboard()
				? $this->createEmptyDashboard($dashboard, $result)
				: $this->installMarketApplication($dashboard, $result)
			;
		}
		catch (\Throwable $e)
		{
			return $result->addError(new Error($e->getMessage(), $e->getCode()));
		}
	}

	private function createEmptyDashboard(Model\Dashboard $dashboard, Result $result): Result
	{
		$response = IntegratorFactory::getInstance()->createEmptyDashboard([
			'name' => $dashboard->getTitle(),
		]);

		if ($response->getErrors())
		{
			return $result->addError(IntegrationErrorPresenter::present(
				$response->getErrors(),
				Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_INSTALL_ERROR'),
			));
		}

		$responseData = $response->getData();
		$externalId = (int)($responseData['body']['id'] ?? 0);

		$dashboard
			->getOrmObject()
			->setExternalId($externalId)
			->setStatus(SupersetDashboardTable::DASHBOARD_STATUS_DRAFT)
			->save()
		;

		DashboardManager::notifyDashboardStatus($dashboard->getId(), SupersetDashboardTable::DASHBOARD_STATUS_DRAFT);

		return $result;
	}

	private function installMarketApplication(Model\Dashboard $dashboard, Result $result): Result
	{
		$appId = $dashboard->getAppId();
		if (!$appId)
		{
			return $result->addError(
				new UserFacingError(Loc::getMessage('BICONNECTOR_CONTROLLER_DASHBOARD_INSTALL_ERROR_NO_APP')),
			);
		}

		$installResult = MarketDashboardManager::getInstance()->installApplication($appId);
		if (!$installResult->isSuccess())
		{
			$result->addErrors($installResult->getErrors());
		}

		return $result;
	}
}
