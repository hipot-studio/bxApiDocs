<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Digest\Service;

use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorResponse;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\BIConnector\Internal\Digest\Provider\BiInstanceStatusProvider;
use Bitrix\BIConnector\Internal\Digest\Provider\DigestDashboardProvider;
use Bitrix\BIConnector\Internal\Digest\Provider\DigestIntegratorProvider;
use Bitrix\BIConnector\Internal\Digest\Reason\DigestReason;

/**
 * Touches the reports instance once per run, by reading the card of the report
 * the digest is about. The card and not the overview: the outcomes the run tells
 * apart are the ones the request middleware decides, they are the same for every
 * proxy action, and the overview makes the instance execute the whole report —
 * work the run throws away and the first send step then repeats.
 *
 * Waking the instance up is left to the standard handling of the answer: a
 * frozen instance replies 555 and the status middleware moves the state on its
 * own. The run never calls SupersetInitializer::startupSuperset(),
 * unfreezeSuperset() or initializeOrCheckSupersetStatus(): the first two raise
 * an instance behind an unattended schedule, and the third calls startup itself
 * in ERROR and LOAD. An instance in those states is therefore left to the
 * portal's own recovery, and the run answers with a temporary reason.
 *
 * The reading request also gives up arbitration of its own failure: a single
 * answer of the 5xx family would otherwise leave the instance in ERROR, and the
 * run cannot get it out of there: the check below refuses before the call, and
 * the recovery series is opened by a user visit only. The instance keeps the
 * state it had, so the next run by schedule asks it again.
 */
final class InstanceWarmupService
{
	public function __construct(
		private readonly BiInstanceStatusProvider $instanceStatusProvider,
		private readonly DigestIntegratorProvider $integratorProvider,
		private readonly DigestDashboardProvider $dashboardProvider,
	)
	{
	}

	/**
	 * @param string $runId The run the warmup starts: what the provider remembers about the report from
	 *     an earlier run of the same process is not carried into it.
	 *
	 * @return DigestReason|null Null when the run may go on: the instance answered, or there was nothing to ask it.
	 */
	public function warmUp(int $dashboardId, string $runId): ?DigestReason
	{
		$this->dashboardProvider->beginRun($dashboardId, $runId);

		// Anything but READY is refused before the call: the reading request
		// would be stopped by the ready gate anyway, and raising the instance is
		// not something the run is allowed to do.
		if ($this->instanceStatusProvider->getStatus() !== SupersetInitializer::SUPERSET_STATUS_READY)
		{
			return DigestReason::BiPreparing;
		}

		// Nothing to ask about: a report that is gone or not synced yet leaves the
		// warmup with no request to make. Refusing over it belongs to the context
		// step, which owns those reasons and decides them per recipient.
		$externalId = $this->dashboardProvider->findExternalId($dashboardId) ?? 0;
		if ($externalId <= 0)
		{
			return null;
		}

		$response = $this->integratorProvider->getIntegrator()->getDashboardById(
			$externalId,
			arbitrateServiceFailure: false,
		);
		// The card carries the native filters the period check of this run reads.
		$this->dashboardProvider->rememberRemoteDashboard($dashboardId, $response);

		return $this->resolveResponseReason($response);
	}

	/**
	 * A frozen answer is a recognized outcome: the request itself starts the
	 * reactivation and the state is already moved to loading, so the run waits
	 * on it. Every outcome the run cannot read (timeout, service error, unknown
	 * status, a body that did not parse) is a warmup failure and must not be
	 * reported as "preparing".
	 */
	private function resolveResponseReason(IntegratorResponse $response): ?DigestReason
	{
		if ($response->getStatus() === IntegratorResponse::STATUS_FROZEN)
		{
			return DigestReason::BiPreparing;
		}

		if ($response->getStatus() !== IntegratorResponse::STATUS_OK || $response->hasErrors())
		{
			return DigestReason::WarmupFailed;
		}

		return null;
	}
}
