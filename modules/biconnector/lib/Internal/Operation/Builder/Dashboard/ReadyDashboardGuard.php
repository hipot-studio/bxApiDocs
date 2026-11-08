<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\Dashboard;

use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\BIConnector\Internal\Operation\Builder\Actor;
use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Internal\Operation\Builder\Refusal;
use Bitrix\BIConnector\Superset\Logger\AiToolsLogger;
use Bitrix\BIConnector\Superset\MarketDashboardManager;
use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * A dashboard that can be queried in Superset right now: the actor may view the dashboard, the BI instance
 * is ready, the dashboard is loaded and synced. The side effects and texts are those of
 * BiToolBehaviour::loadReadyDashboard(), but the dashboard and the right on it are checked first, so a caller
 * without the right neither starts the BI instance nor queues a reinstall of the dashboard.
 * On success getData()['dashboard'] holds the SupersetDashboard.
 */
final class ReadyDashboardGuard
{
	private const PREPARING_MESSAGE = 'Dashboard is preparing, first open takes a moment. Try again shortly.';

	private readonly DashboardGuard $dashboardGuard;

	public function __construct(?DashboardGuard $dashboardGuard = null)
	{
		$this->dashboardGuard = $dashboardGuard ?? new DashboardGuard();
	}

	public function forView(int $dashboardId, Actor $actor): Result
	{
		$viewResult = $this->dashboardGuard->forView($dashboardId, $actor);
		if (!$viewResult->isSuccess())
		{
			return $viewResult;
		}
		$dashboard = $viewResult->getData()['dashboard'];

		$instanceStatus = SupersetInitializer::getSupersetStatus();
		if ($instanceStatus !== SupersetInitializer::SUPERSET_STATUS_READY)
		{
			return $this->refuseInstanceNotReady($instanceStatus, $dashboardId, $actor);
		}

		$status = $dashboard->getStatus();
		$installableStatuses = [
			SupersetDashboardTable::DASHBOARD_STATUS_NOT_INSTALLED,
			SupersetDashboardTable::DASHBOARD_STATUS_FAILED,
		];
		if (in_array($status, $installableStatuses, true))
		{
			Application::getInstance()->addBackgroundJob(static function () use ($dashboardId) {
				MarketDashboardManager::getInstance()->reinstallDashboard($dashboardId);
			});

			return Refusal::error(FailureCode::Unavailable, self::PREPARING_MESSAGE);
		}

		if ($status === SupersetDashboardTable::DASHBOARD_STATUS_LOAD)
		{
			return Refusal::error(FailureCode::Unavailable, self::PREPARING_MESSAGE);
		}

		$readyStatuses = [
			SupersetDashboardTable::DASHBOARD_STATUS_READY,
			SupersetDashboardTable::DASHBOARD_STATUS_DRAFT,
		];
		if (!in_array($status, $readyStatuses, true))
		{
			return $this->refuseNotSynced(
				'Dashboard ' . $dashboardId . ' status not ready: ' . $status,
				'load_dashboard.status',
				$dashboardId,
				$actor,
			);
		}

		if (!$dashboard->getExternalId())
		{
			return $this->refuseNotSynced(
				'Dashboard ' . $dashboardId . ' has no external id (not synced yet)',
				'load_dashboard.no_external_id',
				$dashboardId,
				$actor,
			);
		}

		return $viewResult;
	}

	private function refuseNotSynced(string $detail, string $stage, int $dashboardId, Actor $actor): Result
	{
		AiToolsLogger::logErrors(
			[new Error($detail)],
			['stage' => $stage, 'dashboard_id' => $dashboardId, 'user_id' => $actor->userId],
		);

		return Refusal::error(
			FailureCode::Unavailable,
			Refusal::appendDebugDetail(Refusal::UNAVAILABLE_DASHBOARD_MESSAGE, $detail),
		);
	}

	private function refuseInstanceNotReady(string $instanceStatus, int $dashboardId, Actor $actor): Result
	{
		if ($instanceStatus === SupersetInitializer::SUPERSET_STATUS_DOESNT_EXISTS)
		{
			SupersetInitializer::startupSuperset();
		}

		if (in_array($instanceStatus, [
			SupersetInitializer::SUPERSET_STATUS_DOESNT_EXISTS,
			SupersetInitializer::SUPERSET_STATUS_LOAD,
		], true))
		{
			return Refusal::error(
				FailureCode::Unavailable,
				'BI is preparing, this can take a moment on first use. Try again shortly.',
			);
		}

		if ($instanceStatus === SupersetInitializer::SUPERSET_STATUS_LIMIT_EXCEEDED)
		{
			return Refusal::error(
				FailureCode::Unavailable,
				'Maximum number of BI Builder instances reached for this license key. '
				. 'Disable BI Builder on any other Bitrix24 that uses the same license key, '
				. 'then try again.',
			);
		}

		$detail = 'Instance status ' . $instanceStatus . ' for dashboard ' . $dashboardId;
		AiToolsLogger::logErrors(
			[new Error($detail)],
			[
				'stage' => 'load_dashboard.instance_status',
				'dashboard_id' => $dashboardId,
				'user_id' => $actor->userId,
				'instance_status' => $instanceStatus,
			],
		);

		return Refusal::error(
			FailureCode::Unavailable,
			Refusal::appendDebugDetail('BI is temporarily unavailable. Try again later.', $detail),
		);
	}
}
