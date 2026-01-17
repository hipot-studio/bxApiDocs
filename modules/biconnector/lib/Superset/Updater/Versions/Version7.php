<?php

namespace Bitrix\BIConnector\Superset\Updater\Versions;

use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\Main;
use Bitrix\Main\Result;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;

/**
 * Delete system duplicate dashboards.
 *
 * Delete logic for dashboards with same APP_ID:
 * Sort dashboards by status priority (READY > LOAD > FAILED > NOT_INSTALLED),
 * then by creation date (older first), then by ID (smaller first).
 * Keep the first dashboard, delete the rest.
 */
final class Version7 extends BaseVersion
{
	public function run(): Result
	{
		$result = new Result();
		$supersetStatus = SupersetInitializer::getSupersetStatus();

		if ($supersetStatus === SupersetInitializer::SUPERSET_STATUS_DELETED)
		{
			return $result;
		}

		if (
			!in_array($supersetStatus, [
					SupersetInitializer::SUPERSET_STATUS_READY,
					SupersetInitializer::SUPERSET_STATUS_DOESNT_EXISTS,
				], true)
		)
		{
			$result->addError(new Main\Error('Superset status is not READY or DOESNT_EXISTS'));

			return $result;
		}

		$duplicateAppId = self::getDuplicateDashboardsAppId();
		if (empty($duplicateAppId))
		{
			return $result;
		}

		$duplicateDashboards = self::getDuplicateDashboards($duplicateAppId);
		self::deleteExtraDashboards($duplicateDashboards);

		return $result;
	}

	private static function getDuplicateDashboardsAppId(): array
	{
		$searchSystemDuplicatesResult = SupersetDashboardTable::getList([
			'select' => [
				'APP_ID',
				'DUPLICATE_COUNT' => new \Bitrix\Main\ORM\Fields\ExpressionField('DUPLICATE_COUNT', 'COUNT(*)'),
			],
			'filter' => [
				'=TYPE' => SupersetDashboardTable::DASHBOARD_TYPE_SYSTEM,
				'>DUPLICATE_COUNT' => 1,
			],
			'group' => ['APP_ID'],
		])
			->fetchAll()
		;

		return array_column($searchSystemDuplicatesResult, 'APP_ID');
	}

	private static function getDuplicateDashboards(array $duplicateAppId): array
	{
		$allDashboards = SupersetDashboardTable::getList([
			'select' => [
				'ID',
				'APP_ID',
				'STATUS',
				'DATE_CREATE',
			],
			'filter' => [
				'=TYPE' => SupersetDashboardTable::DASHBOARD_TYPE_SYSTEM,
				'=APP_ID' => $duplicateAppId,
			],
		])
			->fetchCollection()
		;

		$dashboardsByAppId = [];
		foreach ($allDashboards as $dashboard)
		{
			$dashboardsByAppId[$dashboard->getAppId()][] = $dashboard;
		}

		return $dashboardsByAppId;
	}

	private static function deleteExtraDashboards(array $duplicateDashboards): void
	{
		$allDashboardsToDelete = [];

		foreach ($duplicateDashboards as $appId => $dashboards)
		{
			if (count($dashboards) <= 1)
			{
				continue;
			}

			usort($dashboards, static function ($a, $b) {
				$statusPriority = [
					SupersetDashboardTable::DASHBOARD_STATUS_READY => 1,
					SupersetDashboardTable::DASHBOARD_STATUS_LOAD => 2,
					SupersetDashboardTable::DASHBOARD_STATUS_FAILED => 3,
					SupersetDashboardTable::DASHBOARD_STATUS_NOT_INSTALLED => 4,
				];

				$aStatusPriority = $statusPriority[$a->getStatus()] ?? 100;
				$bStatusPriority = $statusPriority[$b->getStatus()] ?? 100;

				$statusComparison = $aStatusPriority <=> $bStatusPriority;
				if ($statusComparison !== 0)
				{
					return $statusComparison;
				}

				$dateComparison = $a->getDateCreate() <=> $b->getDateCreate();
				if ($dateComparison !== 0)
				{
					return $dateComparison;
				}

				return $a->getId() <=> $b->getId();
			});

			array_shift($dashboards);

			foreach ($dashboards as $dashboard)
			{
				$allDashboardsToDelete[] = $dashboard->getId();
			}
		}

		if (empty($allDashboardsToDelete))
		{
			return;
		}

		foreach ($allDashboardsToDelete as $dashboardId)
		{
			SupersetDashboardTable::delete($dashboardId);
		}
	}
}
