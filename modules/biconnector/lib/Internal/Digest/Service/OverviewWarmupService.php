<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Digest\Service;

use Bitrix\BIConnector\Integration\Superset\Integrator\Dto;
use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorResponse;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Internal\Digest\DigestDeadline;
use Bitrix\BIConnector\Internal\Digest\Dto\PinnedContextDto;
use Bitrix\BIConnector\Internal\Digest\Provider\DigestDashboardProvider;
use Bitrix\BIConnector\Internal\Digest\Provider\DigestIntegratorProvider;
use Bitrix\BIConnector\Internal\Digest\Reason\DigestReason;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Filter\AppliedFilters;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\UrlParameter\UrlParameters;
use Bitrix\Main\Application;
use Bitrix\Main\Web\Json;

/**
 * Builds the overview of the report once per run, before the agent asks for it.
 *
 * A cold report takes Superset longer to build than the AI service waits for a tool, so the first
 * call of the agent used to time out and the recipient got nothing. The overview is requested with
 * the filters and the url parameters the tool is going to send for the same recipient, so the tool
 * then gets the answer Superset has just built.
 *
 * The card warmup of the run ({@see InstanceWarmupService}) wakes the instance up; this one makes
 * the report itself ready and is done by the context step, which knows the recipient and the pinned
 * values. The card and the overview share one deadline, so the step never waits longer than it.
 *
 * The overview is built on behalf of the recipient and not of the user of the process: the step runs
 * unattended, and the rights and row rules of whoever the process belongs to are not the recipient's.
 */
final class OverviewWarmupService
{
	private const DEADLINE_SECONDS = 120;

	private const MARK_TTL = 86400;
	private const MARK_KEY_PREFIX = 'digest_overview_warm_';
	private const MARK_CACHE_DIR = '/biconnector/digest_overview_warm/';

	public function __construct(
		private readonly DigestIntegratorProvider $integratorProvider,
		private readonly DigestDashboardProvider $dashboardProvider,
	)
	{
	}

	/**
	 * The mark is set after the first attempt whatever its outcome: a report that did not answer
	 * in time is left to the retries of the template, and every other recipient of the run would
	 * only wait the same deadline again.
	 *
	 * The mark stands for the request that was warmed, so a recipient pinned to another period or other
	 * scope values warms the report again. The recipient is not part of the mark: a warmup per recipient
	 * would double the run, and the row rules of the next recipients are left to the budget of the tool.
	 *
	 * @return DigestReason|null Null when the run may go on: the overview is built, or it was already
	 *     attempted in this run for the same request.
	 */
	public function warmOnce(int $userId, PinnedContextDto $pinnedContext): ?DigestReason
	{
		$runId = $pinnedContext->runId;
		$request = self::getRequestFingerprint($pinnedContext);
		if (in_array($request, $this->readMarkedRequests($runId), true))
		{
			return null;
		}

		try
		{
			return $this->warm($userId, $pinnedContext);
		}
		finally
		{
			$this->mark($runId, $request);
		}
	}

	private function warm(int $userId, PinnedContextDto $pinnedContext): ?DigestReason
	{
		$deadline = DigestDeadline::in(self::DEADLINE_SECONDS);

		// The readiness check of the same recipient has just read the report through the same provider.
		$externalId = (int)$this->dashboardProvider->findDashboard($pinnedContext->dashboardId)?->externalId;
		if ($externalId <= 0)
		{
			return null;
		}

		// Without the recipient the overview would be built for someone else, so the warmup is skipped.
		$user = $this->integratorProvider->findUser($userId);
		if ($user === null)
		{
			return null;
		}

		// Filters take the report for its period default only, which the global BI setting decides.
		$dashboard = SupersetDashboardTable::wakeUpObject([
			'ID' => $pinnedContext->dashboardId,
			'EXTERNAL_ID' => $externalId,
		]);

		$integrator = $this->integratorProvider->getIntegrator();

		$cardResponse = $integrator->getDashboardById(
			$externalId,
			$deadline->getStreamTimeout(),
			arbitrateServiceFailure: false,
		);
		$card = $cardResponse->getData();
		if (self::isFailed($cardResponse) || !$card instanceof Dto\Dashboard)
		{
			return DigestReason::DashboardTimeout;
		}

		// Filters the tool is going to send: the pinned period arrives there as an applied filter.
		$callerFilters = [];
		if ($pinnedContext->period !== null)
		{
			$callerFilters[] = ['name' => 'period', 'value' => $pinnedContext->period];
		}

		$filters = new AppliedFilters($userId);
		$resolveResult = $filters->resolve($dashboard, $card, $callerFilters);
		$urlParamsResult = (new UrlParameters($userId))->resolve($dashboard->getId(), $pinnedContext->scopeValues);
		// The same refusal reaches the tool, which reports it in its own words.
		if (!$resolveResult->isSuccess() || !$urlParamsResult->isSuccess())
		{
			return null;
		}

		if ($deadline->isExhausted())
		{
			return DigestReason::DashboardTimeout;
		}

		$response = $integrator->getDashboardOverview(
			$externalId,
			$filters->convertToSupersetExtraFormData($resolveResult->getData()['filters']),
			$urlParamsResult->getData()['urlParams'],
			$deadline->getStreamTimeout(),
			arbitrateServiceFailure: false,
			user: $user,
		);

		return self::isFailed($response) ? DigestReason::DashboardTimeout : null;
	}

	/**
	 * A frozen instance, a timeout and an error answer all mean the report was not built in time.
	 */
	private static function isFailed(IntegratorResponse $response): bool
	{
		return $response->getStatus() !== IntegratorResponse::STATUS_OK || $response->hasErrors();
	}

	/**
	 * @return string[] Fingerprints of the requests already warmed in the run.
	 */
	private function readMarkedRequests(string $runId): array
	{
		$cache = Application::getInstance()->getCache();
		if (!$cache->initCache(self::MARK_TTL, self::getMarkKey($runId), self::MARK_CACHE_DIR))
		{
			return [];
		}

		$requests = $cache->getVars();

		return is_array($requests) ? $requests : [];
	}

	/**
	 * Two recipients writing at once may lose one fingerprint; that costs one more warmup, not a wrong answer.
	 */
	private function mark(string $runId, string $request): void
	{
		$requests = $this->readMarkedRequests($runId);
		$requests[] = $request;

		$cache = Application::getInstance()->getCache();
		$cache->forceRewriting(true);
		if ($cache->startDataCache(self::MARK_TTL, self::getMarkKey($runId), self::MARK_CACHE_DIR))
		{
			$cache->endDataCache(array_values(array_unique($requests)));
		}
	}

	private static function getMarkKey(string $runId): string
	{
		return self::MARK_KEY_PREFIX . $runId;
	}

	/**
	 * The values the request of the warmup is built from, besides the recipient.
	 */
	private static function getRequestFingerprint(PinnedContextDto $pinnedContext): string
	{
		$scopeValues = array_map('strval', $pinnedContext->scopeValues);
		ksort($scopeValues);

		return md5(Json::encode([
			$pinnedContext->dashboardId,
			$pinnedContext->period,
			$scopeValues,
		]));
	}
}
