<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Definition\Tool\Contract\ToolContract;
use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Access\Model\DashboardAccessItem;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboard;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\BIConnector\Superset\DomainLinkService;
use Bitrix\BIConnector\Superset\Logger\AiToolsLogger;
use Bitrix\BIConnector\Superset\MarketDashboardManager;
use Bitrix\Intranet\Settings\Tools\ToolsManager;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;

abstract class BaseBiTool extends ToolContract
{
	private const UNAVAILABLE_DASHBOARD_MESSAGE = 'Dashboard is temporarily unavailable. Try again in a moment, or pick another report.';

	private static function isBiAvailable(): bool
	{
		if (!Feature::isBitrixGptBiConstructorEnabled())
		{
			return false;
		}

		if (!Loader::includeModule('biconnector'))
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

		if (!DomainLinkService::getInstance()->isLinked())
		{
			return false;
		}

		return true;
	}

	public function canList(int $userId): bool
	{
		return self::isBiAvailable();
	}

	public function canRun(int $userId): bool
	{
		if (!self::isBiAvailable())
		{
			return false;
		}

		SupersetInitializer::initializeOrCheckSupersetStatus();

		return true;
	}

	protected function getAccessController(int $userId): AccessController
	{
		return new AccessController($userId);
	}

	protected function loadViewableDashboard(int $dashboardId, int $userId): Result
	{
		$result = new Result();

		$dashboard = SupersetDashboardTable::getById($dashboardId)->fetchObject();
		if (!$dashboard)
		{
			return $result->addError(new Error('Dashboard not found.', 'dashboard_not_found'));
		}

		$accessItem = DashboardAccessItem::createFromId($dashboardId);
		if (!$this->getAccessController($userId)->check(ActionDictionary::ACTION_BIC_DASHBOARD_VIEW, $accessItem))
		{
			return $result->addError(new Error('Access denied.', 'access_denied'));
		}

		return $result->setData(['dashboard' => $dashboard]);
	}

	protected function loadReadyDashboard(int $dashboardId, int $userId): Result
	{
		$result = new Result();

		$instanceStatus = SupersetInitializer::getSupersetStatus();
		if ($instanceStatus !== SupersetInitializer::SUPERSET_STATUS_READY)
		{
			return $this->handleInstanceNotReady($instanceStatus, $dashboardId, $userId);
		}

		$accessResult = $this->loadViewableDashboard($dashboardId, $userId);
		if (!$accessResult->isSuccess())
		{
			return $accessResult;
		}
		$dashboard = $accessResult->getData()['dashboard'];

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

			return $result->addError(new Error(
				'Dashboard is preparing, first open takes a moment. Try again shortly.',
				'dashboard_preparing',
			));
		}

		if ($status === SupersetDashboardTable::DASHBOARD_STATUS_LOAD)
		{
			return $result->addError(new Error(
				'Dashboard is preparing, first open takes a moment. Try again shortly.',
				'dashboard_preparing',
			));
		}

		$readyStatuses = [
			SupersetDashboardTable::DASHBOARD_STATUS_READY,
			SupersetDashboardTable::DASHBOARD_STATUS_DRAFT,
		];
		if (!in_array($status, $readyStatuses, true))
		{
			$detail = 'Dashboard ' . $dashboardId . ' status not ready: ' . $status;
			AiToolsLogger::logErrors(
				[new Error($detail)],
				['stage' => 'load_dashboard.status', 'dashboard_id' => $dashboardId, 'user_id' => $userId],
			);

			$message = self::appendDebugDetail(self::UNAVAILABLE_DASHBOARD_MESSAGE, $detail);

			return $result->addError(new Error($message, 'dashboard_unavailable'));
		}

		if (!$dashboard->getExternalId())
		{
			$detail = 'Dashboard ' . $dashboardId . ' has no external id (not synced yet)';
			AiToolsLogger::logErrors(
				[new Error($detail)],
				['stage' => 'load_dashboard.no_external_id', 'dashboard_id' => $dashboardId, 'user_id' => $userId],
			);

			$message = self::appendDebugDetail(self::UNAVAILABLE_DASHBOARD_MESSAGE, $detail);

			return $result->addError(new Error($message, 'dashboard_unavailable'));
		}

		return $result->setData(['dashboard' => $dashboard]);
	}

	private function handleInstanceNotReady(string $instanceStatus, int $dashboardId, int $userId): Result
	{
		$result = new Result();

		if ($instanceStatus === SupersetInitializer::SUPERSET_STATUS_DOESNT_EXISTS)
		{
			SupersetInitializer::startupSuperset();
		}

		if (in_array($instanceStatus, [
			SupersetInitializer::SUPERSET_STATUS_DOESNT_EXISTS,
			SupersetInitializer::SUPERSET_STATUS_LOAD,
		], true))
		{
			return $result->addError(new Error(
				'BI is preparing, this can take a moment on first use. Try again shortly.',
				'bi_preparing',
			));
		}

		if ($instanceStatus === SupersetInitializer::SUPERSET_STATUS_LIMIT_EXCEEDED)
		{
			return $result->addError(new Error(
				'Maximum number of BI Builder instances reached for this license key. '
				. 'Disable BI Builder on any other Bitrix24 that uses the same license key, '
				. 'then try again.',
				'bi_limit_exceeded',
			));
		}

		$detail = 'Instance status ' . $instanceStatus . ' for dashboard ' . $dashboardId;
		AiToolsLogger::logErrors(
			[new Error($detail)],
			[
				'stage' => 'load_dashboard.instance_status',
				'dashboard_id' => $dashboardId,
				'user_id' => $userId,
				'instance_status' => $instanceStatus,
			],
		);

		$message = self::appendDebugDetail('BI is temporarily unavailable. Try again later.', $detail);

		return $result->addError(new Error($message, 'bi_unavailable'));
	}

	/**
	 * Translate the first {@see Error} of a failing Result into an McpException
	 * with structured payload (if any) appended as JSON. Throw sites stay in
	 * `executeStructured()` — helpers below the tool layer return Results.
	 */
	protected static function toMcpException(Result $result): McpException
	{
		$errors = $result->getErrors();
		$error = $errors[0] ?? new Error('Unexpected error.');
		$message = $error->getMessage();
		$payload = $error->getCustomData();
		if (is_array($payload) && !empty($payload))
		{
			$message .= '. ' . json_encode($payload, JSON_UNESCAPED_UNICODE);
		}

		return new McpException($message);
	}

	/**
	 * Wrap an Integrator failure in a generic agent-facing message + a detailed
	 * event log entry. Real cause never leaks to the LLM context.
	 *
	 * Logged at WARNING, not ERROR: a failure to read data from Superset is not
	 * something this module can fix (it is a downstream/transient condition — e.g.
	 * a just-installed report whose charts are not queryable yet), so it must not
	 * raise error-level noise. Genuine module-side problems (bad dashboard status,
	 * instance error) are still logged as errors from their own call sites.
	 *
	 * @param \Bitrix\Main\Error[] $errors
	 */
	protected static function unavailableDashboardException(array $errors, array $context): McpException
	{
		AiToolsLogger::logWarning($errors, $context);

		$message = self::UNAVAILABLE_DASHBOARD_MESSAGE;
		if (self::isDebugLogEnabled())
		{
			$details = implode('; ', array_map(static fn(Error $e): string => $e->getMessage(), $errors));
			$message .= ' | bi_debug: ' . $details . ' | ctx: ' . json_encode($context, JSON_UNESCAPED_UNICODE);
		}

		return new McpException($message);
	}

	private static function isDebugLogEnabled(): bool
	{
		return Option::get('biconnector', 'bgpt_bi_expand_log', 'N') === 'Y';
	}

	private static function appendDebugDetail(string $message, string $detail): string
	{
		if (self::isDebugLogEnabled())
		{
			$message .= ' | bi_debug: ' . $detail;
		}

		return $message;
	}
}
