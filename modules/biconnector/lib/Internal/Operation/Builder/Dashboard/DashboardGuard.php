<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\Dashboard;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Access\Model\DashboardAccessItem;
use Bitrix\BIConnector\Configuration\DashboardTariffConfigurator;
use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Internal\Operation\Builder\Actor;
use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Superset\Logger\AiToolsLogger;
use Bitrix\BIConnector\Superset\MarketAccessManager;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Loads a dashboard for a builder operation with the checks of the MCP tool loaders
 * (BiToolBehaviour::loadViewableDashboard() and loadDashboardForWrite()), refusing with
 * the same texts. On success getData()['dashboard'] holds the SupersetDashboard.
 * forView() also refuses, as the web does, a Market dashboard without the Market subscription
 * and a dashboard closed on the plan of the portal.
 *
 * Only the loader level is repeated. The entry point stays responsible for:
 * - the availability gates of BaseBiTool::canRun() / BiToolBehaviour::isBiAvailable():
 *   Feature::isBitrixGptBiConstructorEnabled(), the crm_bi tool switched off by the administrator
 *   in ToolsManager, DomainLinkService::isLinked();
 * - its rate limiter;
 * - ACTION_BIC_DASHBOARD_DELETE with DashboardDeleteRule for deletion, forWrite() checks EDIT only;
 * - taking Actor::$userId from the authenticated context (CurrentUser, REST auth),
 *   never from request parameters.
 */
final class DashboardGuard
{
	private const UNAVAILABLE_DASHBOARD_MESSAGE =
		'Dashboard is temporarily unavailable. Try again in a moment, or pick another report.'
	;

	/** @var \Closure(int): AccessController */
	private readonly \Closure $accessControllerFactory;
	/** @var \Closure(string): bool */
	private readonly \Closure $isAvailableBySubscription;
	/** @var \Closure(string): bool */
	private readonly \Closure $isAvailableOnPlan;

	/**
	 * @param (\Closure(int): AccessController)|null $accessControllerFactory
	 * @param (\Closure(string): bool)|null $isAvailableBySubscription Takes the type of the dashboard.
	 * @param (\Closure(string): bool)|null $isAvailableOnPlan Takes the APP_ID of the dashboard.
	 */
	public function __construct(
		?\Closure $accessControllerFactory = null,
		?\Closure $isAvailableBySubscription = null,
		?\Closure $isAvailableOnPlan = null,
	)
	{
		$this->accessControllerFactory = $accessControllerFactory
			?? static fn(int $userId): AccessController => new AccessController($userId)
		;
		$this->isAvailableBySubscription = $isAvailableBySubscription
			?? static fn(string $type): bool => MarketAccessManager::getInstance()->isDashboardAvailableByType($type)
		;
		$this->isAvailableOnPlan = $isAvailableOnPlan
			?? static fn(string $appId): bool => DashboardTariffConfigurator::isAvailableDashboard($appId)
		;
	}

	public function forView(int $dashboardId, Actor $actor): Result
	{
		$result = new Result();

		$dashboard = SupersetDashboardTable::getById($dashboardId)->fetchObject();
		if (!$dashboard)
		{
			return $result->addError(new Error('Dashboard not found.', FailureCode::NotFound->value));
		}

		if (!$this->canAct(ActionDictionary::ACTION_BIC_DASHBOARD_VIEW, $dashboardId, $actor))
		{
			return $result->addError(new Error('Access denied.', FailureCode::AccessDenied->value));
		}

		if (!($this->isAvailableBySubscription)($dashboard->getType()))
		{
			return $result->addError(new Error(
				'Market dashboard is unavailable: the Bitrix24 Market subscription has expired.',
				FailureCode::AccessDenied->value,
			));
		}

		$appId = (string)$dashboard->getAppId();
		if ($appId !== '' && !($this->isAvailableOnPlan)($appId))
		{
			return $result->addError(new Error(
				'Dashboard is not available on your plan.',
				FailureCode::AccessDenied->value,
			));
		}

		return $result->setData(['dashboard' => $dashboard]);
	}

	public function forWrite(int $dashboardId, Actor $actor): Result
	{
		$result = new Result();

		if (!Feature::isBuilderEnabled())
		{
			return $result->addError(new Error(
				'BI Builder feature is not enabled on this portal.',
				FailureCode::FeatureDisabled->value,
			));
		}

		$dashboard = SupersetDashboardTable::getById($dashboardId)->fetchObject();
		if (!$dashboard)
		{
			return $result->addError(new Error('Dashboard not found.', FailureCode::NotFound->value));
		}

		if ($dashboard->getType() !== SupersetDashboardTable::DASHBOARD_TYPE_CUSTOM)
		{
			return $result->addError(new Error(
				'Only custom dashboards can be updated.',
				FailureCode::NotCustom->value,
			));
		}

		$status = $dashboard->getStatus();
		$editableStatuses = [
			SupersetDashboardTable::DASHBOARD_STATUS_READY,
			SupersetDashboardTable::DASHBOARD_STATUS_DRAFT,
		];
		if (!in_array($status, $editableStatuses, true))
		{
			AiToolsLogger::logErrors(
				[new Error('Dashboard ' . $dashboardId . ' status not editable: ' . $status)],
				[
					'stage' => 'load_dashboard_for_write.status',
					'dashboard_id' => $dashboardId,
					'user_id' => $actor->userId,
				],
			);

			return $result->addError(new Error(self::UNAVAILABLE_DASHBOARD_MESSAGE, FailureCode::Unavailable->value));
		}

		if (!$this->canAct(ActionDictionary::ACTION_BIC_DASHBOARD_EDIT, $dashboardId, $actor))
		{
			return $result->addError(new Error('Access denied.', FailureCode::AccessDenied->value));
		}

		if (!$dashboard->getExternalId())
		{
			AiToolsLogger::logErrors(
				[new Error('Dashboard ' . $dashboardId . ' has no external id (not synced yet)')],
				[
					'stage' => 'load_dashboard_for_write.no_external_id',
					'dashboard_id' => $dashboardId,
					'user_id' => $actor->userId,
				],
			);

			return $result->addError(new Error(self::UNAVAILABLE_DASHBOARD_MESSAGE, FailureCode::Unavailable->value));
		}

		return $result->setData(['dashboard' => $dashboard]);
	}

	private function canAct(string $action, int $dashboardId, Actor $actor): bool
	{
		$accessController = ($this->accessControllerFactory)($actor->userId);

		return $accessController->check($action, DashboardAccessItem::createFromId($dashboardId));
	}
}
