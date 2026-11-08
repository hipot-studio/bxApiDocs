<?php

declare(strict_types=1);

namespace Bitrix\BIConnectorMobile\Service;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Access\Model\DashboardAccessItem;
use Bitrix\BIConnector\Configuration\DashboardTariffConfigurator;
use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\Integration\Superset\Model;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Integration\Superset\SupersetController;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\BIConnector\Superset\DomainLinkService;
use Bitrix\BIConnector\Superset\MarketAccessManager;
use Bitrix\BIConnectorMobile\Dto\DashboardViewState;
use Bitrix\Intranet\Settings\Tools\ToolsManager;
use Bitrix\Main\Loader;

/**
 * Resolves the state of the report screen the same way the web detail component decides which template
 * to render (biconnector.apachesuperset.dashboard.detail/class.php::executeComponent). The web makes
 * that decision while rendering a page; on mobile the same decision is an answer to the client, so it
 * lives here and both the state action and the preparation action ask this service.
 *
 * The lookup is read-only apart from initializeOrCheckSupersetStatus(), which the web runs on the very
 * same path: it only refreshes a stale instance status. Installing a report and starting the instance
 * up belong to DashboardPreparationService.
 */
class DashboardViewStateService
{
	public function resolve(int $dashboardId): DashboardViewState
	{
		if (!$this->isBuilderAvailable())
		{
			return DashboardViewState::error(
				DashboardViewState::REASON_SUPERSET_UNAVAILABLE,
				SupersetInitializer::getSupersetStatus(),
			);
		}

		$this->refreshInstanceStatus();
		$supersetStatus = SupersetInitializer::getSupersetStatus();

		$dashboard = $this->findDashboard($dashboardId);
		if (!$dashboard)
		{
			return DashboardViewState::error(DashboardViewState::REASON_NO_ACCESS, $supersetStatus);
		}

		if (!$this->canView($dashboard))
		{
			// The very answer a report that does not exist gets: the screen picks the refusal by the reason
			// alone, so telling the two apart would only tell a user going through ids which of them are
			// taken and what state the reports behind them are in.
			return DashboardViewState::error(DashboardViewState::REASON_NO_ACCESS, $supersetStatus);
		}

		$dashboardStatus = $dashboard->getStatus();
		$dashboardType = $dashboard->getType();

		// A report that was never installed is prepared whatever the instance is doing: the preparation
		// starts the instance up as well, exactly as the web skeleton does.
		if ($dashboardStatus === SupersetDashboardTable::DASHBOARD_STATUS_NOT_INSTALLED)
		{
			return DashboardViewState::preparing(
				$supersetStatus,
				$dashboardStatus,
				$dashboardType,
				needsInstall: true,
				// The web calls the very first opening of a report on a portal with no running instance
				// a first startup, and only then explains that it takes minutes.
				isFirstStartup: $supersetStatus !== SupersetInitializer::SUPERSET_STATUS_READY,
			);
		}

		$preparingStatuses = [
			SupersetInitializer::SUPERSET_STATUS_LOAD,
			SupersetInitializer::SUPERSET_STATUS_DOESNT_EXISTS,
		];

		if (in_array($supersetStatus, $preparingStatuses, true))
		{
			return DashboardViewState::preparing(
				$supersetStatus,
				$dashboardStatus,
				$dashboardType,
				needsInstall: false,
				isFirstStartup: $supersetStatus === SupersetInitializer::SUPERSET_STATUS_DOESNT_EXISTS,
			);
		}

		if ($supersetStatus !== SupersetInitializer::SUPERSET_STATUS_READY)
		{
			return DashboardViewState::error(
				DashboardViewState::REASON_SUPERSET_UNAVAILABLE,
				$supersetStatus,
				$dashboardStatus,
				$dashboardType,
			);
		}

		if ($dashboardStatus === SupersetDashboardTable::DASHBOARD_STATUS_LOAD)
		{
			return DashboardViewState::preparing(
				$supersetStatus,
				$dashboardStatus,
				$dashboardType,
				needsInstall: false,
				isFirstStartup: false,
			);
		}

		if ($dashboardStatus === SupersetDashboardTable::DASHBOARD_STATUS_FAILED)
		{
			return DashboardViewState::error(
				DashboardViewState::REASON_SUPERSET_UNAVAILABLE,
				$supersetStatus,
				$dashboardStatus,
				$dashboardType,
			);
		}

		return DashboardViewState::ready($supersetStatus, $dashboardStatus, $dashboardType);
	}

	/**
	 * The only outbound call of the lookup - a stale ERROR or LOAD makes it ask the proxy - kept in one
	 * place and substitutable for tests.
	 */
	protected function refreshInstanceStatus(): void
	{
		SupersetInitializer::initializeOrCheckSupersetStatus();
	}

	public function findDashboard(int $dashboardId): ?Model\Dashboard
	{
		if ($dashboardId <= 0)
		{
			return null;
		}

		return (new SupersetController())->getDashboardRepository()->getById($dashboardId);
	}

	/**
	 * A report the user may not view and a report unavailable by subscription or by tariff answer the
	 * same way on purpose: the mobile screen has one refusal state, the web separates them only by the
	 * message it prints.
	 */
	public function canView(Model\Dashboard $dashboard): bool
	{
		$accessItem = DashboardAccessItem::createFromArray([
			'ID' => $dashboard->getId(),
			'TYPE' => $dashboard->getType(),
			'STATUS' => $dashboard->getStatus(),
		]);

		if (!AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_DASHBOARD_VIEW, $accessItem))
		{
			return false;
		}

		if (!MarketAccessManager::getInstance()->isDashboardAvailableByType($dashboard->getType()))
		{
			return false;
		}

		$appId = $dashboard->getAppId();

		return !$appId || DashboardTariffConfigurator::isAvailableDashboard($appId);
	}

	/**
	 * Mirrors ApacheSupersetDashboardDetailComponent::isComponentAvailable(): the states where the web
	 * sends the user away from the detail page instead of rendering it.
	 */
	private function isBuilderAvailable(): bool
	{
		$terminalStatuses = [
			SupersetInitializer::SUPERSET_STATUS_DELETED,
			SupersetInitializer::SUPERSET_STATUS_PENDING_DELETE,
			SupersetInitializer::SUPERSET_STATUS_PENDING_DELETE_SUSPENDED,
		];

		if (in_array(SupersetInitializer::getSupersetStatus(), $terminalStatuses, true))
		{
			return false;
		}

		if (!Feature::isBuilderEnabled())
		{
			return false;
		}

		if (
			Loader::includeModule('intranet')
			&& !ToolsManager::getInstance()->checkAvailabilityByToolId('crm_bi')
		)
		{
			return false;
		}

		return DomainLinkService::getInstance()->isLinked() && !SupersetInitializer::isRebindRequired();
	}
}
