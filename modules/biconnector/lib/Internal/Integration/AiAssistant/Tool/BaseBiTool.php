<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Definition\Tool\Contract\ToolContract;
use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Access\Model\DashboardAccessItem;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboard;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\BIConnector\Superset\Logger\AiToolsLogger;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;

abstract class BaseBiTool extends ToolContract
{
	protected const INTEGRATOR_TIMEOUT_SEC = 50;
	private const UNAVAILABLE_DASHBOARD_MESSAGE = 'Dashboard is temporarily unavailable. Try again in a moment, or pick another report.';

	private static function isFeatureEnabled(): bool
	{
		return Option::get('biconnector', 'bitrixgpt_bi_constructor', 'N') === 'Y';
	}

	public function canList(int $userId): bool
	{
		return self::isFeatureEnabled();
	}

	public function canRun(int $userId): bool
	{
		if (!self::isFeatureEnabled())
		{
			return false;
		}

		Loader::includeModule('biconnector');

		SupersetInitializer::initializeOrCheckSupersetStatus();

		return true;
	}

	protected function getAccessController(int $userId): AccessController
	{
		return new AccessController($userId);
	}

	/**
	 * Loads a dashboard for read access: existence + status + ACL + sync state.
	 * On success, `getData()['dashboard']` holds the {@see SupersetDashboard}.
	 * Real causes (status mismatch / no external_id) go to the AI tools event
	 * log; the agent only sees a generic "unavailable" message.
	 */
	protected function loadDashboard(int $dashboardId, int $userId): Result
	{
		$result = new Result();

		$dashboard = SupersetDashboardTable::getById($dashboardId)->fetchObject();
		if (!$dashboard)
		{
			return $result->addError(new Error('Dashboard not found.', 'dashboard_not_found'));
		}

		$status = $dashboard->getStatus();
		$allowed = [
			SupersetDashboardTable::DASHBOARD_STATUS_READY,
			SupersetDashboardTable::DASHBOARD_STATUS_DRAFT,
		];
		if (!in_array($status, $allowed, true))
		{
			AiToolsLogger::logErrors(
				[new Error('Dashboard ' . $dashboardId . ' status not ready: ' . $status)],
				['stage' => 'load_dashboard.status', 'dashboard_id' => $dashboardId, 'user_id' => $userId],
			);

			return $result->addError(new Error(self::UNAVAILABLE_DASHBOARD_MESSAGE, 'dashboard_unavailable'));
		}

		$accessItem = DashboardAccessItem::createFromId($dashboardId);
		if (!$this->getAccessController($userId)->check(ActionDictionary::ACTION_BIC_DASHBOARD_VIEW, $accessItem))
		{
			return $result->addError(new Error('Access denied.', 'access_denied'));
		}

		if (!$dashboard->getExternalId())
		{
			AiToolsLogger::logErrors(
				[new Error('Dashboard ' . $dashboardId . ' has no external id (not synced yet)')],
				['stage' => 'load_dashboard.no_external_id', 'dashboard_id' => $dashboardId, 'user_id' => $userId],
			);

			return $result->addError(new Error(self::UNAVAILABLE_DASHBOARD_MESSAGE, 'dashboard_unavailable'));
		}

		return $result->setData(['dashboard' => $dashboard]);
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
	 * @param \Bitrix\Main\Error[] $errors
	 */
	protected static function unavailableDashboardException(array $errors, array $context): McpException
	{
		AiToolsLogger::logErrors($errors, $context);

		return new McpException(self::UNAVAILABLE_DASHBOARD_MESSAGE);
	}
}
