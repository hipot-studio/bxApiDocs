<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Digest\Service;

use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Superset\MarketDashboardManager;
use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlExpression;

/**
 * Starts the installation of a report the digest was pointed at but that nobody
 * has ever opened. System reports reach the reports instance lazily, on the first
 * open in the BI builder, so a report left in NOT_INSTALLED never gets there on
 * its own: an unattended run has to ask for the installation itself and then wait
 * for it the way it waits for any other preparing state.
 *
 * The installation itself is left to the end of the request: it downloads the
 * application and imports its configuration, which is far too long to hold a step
 * of the run, and the run has nothing to do with the outcome anyway - it refuses
 * this recipient either way and comes back later.
 *
 * Left open for extension so tests can watch the request without installing.
 */
class DashboardInstallService
{
	/** @var array<int, true> */
	private array $requested = [];

	public function __construct(
		private readonly MarketDashboardManager $dashboardManager,
		private readonly Application $application,
	)
	{
	}

	/**
	 * Asked once per report, whatever the run does next: a run resolves up to a
	 * hundred recipients against the same report and every one of them walks the
	 * same check. Between runs the guard is the status of the report itself - the
	 * installation moves it out of NOT_INSTALLED before it does anything else, so
	 * a repeat of the run no longer reaches this request.
	 */
	public function requestInstall(int $dashboardId): void
	{
		if (isset($this->requested[$dashboardId]))
		{
			return;
		}

		$this->requested[$dashboardId] = true;

		$this->scheduleInstall($dashboardId);
	}

	/**
	 * Atomically claims a report before starting the installation. The snapshot
	 * the run works on may be stale, and another process may request the same
	 * report concurrently.
	 */
	public function installIfStillNeeded(int $dashboardId): void
	{
		if (!$this->claimInstallation($dashboardId))
		{
			return;
		}

		$this->dashboardManager->reinstallDashboard($dashboardId);
	}

	protected function claimInstallation(int $dashboardId): bool
	{
		$connection = SupersetDashboardTable::getEntity()->getConnection();
		$sql = (new SqlExpression(
			'UPDATE ?# SET ?# = ?s WHERE ?# = ?i AND ?# = ?s AND ?# IS NOT NULL',
			SupersetDashboardTable::getTableName(),
			'STATUS',
			SupersetDashboardTable::DASHBOARD_STATUS_LOAD,
			'ID',
			$dashboardId,
			'STATUS',
			SupersetDashboardTable::DASHBOARD_STATUS_NOT_INSTALLED,
			'APP_ID',
		))->compile();

		$connection->queryExecute($sql);
		$claimed = $connection->getAffectedRowsCount() === 1;
		if ($claimed)
		{
			SupersetDashboardTable::cleanCache();
		}

		return $claimed;
	}

	protected function scheduleInstall(int $dashboardId): void
	{
		$this->application->addBackgroundJob(
			fn() => $this->installIfStillNeeded($dashboardId),
		);
	}
}
