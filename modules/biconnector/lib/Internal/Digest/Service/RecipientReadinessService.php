<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Digest\Service;

use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\BIConnector\Internal\Digest\DigestPeriod;
use Bitrix\BIConnector\Internal\Digest\DigestPeriodSelection;
use Bitrix\BIConnector\Internal\Digest\Dto\DashboardSnapshotDto;
use Bitrix\BIConnector\Internal\Digest\Dto\PinnedContextDto;
use Bitrix\BIConnector\Internal\Digest\Dto\ReadinessDto;
use Bitrix\BIConnector\Internal\Digest\Provider\BiInstanceStatusProvider;
use Bitrix\BIConnector\Internal\Digest\Provider\DashboardViewRightChecker;
use Bitrix\BIConnector\Internal\Digest\Provider\DigestDashboardProvider;
use Bitrix\BIConnector\Internal\Digest\Provider\ScopeValueAccessChecker;
use Bitrix\BIConnector\Internal\Digest\Reason\DigestReason;
use Bitrix\BIConnector\Superset\Dashboard\UrlParameter\Parameter;
use Bitrix\BIConnector\Superset\Dashboard\UrlParameter\ScopeMap;
use Bitrix\BIConnector\Superset\Logger\Logger;
use Bitrix\Main\Type\Date;

/**
 * Tells whether one recipient can be sent a digest of one report, and what has
 * to be pinned for the send step.
 *
 * The checks run cheapest-and-most-permanent first: a settings problem outlives
 * any retry, so it wins over a state that resolves itself. The order (report
 * exists, recipient's right, foreign scope value, scope configuration against
 * the report, no accessible value at all, configured value not accessible,
 * instance state, report status, sync state) is fixed by the reason list;
 * the first condition that holds is the reason, the rest is not computed.
 */
final class RecipientReadinessService
{
	private const READY_DASHBOARD_STATUSES = [
		SupersetDashboardTable::DASHBOARD_STATUS_READY,
		SupersetDashboardTable::DASHBOARD_STATUS_DRAFT,
	];

	private const FALLBACK_EMPTY = 'empty';
	private const FALLBACK_UNKNOWN = 'unknown';
	private const FALLBACK_MALFORMED = 'malformed';
	private const FALLBACK_PORTAL_UNBOUNDED = 'portal_unbounded';
	private const FALLBACK_DASHBOARD_UNBOUNDED = 'report_unbounded';
	// A rolling period biconnector still resolves; the digest period list no longer offers it.
	private const FALLBACK_PERIOD_CODE = 'last_30';

	private readonly PeriodTrimmer $periodTrimmer;
	private readonly \Closure $logPeriodFallback;

	/**
	 * @param \Closure(string, int, string): void|null $logPeriodFallback Receives the reason, the report id and
	 *     the run id of a period replaced with the last 30 days.
	 */
	public function __construct(
		private readonly DigestDashboardProvider $dashboardProvider,
		private readonly DashboardViewRightChecker $rightChecker,
		private readonly BiInstanceStatusProvider $instanceStatusProvider,
		private readonly ScopeValueAccessChecker $scopeValueAccessChecker,
		private readonly DashboardInstallService $installService,
		?\Closure $logPeriodFallback = null,
	)
	{
		// Not a dependency of the service: the trimming is a pure calculation over
		// the report period, with nothing to configure and nothing to replace.
		$this->periodTrimmer = new PeriodTrimmer();
		$this->logPeriodFallback = $logPeriodFallback ?? static function (string $reason, int $dashboardId, string $runId): void {
			Logger::logInfo('BI digest run: the period gives no bounds, the last 30 days are pinned', [
				'reason' => $reason,
				'dashboard_id' => $dashboardId,
				'run_id' => $runId,
			]);
		};
	}

	/**
	 * @param array<string, int> $scopeValues Scope parameter code => configured value id; an unset parameter is absent.
	 * @param string $storedPeriod The period value of the agent setup as stored: a code of {@see DigestPeriod},
	 *     a fixed range, or whatever an older or broken setup left there, empty included.
	 */
	public function check(
		int $recipientId,
		int $dashboardId,
		array $scopeValues,
		string $runId,
		string $storedPeriod = '',
	): ReadinessDto
	{
		$this->dashboardProvider->beginRun($dashboardId, $runId);

		return $this->checkRecipient(
			$recipientId,
			$dashboardId,
			$scopeValues,
			$runId,
			$storedPeriod,
			true,
		);
	}

	/**
	 * Nothing is sent after this check, so no period is resolved and the report is not asked about it.
	 *
	 * @param array<string, int> $scopeValues
	 */
	public function checkForSetup(int $recipientId, int $dashboardId, array $scopeValues): ReadinessDto
	{
		return $this->checkRecipient($recipientId, $dashboardId, $scopeValues, '', null, false);
	}

	/**
	 * @param array<string, int> $scopeValues
	 * @param string|null $storedPeriod Null when the check is not for a run.
	 */
	private function checkRecipient(
		int $recipientId,
		int $dashboardId,
		array $scopeValues,
		string $runId,
		?string $storedPeriod,
		bool $requestDashboardInstall,
	): ReadinessDto
	{
		$dashboard = $this->dashboardProvider->findDashboard($dashboardId);
		if ($dashboard === null)
		{
			return ReadinessDto::refused(DigestReason::DashboardNotFound);
		}

		if (!$this->rightChecker->canView($recipientId, $dashboard))
		{
			return ReadinessDto::refused(DigestReason::NoDashboardRight);
		}

		$scopeReason = $this->resolveScopeReason($recipientId, $dashboard, $scopeValues);
		if ($scopeReason !== null)
		{
			return ReadinessDto::refused($scopeReason);
		}

		// Configuration-class instance states (BI off, unlinked domain, limit,
		// deleted) are refused once for the whole run before recipients are
		// resolved, so anything but READY here is a state that resolves itself.
		if ($this->instanceStatusProvider->getStatus() !== SupersetInitializer::SUPERSET_STATUS_READY)
		{
			return ReadinessDto::refused(DigestReason::BiPreparing);
		}

		if (!in_array($dashboard->status, self::READY_DASHBOARD_STATUSES, true))
		{
			// A report nobody has ever opened is not on its way anywhere: it reaches
			// the reports instance on the first open in the BI builder. So the run
			// asks for the installation and goes on waiting - the state does resolve
			// itself once somebody, here the run itself, has asked for it.
			if (
				$requestDashboardInstall
				&& $dashboard->status === SupersetDashboardTable::DASHBOARD_STATUS_NOT_INSTALLED
			)
			{
				$this->installService->requestInstall($dashboardId);
			}

			return ReadinessDto::refused(DigestReason::DashboardPreparing);
		}

		if (($dashboard->externalId ?? 0) <= 0)
		{
			return ReadinessDto::refused(DigestReason::DashboardNotSynced);
		}

		return ReadinessDto::ready(new PinnedContextDto(
			dashboardId: $dashboardId,
			scopeValues: $scopeValues,
			runId: $runId,
			period: $storedPeriod === null ? null : $this->resolvePinnedPeriod($storedPeriod, $dashboardId, $runId),
		));
	}

	/**
	 * The period to pin. A digest never runs without bounds: with no period pinned the tools may read
	 * whole tables. So nothing is pinned only for "as in the report" of a report proven to give finite
	 * dates itself within the year limit; a longer period of such a report is pinned trimmed, every other
	 * value is pinned with bounds, and any doubt pins the last 30 days counted from this check.
	 *
	 * A code of the list is resolved on every run, a fixed range keeps the bounds it was stored with;
	 * both are trimmed to a year the same way.
	 *
	 * @return array{from: string, to: string}|null
	 */
	private function resolvePinnedPeriod(string $storedPeriod, int $dashboardId, string $runId): ?array
	{
		$fallbackReason = null;
		$bounds = null;

		$selection = DigestPeriodSelection::fromStored($storedPeriod);
		if ($storedPeriod === '')
		{
			$fallbackReason = self::FALLBACK_EMPTY;
		}
		elseif ($selection === null)
		{
			$fallbackReason = DigestPeriodSelection::isRangeForm($storedPeriod)
				? self::FALLBACK_MALFORMED
				: self::FALLBACK_UNKNOWN
			;
		}
		elseif ($selection->isFixedRange())
		{
			$bounds = $selection->toBounds();
		}
		elseif ($storedPeriod === DigestPeriod::Report->value)
		{
			if ($this->dashboardProvider->isPeriodBoundedByDashboard($dashboardId))
			{
				$dashboardBounds = $this->dashboardProvider->findDashboardPeriodOverYearLimit($dashboardId);

				return $dashboardBounds === null
					? null
					: ($this->periodTrimmer->trim($dashboardBounds) ?? $dashboardBounds)
				;
			}

			$fallbackReason = self::FALLBACK_DASHBOARD_UNBOUNDED;
		}
		elseif ($storedPeriod === DigestPeriod::PortalDefault->value)
		{
			$bounds = $this->dashboardProvider->resolvePortalDefaultPeriod();
			$fallbackReason = $bounds === null ? self::FALLBACK_PORTAL_UNBOUNDED : null;
		}
		else
		{
			// The code of the selection, not the stored one: a legacy rolling code is resolved as its entry.
			$bounds = $this->dashboardProvider->resolvePeriodCode($selection->toFilterPeriod());
			$fallbackReason = $bounds === null ? self::FALLBACK_UNKNOWN : null;
		}

		if ($fallbackReason !== null)
		{
			($this->logPeriodFallback)($fallbackReason, $dashboardId, $runId);
			$bounds = $this->dashboardProvider->resolvePeriodCode(self::FALLBACK_PERIOD_CODE)
				?? $this->countLast30Days()
			;
		}

		return $this->periodTrimmer->trim($bounds) ?? $bounds;
	}

	/**
	 * The fallback itself must not depend on the shared period list: a code dropped from it there would
	 * leave the run with no bounds at all. Counted the same way the list counts its rolling periods.
	 *
	 * @return array{from: string, to: string}
	 */
	private function countLast30Days(): array
	{
		$today = new Date();

		return [
			'from' => (clone $today)->add('-30 days')->format('Y-m-d'),
			'to' => (clone $today)->add('-1 day')->format('Y-m-d'),
		];
	}

	/**
	 * An unset scope parameter has to be refused here, before the report is
	 * asked for data: at that point a missing value turns into a question for
	 * the user, and an unattended digest has nobody to ask.
	 *
	 * @param array<string, int> $scopeValues
	 */
	private function resolveScopeReason(
		int $recipientId,
		DashboardSnapshotDto $dashboard,
		array $scopeValues,
	): ?DigestReason
	{
		$dashboardParameters = $this->collectScopeParameters($dashboard->scopeCodes);

		$configured = [];
		foreach (array_keys($scopeValues) as $code)
		{
			$parameter = Parameter::tryFrom((string)$code);
			if ($parameter === null || !isset($dashboardParameters[$parameter->code()]))
			{
				return DigestReason::ScopeValueForeignToDashboard;
			}

			$configured[$parameter->code()] = $parameter;
		}

		$required = $this->filterScopeParameters($dashboard->scopeParameterCodes);
		if (!$this->isSameParameterSet($required, $configured))
		{
			return DigestReason::ScopeParametersMismatch;
		}

		$isEveryValueAccessible = true;
		foreach ($configured as $code => $parameter)
		{
			if (!$this->scopeValueAccessChecker->isValueAccessible($parameter, $recipientId, (int)$scopeValues[$code]))
			{
				$isEveryValueAccessible = false;

				break;
			}
		}

		if ($isEveryValueAccessible)
		{
			return null;
		}

		// "No accessible value at all" outranks "the configured value is not among
		// them", but it is only asked after a refusal: a configured value the
		// recipient may see is itself proof that the list is not empty, and the
		// question costs a grouped scan while the exact one does not.
		foreach ($configured as $parameter)
		{
			if (!$this->scopeValueAccessChecker->hasAnyAccessibleValue($parameter, $recipientId))
			{
				return DigestReason::NoAccessibleScopeValue;
			}
		}

		return DigestReason::ScopeValueNotAccessible;
	}

	/**
	 * Parameters the report's own scopes imply, read from the local scope map,
	 * without a call to the reports instance.
	 *
	 * Globals stay in this map on purpose, unlike in {@see self::filterScopeParameters()}:
	 * this one answers "does the report know this parameter at all", and a global
	 * one it does know is not foreign to it. It is not part of the configurable
	 * set either, so a value configured for it is refused a step later, as a
	 * mismatch of the parameter set.
	 *
	 * @param string[] $scopeCodes
	 *
	 * @return array<string, Parameter>
	 */
	private function collectScopeParameters(array $scopeCodes): array
	{
		$parameters = [];
		foreach ($scopeCodes as $scopeCode)
		{
			foreach (ScopeMap::getScopeParameters((string)$scopeCode) ?? [] as $parameter)
			{
				$parameters[$parameter->code()] = $parameter;
			}
		}

		return $parameters;
	}

	/**
	 * @param string[] $codes
	 *
	 * @return array<string, Parameter>
	 */
	private function filterScopeParameters(array $codes): array
	{
		$globals = ScopeMap::getGlobals();
		$parameters = [];
		foreach ($codes as $code)
		{
			$parameter = Parameter::tryFrom((string)$code);
			if ($parameter !== null && !in_array($parameter, $globals, true))
			{
				$parameters[$parameter->code()] = $parameter;
			}
		}

		return $parameters;
	}

	/**
	 * @param array<string, Parameter> $required
	 * @param array<string, Parameter> $configured
	 */
	private function isSameParameterSet(array $required, array $configured): bool
	{
		$requiredCodes = array_keys($required);
		$configuredCodes = array_keys($configured);
		sort($requiredCodes);
		sort($configuredCodes);

		return $requiredCodes === $configuredCodes;
	}
}
