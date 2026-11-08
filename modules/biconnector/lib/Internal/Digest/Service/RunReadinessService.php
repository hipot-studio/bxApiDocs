<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Digest\Service;

use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\BIConnector\Internal\Digest\Contract\LiveWorkflowInstanceProvider;
use Bitrix\BIConnector\Internal\Digest\Dto\RunReadinessDto;
use Bitrix\BIConnector\Internal\Digest\Logger\DigestRunLogger;
use Bitrix\BIConnector\Internal\Digest\Provider\BiAvailabilityProvider;
use Bitrix\BIConnector\Internal\Digest\Provider\BiInstanceStatusProvider;
use Bitrix\BIConnector\Internal\Digest\Reason\DigestReason;

/**
 * Tells whether the run may start at all. Computed once, before the first
 * recipient is resolved.
 *
 * The order is fixed and is not rearranged: configuration predicates, overlap,
 * warmup. Naming a configuration problem as one is what decides whether anybody
 * is asked to change a setting, and mistaking it for a state that resolves
 * itself is expensive both ways, so it is decided first, and always from local
 * state: an answer of the reports instance never becomes a configuration reason.
 */
final class RunReadinessService
{
	public function __construct(
		private readonly BiAvailabilityProvider $availabilityProvider,
		private readonly BiInstanceStatusProvider $instanceStatusProvider,
		private readonly LiveWorkflowInstanceProvider $liveInstanceProvider,
		private readonly InstanceWarmupService $warmupService,
		private readonly DigestRunLogger $runLogger,
	)
	{
	}

	/**
	 * @param int $templateId Schedule the run belongs to, taken from the activity runtime.
	 * @param string|null $ownInstanceId Own workflow instance, from the activity runtime; absent on a synchronous start.
	 * @param int $dashboardId Report the run is about; the warmup reads its card.
	 * @param string $runId Cross-cutting run id, journaled when the run yields to another one.
	 */
	public function check(int $templateId, ?string $ownInstanceId, int $dashboardId, string $runId): RunReadinessDto
	{
		$configurationReason = $this->resolveConfigurationReason();
		if ($configurationReason !== null)
		{
			return RunReadinessDto::refused($configurationReason);
		}

		$overlappingIds = $this->findOverlappingInstanceIds($templateId, $ownInstanceId);
		if ($overlappingIds !== [])
		{
			$this->runLogger->logOverlap($runId, $templateId, $overlappingIds);

			return RunReadinessDto::overlapped();
		}

		$warmupReason = $this->warmupService->warmUp($dashboardId, $runId);
		if ($warmupReason !== null)
		{
			return RunReadinessDto::refused($warmupReason);
		}

		return RunReadinessDto::ready();
	}

	/**
	 * Predicates of the configuration class in the order the reason list fixes:
	 * BI switched off: by the tools option, by the tariff feature or by the
	 * tool itself, an unlinked domain, the instance limit, a deleted instance.
	 */
	private function resolveConfigurationReason(): ?DigestReason
	{
		if (
			!$this->availabilityProvider->isBiToolsOptionEnabled()
			|| !$this->availabilityProvider->isBuilderEnabled()
			|| !$this->availabilityProvider->isBiToolAvailable()
		)
		{
			return DigestReason::BiDisabled;
		}

		if (!$this->availabilityProvider->isDomainLinked())
		{
			return DigestReason::DomainNotLinked;
		}

		return $this->resolveInstanceStateReason($this->instanceStatusProvider->getStatus());
	}

	/**
	 * The instance state is read from what the portal stores about it, so a
	 * configuration reason never depends on a call. Preparing states are not
	 * decided here: they belong to the warmup and are temporary.
	 */
	private function resolveInstanceStateReason(string $instanceStatus): ?DigestReason
	{
		return match ($instanceStatus)
		{
			SupersetInitializer::SUPERSET_STATUS_LIMIT_EXCEEDED => DigestReason::BiLimitExceeded,

			SupersetInitializer::SUPERSET_STATUS_DELETED,
			SupersetInitializer::SUPERSET_STATUS_PENDING_DELETE,
			SupersetInitializer::SUPERSET_STATUS_PENDING_DELETE_SUSPENDED => DigestReason::BiDeleted,

			default => null,
		};
	}

	/**
	 * Own instance is excluded if the selection has it at all: on a synchronous
	 * start the process row is written after the activity has run, so it is not
	 * there yet.
	 *
	 * @return string[]
	 */
	private function findOverlappingInstanceIds(int $templateId, ?string $ownInstanceId): array
	{
		$liveIds = $this->liveInstanceProvider->findLiveInstanceIds($templateId);
		if ($ownInstanceId === null || $ownInstanceId === '')
		{
			return $liveIds;
		}

		return array_values(array_filter(
			$liveIds,
			static fn(string $instanceId): bool => $instanceId !== $ownInstanceId,
		));
	}
}
