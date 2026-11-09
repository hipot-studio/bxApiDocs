<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\Dashboard;

use Bitrix\BIConnector\Integration\Superset\Integrator\Dto\Dashboard as DashboardDto;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Superset\Logger\AiToolsLogger;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Error;
use Bitrix\Main\Web\Json;

/**
 * Read-side helper for Superset `json_metadata`: fetches the current dict from
 * Superset and answers structural questions (e.g. "does this dashboard already
 * have a time_range filter configured?").
 *
 * Shared by tools that need to merge new keys onto the existing json_metadata
 * (`set_dashboard_content`) or to enforce invariants before transitioning
 * status (`publish_dashboard`).
 *
 * On Superset failure we degrade to an empty dict — partial work beats blocking
 * the agent — but real causes are routed to AiToolsLogger so silent metadata
 * loss never goes unnoticed.
 */
final class DashboardJsonMetadataReader
{
	/**
	 * @param int $externalId Superset dashboard id.
	 * @param int $userId Initiator (for log context).
	 * @param int $dashboardId Local Bitrix dashboard id (for log context).
	 * @param string $stage Caller-specific tag for AiToolsLogger context.
	 * @return array Current `json_metadata` dict, or `[]` on failure.
	 */
	public static function fetch(int $externalId, int $userId, int $dashboardId, string $stage): array
	{
		return self::fetchDashboard($externalId, $userId, $dashboardId, $stage)?->jsonMetadata ?? [];
	}

	/**
	 * The same read as fetch(), telling a failure apart from a dashboard with empty metadata.
	 *
	 * @return DashboardDto|null The dashboard as Superset holds it, or null when it could not be read.
	 */
	public static function fetchDashboard(int $externalId, int $userId, int $dashboardId, string $stage): ?DashboardDto
	{
		$response = IntegratorFactory::getInstance()->getDashboardById($externalId);
		if ($response->hasErrors())
		{
			AiToolsLogger::logErrors(
				$response->getErrors(),
				[
					'stage' => $stage . '.fetch_metadata',
					'user_id' => $userId,
					'dashboard_id' => $dashboardId,
					'external_id' => $externalId,
				],
			);

			return null;
		}

		$dto = $response->getData();
		if (!($dto instanceof DashboardDto))
		{
			AiToolsLogger::logErrors(
				[new Error('getDashboardById returned non-Dashboard payload')],
				[
					'stage' => $stage . '.fetch_metadata.shape',
					'user_id' => $userId,
					'dashboard_id' => $dashboardId,
					'external_id' => $externalId,
				],
			);

			return null;
		}

		return $dto;
	}

	/**
	 * Layout the dashboard is saved with. Superset moves `positions` out of `json_metadata` into
	 * `position_json` on save, so the metadata copy only holds a layout not yet saved that way.
	 */
	public static function savedPositions(DashboardDto $dashboard): array
	{
		if (is_string($dashboard->positionJson) && $dashboard->positionJson !== '')
		{
			try
			{
				$positions = Json::decode($dashboard->positionJson);
			}
			catch (ArgumentException)
			{
				$positions = null;
			}
			if (is_array($positions) && $positions !== [])
			{
				return $positions;
			}
		}

		$positions = $dashboard->jsonMetadata['positions'] ?? [];

		return is_array($positions) ? $positions : [];
	}

	/**
	 * True if `json_metadata.native_filter_configuration` contains at least one
	 * `filter_time` entry with a non-empty default time_range expression.
	 */
	public static function hasTimeRange(array $jsonMetadata): bool
	{
		$config = $jsonMetadata['native_filter_configuration'] ?? null;
		if (!is_array($config))
		{
			return false;
		}

		foreach ($config as $entry)
		{
			if (!is_array($entry) || ($entry['filterType'] ?? '') !== 'filter_time')
			{
				continue;
			}
			$value = $entry['defaultDataMask']['extraFormData']['time_range'] ?? '';
			if (is_string($value) && trim($value) !== '')
			{
				return true;
			}
		}

		return false;
	}
}
