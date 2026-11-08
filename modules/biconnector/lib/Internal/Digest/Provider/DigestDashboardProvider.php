<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Digest\Provider;

use Bitrix\BIConnector\Integration\Superset\Integrator\Dto;
use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorResponse;
use Bitrix\BIConnector\Integration\Superset\Model\Dashboard;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardUrlParameterTable;
use Bitrix\BIConnector\Internal\Digest\Dto\DashboardSnapshotDto;
use Bitrix\BIConnector\Internal\Digest\Period\ScreenPeriodRecognizer;
use Bitrix\BIConnector\Internal\Digest\Period\SupersetExpressionRecognizer;
use Bitrix\BIConnector\Internal\Digest\Service\PeriodTrimmer;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Filter\AppliedFilters;
use Bitrix\BIConnector\Superset\Dashboard\EmbeddedFilter;
use Bitrix\BIConnector\Superset\Dashboard\UrlParameter\Parameter;
use Bitrix\BIConnector\Superset\Dashboard\UrlParameter\ScopeMap;
use Bitrix\BIConnector\Superset\Scope\ScopeService;

/**
 * Reads the local report state the digest readiness check works on.
 * Left open for extension so unit tests can replace it with a stub.
 */
class DigestDashboardProvider
{
	private const NO_FILTER = 'no filter';
	private const ROOT_PATH = ['ROOT_ID'];
	private const UNBOUNDED = ['isBounded' => false, 'overYearLimit' => null];

	/**
	 * Read timeout of the report card request in seconds. The run waits on it with no one to see the wait,
	 * and any failure only pins the last 30 days, so a slow instance is not waited for.
	 */
	private const REMOTE_READ_TIMEOUT = 5;

	/** @var array<int, DashboardSnapshotDto|null> */
	private array $snapshots = [];

	/** @var array<int, Dto\Dashboard> Report id => its card as Superset gave it. */
	private array $remoteDashboards = [];

	/** @var array<int, array{isBounded: bool, overYearLimit: array{from: string, to: string}|null}> */
	private array $dashboardPeriods = [];

	/** @var array<int, string> Report id => the run what is remembered about it was read for. */
	private array $runIds = [];

	/**
	 * @param ScreenPeriodRecognizer|null $periodRecognizer Null counts relative expressions from the day of each run:
	 *     the provider is shared by the process, and a process may outlive the day it was created on.
	 */
	public function __construct(
		private readonly ?ScreenPeriodRecognizer $periodRecognizer = null,
		private readonly DigestIntegratorProvider $integratorProvider = new DigestIntegratorProvider(),
	)
	{
	}

	/**
	 * The provider is shared by the process, and a long-lived process serves one run after another while
	 * the report changes between them. What is remembered about a report belongs to the run it was read for,
	 * so a run the provider has not seen yet reads the report anew. Steps of the same run keep sharing it.
	 */
	public function beginRun(int $dashboardId, string $runId): void
	{
		if (($this->runIds[$dashboardId] ?? null) === $runId)
		{
			return;
		}

		unset(
			$this->snapshots[$dashboardId],
			$this->remoteDashboards[$dashboardId],
			$this->dashboardPeriods[$dashboardId],
		);
		$this->runIds[$dashboardId] = $runId;
	}

	/**
	 * The snapshot is read once per report and reused: the run resolves its
	 * recipients one after another against the same report state, so reading it
	 * again for every recipient would only repeat the same three rows.
	 */
	public function findDashboard(int $dashboardId): ?DashboardSnapshotDto
	{
		if (!array_key_exists($dashboardId, $this->snapshots))
		{
			$this->snapshots[$dashboardId] = $this->readSnapshot($dashboardId);
		}

		return $this->snapshots[$dashboardId];
	}

	/**
	 * Takes a report the caller has already read together with its url parameters and scopes, so the
	 * snapshot costs no queries of its own. A report read without them is left to be read as usual.
	 */
	public function rememberDashboard(Dashboard $dashboard): void
	{
		$ormObject = $dashboard->getOrmObject();
		if (!$ormObject->isUrlParamsFilled() || !$ormObject->isScopeFilled())
		{
			return;
		}

		$scopeCodes = [];
		foreach ($ormObject->getScope() as $scope)
		{
			$scopeCodes[] = (string)$scope->getScopeCode();
		}

		$urlParameterCodes = [];
		foreach ($ormObject->getUrlParams() as $urlParameter)
		{
			$urlParameterCodes[] = (string)$urlParameter->getCode();
		}

		$this->snapshots[$dashboard->getId()] = new DashboardSnapshotDto(
			id: $dashboard->getId(),
			status: $dashboard->getStatus(),
			type: $dashboard->getType(),
			externalId: $dashboard->getExternalId(),
			scopeParameterCodes: $this->filterScopeParameterCodes($urlParameterCodes),
			scopeCodes: $scopeCodes,
		);
	}

	/**
	 * Keeps the report card the warmup of the run has just received: the period check of the same run
	 * reads the native filters from it instead of asking Superset once more.
	 */
	public function rememberRemoteDashboard(int $dashboardId, IntegratorResponse $response): void
	{
		// A new card makes the period answer of the previous one stale, and a failed answer
		// leaves no card that could still be trusted.
		unset($this->remoteDashboards[$dashboardId], $this->dashboardPeriods[$dashboardId]);

		$remoteDashboard = $response->getData();
		if (
			$response->getStatus() === IntegratorResponse::STATUS_OK
			&& !$response->hasErrors()
			&& $remoteDashboard instanceof Dto\Dashboard
		)
		{
			$this->remoteDashboards[$dashboardId] = $remoteDashboard;
		}
	}

	/**
	 * Bounds of a period explicitly selected for the digest. The report's own period lives in Superset
	 * and is intentionally not resolved on the portal: an empty code leaves the choice to the report.
	 *
	 * @return array{from: string, to: string}|null
	 */
	public function resolvePeriodCode(string $periodCode): ?array
	{
		return AppliedFilters::resolvePeriodCode($periodCode);
	}

	/**
	 * Bounds of the global BI setting a report without a period of its own is opened with.
	 * The setting is read by code outside the digest, so its answer is checked here rather than trusted:
	 * `none`, an unknown code, a code without dates or a failed read give no bounds at all.
	 *
	 * @return array{from: string, to: string}|null
	 */
	public function resolvePortalDefaultPeriod(): ?array
	{
		try
		{
			return $this->resolvePeriodCode($this->readPortalDefaultPeriodCode());
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	/**
	 * Tells whether a report left to decide its own period is proven to give finite dates.
	 * There are two proofs. Every date filter has a default the recognizer turns into dates, or no date
	 * filter has a default and the global BI setting it is then opened with has bounds. Either proof also
	 * needs every chart of the report to be in the scope of a date filter. Anything else, a report that
	 * could not be read included, is not a proof: an unbounded run reads whole tables.
	 *
	 * The answer is kept per report: the run asks it for every recipient of the same report.
	 */
	public function isPeriodBoundedByDashboard(int $dashboardId): bool
	{
		return $this->readDashboardPeriod($dashboardId)['isBounded'];
	}

	/**
	 * Bounds of the period a report proven to bound it is opened with, when they span more than
	 * the year limit: such a period is pinned trimmed, the way any other period is.
	 *
	 * @return array{from: string, to: string}|null Null when the report keeps within the limit
	 *     or is not proven to bound its period.
	 */
	public function findDashboardPeriodOverYearLimit(int $dashboardId): ?array
	{
		return $this->readDashboardPeriod($dashboardId)['overYearLimit'];
	}

	/**
	 * The warmup needs the Superset id and nothing else of the report, so it is
	 * read on its own instead of assembling a snapshot to throw away.
	 */
	public function findExternalId(int $dashboardId): ?int
	{
		$row = SupersetDashboardTable::getRow([
			'select' => ['EXTERNAL_ID'],
			'filter' => ['=ID' => $dashboardId],
		]);

		$externalId = (int)($row['EXTERNAL_ID'] ?? 0);

		return $externalId > 0 ? $externalId : null;
	}

	protected function readSnapshot(int $dashboardId): ?DashboardSnapshotDto
	{
		$dashboard = SupersetDashboardTable::getById($dashboardId)->fetchObject();
		if ($dashboard === null)
		{
			return null;
		}

		return new DashboardSnapshotDto(
			id: $dashboardId,
			status: (string)$dashboard->getStatus(),
			type: $dashboard->getType(),
			externalId: $dashboard->getExternalId(),
			scopeParameterCodes: $this->loadScopeParameterCodes($dashboardId),
			scopeCodes: ScopeService::getInstance()->getDashboardScopes($dashboardId),
		);
	}

	/**
	 * The configuration lives in Superset. The card the warmup received is used when there is one;
	 * otherwise the card is asked for with arbitration of its failure given up, as the warmup does:
	 * a single 5xx would leave the instance in ERROR, and only a user visit brings it back.
	 *
	 * @return array|null Native filter configuration; null when the report could not be read with it.
	 */
	protected function readNativeFiltersConfig(int $dashboardId): ?array
	{
		if (!isset($this->remoteDashboards[$dashboardId]))
		{
			$externalId = $this->findDashboard($dashboardId)?->externalId ?? 0;
			if ($externalId <= 0)
			{
				return null;
			}

			$this->rememberRemoteDashboard(
				$dashboardId,
				$this->integratorProvider->getIntegrator()->getDashboardById(
					$externalId,
					self::REMOTE_READ_TIMEOUT,
					arbitrateServiceFailure: false,
				),
			);
		}

		return ($this->remoteDashboards[$dashboardId] ?? null)?->nativeFilterConfig;
	}

	protected function readPortalDefaultPeriodCode(): string
	{
		return EmbeddedFilter\DateTime::getDefaultPeriod();
	}

	/**
	 * @return array{isBounded: bool, overYearLimit: array{from: string, to: string}|null}
	 */
	private function readDashboardPeriod(int $dashboardId): array
	{
		if (!array_key_exists($dashboardId, $this->dashboardPeriods))
		{
			try
			{
				$this->dashboardPeriods[$dashboardId] = $this->resolveDashboardPeriod(
					$this->readNativeFiltersConfig($dashboardId) ?? [],
				);
			}
			catch (\Throwable)
			{
				$this->dashboardPeriods[$dashboardId] = self::UNBOUNDED;
			}
		}

		return $this->dashboardPeriods[$dashboardId];
	}

	/**
	 * @return array{isBounded: bool, overYearLimit: array{from: string, to: string}|null}
	 */
	private function resolveDashboardPeriod(array $nativeFiltersConfig): array
	{
		$periodRecognizer = $this->periodRecognizer ?? new SupersetExpressionRecognizer();

		$dateFilters = [];
		$windows = [];
		$hasDateFilterWithoutDefault = false;
		foreach ($nativeFiltersConfig as $filterConfig)
		{
			if (!is_array($filterConfig) || ($filterConfig['filterType'] ?? null) !== EmbeddedFilter\DateTime::getFilterType())
			{
				continue;
			}

			$dateFilters[] = $filterConfig;

			$default = $this->readDefaultTimeRange($filterConfig);
			if ($default === null)
			{
				$hasDateFilterWithoutDefault = true;

				continue;
			}

			$window = $this->resolveTimeRangeBounds($default, $periodRecognizer);
			if ($window === null)
			{
				return self::UNBOUNDED;
			}

			$windows[] = $window;
		}

		if ($dateFilters === [])
		{
			return self::UNBOUNDED;
		}

		if ($windows === [])
		{
			$portalWindow = $this->resolvePortalDefaultPeriod();
			if ($portalWindow === null)
			{
				return self::UNBOUNDED;
			}

			$windows[] = $portalWindow;
		}
		// A date filter without a default next to one with it is not opened with the global setting,
		// so the charts it alone covers would get no period at all.
		elseif ($hasDateFilterWithoutDefault)
		{
			return self::UNBOUNDED;
		}

		if (!self::isEveryChartInDateFilterScope($dateFilters))
		{
			return self::UNBOUNDED;
		}

		return ['isBounded' => true, 'overYearLimit' => $this->joinWindowsOverYearLimit($windows)];
	}

	/**
	 * @return mixed The default as Superset stores it, not yet checked for a type; null when there is none.
	 */
	private function readDefaultTimeRange(array $filterConfig): mixed
	{
		$dataMask = $filterConfig['defaultDataMask'] ?? null;
		if (!is_array($dataMask))
		{
			return null;
		}

		foreach ([$dataMask['extraFormData']['time_range'] ?? null, $dataMask['filterState']['value'] ?? null] as $value)
		{
			if ($value !== null && $value !== '' && $value !== [])
			{
				return $value;
			}
		}

		return null;
	}

	/**
	 * @return array{from: string, to: string}|null
	 */
	private function resolveTimeRangeBounds(mixed $timeRange, ScreenPeriodRecognizer $periodRecognizer): ?array
	{
		if (!is_string($timeRange))
		{
			return null;
		}

		$timeRange = trim($timeRange);
		if ($timeRange === '' || mb_strtolower($timeRange) === self::NO_FILTER)
		{
			return null;
		}

		$selection = $periodRecognizer->recognize($timeRange);
		if ($selection === null)
		{
			return null;
		}

		return $selection->toBounds() ?? $this->resolvePeriodCode($selection->toFilterPeriod());
	}

	/**
	 * The pinned period acts on every chart, so windows longer than the limit are joined into one:
	 * trimmed from its end, it keeps the latest data any of them shows. Shorter windows are not joined,
	 * so defaults far apart from each other do not make a long window of their own.
	 *
	 * @param array<array{from: string, to: string}> $windows
	 *
	 * @return array{from: string, to: string}|null
	 */
	private function joinWindowsOverYearLimit(array $windows): ?array
	{
		$periodTrimmer = new PeriodTrimmer();
		$longWindows = array_filter(
			$windows,
			static fn(array $window): bool => $periodTrimmer->trim($window) !== null,
		);
		if ($longWindows === [])
		{
			return null;
		}

		return [
			'from' => min(array_column($longWindows, 'from')),
			'to' => max(array_column($longWindows, 'to')),
		];
	}

	/**
	 * A chart no date filter acts on is read with a time range of its own, "No filter" included.
	 * The scope is read from its layout part, the root of the layout with charts excluded from it.
	 * The stored list of charts in scope is not: Superset recomputes it from the scope when the report
	 * is opened, and a report imported from another instance keeps the chart ids of that instance there.
	 *
	 * The card does not carry the layout, so the charts of the report cannot be listed. A chart is proven
	 * covered only when some date filter scoped to the root does not exclude it: the report is bounded when
	 * no chart is excluded from all of them. A scope below the root proves nothing.
	 *
	 * @param array[] $dateFilters
	 */
	private static function isEveryChartInDateFilterScope(array $dateFilters): bool
	{
		$uncovered = null;
		foreach ($dateFilters as $dateFilter)
		{
			$exclusions = self::readScopeExclusions($dateFilter);
			if ($exclusions === null)
			{
				continue;
			}

			$uncovered = $uncovered === null ? $exclusions : array_intersect($uncovered, $exclusions);
			if ($uncovered === [])
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * A filter without a scope is scoped to the whole report, the way Superset creates it.
	 *
	 * @return int[]|null Charts excluded from the whole report; null for a scope that is not the whole report.
	 */
	private static function readScopeExclusions(array $filterConfig): ?array
	{
		if (!array_key_exists('scope', $filterConfig))
		{
			return [];
		}

		$scope = $filterConfig['scope'];
		if (!is_array($scope) || ($scope['rootPath'] ?? null) !== self::ROOT_PATH)
		{
			return null;
		}

		$excluded = $scope['excluded'] ?? [];

		return is_array($excluded) ? self::normalizeChartIds($excluded) : null;
	}

	/**
	 * @return int[]
	 */
	private static function normalizeChartIds(array $ids): array
	{
		$chartIds = [];
		foreach ($ids as $id)
		{
			if (is_int($id) || (is_string($id) && preg_match('/^\d+$/D', $id) === 1))
			{
				$chartId = (int)$id;
				if ($chartId > 0)
				{
					$chartIds[$chartId] = $chartId;
				}
			}
		}

		return array_values($chartIds);
	}

	/**
	 * @return string[]
	 */
	private function loadScopeParameterCodes(int $dashboardId): array
	{
		$registered = SupersetDashboardUrlParameterTable::getList([
			'filter' => ['=DASHBOARD_ID' => $dashboardId],
			'select' => ['CODE'],
		])->fetchAll();

		return $this->filterScopeParameterCodes(array_map(
			static fn(array $row): string => (string)$row['CODE'],
			$registered,
		));
	}

	/**
	 * @param string[] $urlParameterCodes
	 *
	 * @return string[]
	 */
	private function filterScopeParameterCodes(array $urlParameterCodes): array
	{
		$globals = ScopeMap::getGlobals();
		$codes = [];
		foreach ($urlParameterCodes as $urlParameterCode)
		{
			$parameter = Parameter::tryFrom($urlParameterCode);
			if ($parameter !== null && !in_array($parameter, $globals, true))
			{
				$codes[] = $parameter->code();
			}
		}

		return $codes;
	}

}
