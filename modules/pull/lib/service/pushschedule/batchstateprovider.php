<?php

declare(strict_types=1);

namespace Bitrix\Pull\Service\PushSchedule;

use Bitrix\Intranet\Public\Dto\User\Absence\BlockingAbsenceBatchResult;
use Bitrix\Intranet\Public\Provider\User\AbsenceProvider;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Bitrix\Pull\Push;
use Bitrix\Timeman\V2\Public\Dto\Record\CurrentState;
use Bitrix\Timeman\V2\Public\Dto\Record\CurrentStateCollection;
use Bitrix\Timeman\V2\Public\Provider\RecordProvider;

final class BatchStateProvider
{
	private const BATCH_SIZE = 300;
	private const WORK_STATUS_UNKNOWN = 'unknown';
	private const SCHEDULE_TYPE_UNKNOWN = 'unknown';
	private const AUTOMATIC_MODE_UNKNOWN = 'unknown';

	private readonly \Closure $policyResolver;
	private readonly \Closure $currentStatesResolver;
	private readonly \Closure $absencesResolver;
	private readonly \Closure $moduleLoader;
	private readonly \Closure $sourceResolver;
	private array $registeredSourceIds = [];
	private array $statesByUserId = [];

	public function __construct(
		?\Closure $policyResolver = null,
		?\Closure $currentStatesResolver = null,
		?\Closure $absencesResolver = null,
		?\Closure $moduleLoader = null,
		private readonly ?PolicyMetrics $metrics = null,
		?\Closure $sourceResolver = null,
	)
	{
		$this->policyResolver = $policyResolver
			?? static fn (array $userIds): array => (new PolicyRepository())->getForUsers($userIds);
		$this->currentStatesResolver = $currentStatesResolver
			?? static fn (array $userIds, int $at): CurrentStateCollection => (new RecordProvider())
				->getCurrentStates($userIds, $at)
		;
		$this->absencesResolver = $absencesResolver
			?? static function (array $userIds, DateTime $at): BlockingAbsenceBatchResult {
				return (new AbsenceProvider())->getUsersBlockingAbsences($userIds, $at);
			};
		$this->moduleLoader = $moduleLoader
			?? static fn (string $moduleId): bool => Loader::includeModule($moduleId);
		$this->sourceResolver = $sourceResolver
			?? static fn (): array => Push::getSources();
	}

	/**
	 * Loads all external state required to evaluate one delivery batch.
	 *
	 * @param int[] $userIds
	 */
	public function prepare(array $userIds, int $at): void
	{
		$userIds = $this->normalizeUserIds($userIds);
		$this->recordBatchSize(count($userIds));
		$this->statesByUserId = [];
		if ($userIds === [])
		{
			$this->registeredSourceIds = [];

			return;
		}

		$this->registeredSourceIds = $this->resolveRegisteredSourceIds();
		foreach ($userIds as $userId)
		{
			$this->statesByUserId[$userId] = $this->emptyState();
		}

		foreach (array_chunk($userIds, self::BATCH_SIZE) as $userIdChunk)
		{
			$this->loadPolicyChunk($userIdChunk);
		}

		$automaticUserIds = array_values(array_filter(
			$userIds,
			fn (int $userId): bool => $this->isAutomaticEnabled($userId),
		));
		if ($automaticUserIds === [])
		{
			return;
		}

		$this->loadCurrentStates($automaticUserIds, $at);
		$this->loadAbsences($automaticUserIds, $at);
	}

	/**
	 * Returns a fail-open state for users outside the prepared batch.
	 */
	public function getForUser(int $userId): array
	{
		return $this->statesByUserId[$userId] ?? $this->emptyState();
	}

	private function loadPolicyChunk(array $userIds): void
	{
		try
		{
			$policies = ($this->policyResolver)($userIds);
		}
		catch (\Throwable)
		{
			$this->recordProviderError(PolicyMetrics::PROVIDER_POLICY);

			return;
		}

		foreach ($userIds as $userId)
		{
			if (!isset($policies[$userId]) || !is_array($policies[$userId]))
			{
				continue;
			}

			$this->statesByUserId[$userId]['policyTrusted'] = true;
			$this->statesByUserId[$userId]['policy'] = $policies[$userId];
			$this->statesByUserId[$userId]['exceptionSourceIdSet'] = $this->normalizeSourceIdSet(
				$policies[$userId]['exceptionSourceIds'] ?? null,
			);
		}
	}

	private function loadCurrentStates(array $userIds, int $at): void
	{
		if (!$this->includeModule('timeman'))
		{
			$this->recordProviderError(PolicyMetrics::PROVIDER_TIMEMAN);

			return;
		}

		foreach (array_chunk($userIds, self::BATCH_SIZE) as $userIdChunk)
		{
			try
			{
				$states = ($this->currentStatesResolver)($userIdChunk, $at);
			}
			catch (\Throwable)
			{
				$this->recordProviderError(PolicyMetrics::PROVIDER_TIMEMAN);

				continue;
			}

			if (!$states instanceof CurrentStateCollection)
			{
				$this->recordProviderError(PolicyMetrics::PROVIDER_TIMEMAN);

				continue;
			}
			foreach ($states as $state)
			{
				if (!$state instanceof CurrentState || !isset($this->statesByUserId[$state->userId]))
				{
					continue;
				}

				$this->statesByUserId[$state->userId]['workStateTrusted'] = $state->trusted
					&& $state->status !== self::WORK_STATUS_UNKNOWN;
				$this->statesByUserId[$state->userId]['workStatus'] = $state->status;
				$this->statesByUserId[$state->userId]['startTimestamp'] = $state->startTimestamp;
				$this->statesByUserId[$state->userId]['stopTimestamp'] = $state->stopTimestamp;
				$this->statesByUserId[$state->userId]['scheduleType'] = $state->scheduleType;
				$this->statesByUserId[$state->userId]['automaticConfigurationTrusted']
					= $state->automaticConfigurationTrusted;
				$this->statesByUserId[$state->userId]['automaticConfigured'] = $state->automaticConfigured;
				$this->statesByUserId[$state->userId]['automaticMode'] = $state->automaticMode;
			}
		}
	}

	private function loadAbsences(array $userIds, int $at): void
	{
		if (!$this->includeModule('intranet'))
		{
			$this->recordProviderError(PolicyMetrics::PROVIDER_INTRANET);

			return;
		}

		$atDateTime = DateTime::createFromTimestamp($at);
		foreach (array_chunk($userIds, self::BATCH_SIZE) as $userIdChunk)
		{
			try
			{
				$result = ($this->absencesResolver)($userIdChunk, $atDateTime);
			}
			catch (\Throwable)
			{
				$this->recordProviderError(PolicyMetrics::PROVIDER_INTRANET);

				continue;
			}
			if (!$result instanceof BlockingAbsenceBatchResult)
			{
				$this->recordProviderError(PolicyMetrics::PROVIDER_INTRANET);

				continue;
			}

			foreach ($userIdChunk as $userId)
			{
				$hasBlockingAbsence = $result->getForUser($userId);
				if (!$result->isTrusted($userId) || $hasBlockingAbsence === null)
				{
					continue;
				}

				$this->statesByUserId[$userId]['absenceStateTrusted'] = true;
				$this->statesByUserId[$userId]['hasBlockingAbsence'] = $hasBlockingAbsence;
			}
		}
	}

	private function includeModule(string $moduleId): bool
	{
		try
		{
			return (bool)($this->moduleLoader)($moduleId);
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	private function recordBatchSize(int $batchSize): void
	{
		try
		{
			$this->metrics?->recordBatchSize($batchSize);
		}
		catch (\Throwable)
		{
		}
	}

	private function recordProviderError(string $provider): void
	{
		try
		{
			$this->metrics?->recordProviderError($provider);
		}
		catch (\Throwable)
		{
		}
	}

	private function isAutomaticEnabled(int $userId): bool
	{
		$state = $this->statesByUserId[$userId];

		return $state['policyTrusted']
			&& ($state['policy']['exists'] ?? false)
			&& ($state['policy']['automaticEnabled'] ?? false);
	}

	private function normalizeUserIds(array $userIds): array
	{
		return array_values(array_unique(array_filter(
			array_map('intval', $userIds),
			static fn (int $userId): bool => $userId > 0,
		)));
	}

	private function resolveRegisteredSourceIds(): array
	{
		try
		{
			$sources = ($this->sourceResolver)();
		}
		catch (\Throwable)
		{
			return [];
		}
		if (!is_array($sources))
		{
			return [];
		}

		$sourceIds = [];
		foreach ($sources as $source)
		{
			$sourceId = is_array($source) ? ($source['id'] ?? null) : null;
			$sourceIds[] = $sourceId;
		}

		return $this->normalizeSourceIdSet($sourceIds);
	}

	private function normalizeSourceIdSet(mixed $sourceIds): array
	{
		if (!is_array($sourceIds))
		{
			return [];
		}

		$result = [];
		foreach ($sourceIds as $sourceId)
		{
			if (!is_string($sourceId) || preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $sourceId) !== 1)
			{
				continue;
			}

			$result[$sourceId] = true;
		}

		return $result;
	}

	private function emptyState(): array
	{
		return [
			'policyTrusted' => false,
			'policy' => null,
			'exceptionSourceIdSet' => [],
			'registeredSourceIds' => $this->registeredSourceIds,
			'workStateTrusted' => false,
			'workStatus' => self::WORK_STATUS_UNKNOWN,
			'startTimestamp' => null,
			'stopTimestamp' => null,
			'scheduleType' => self::SCHEDULE_TYPE_UNKNOWN,
			'automaticConfigurationTrusted' => false,
			'automaticConfigured' => false,
			'automaticMode' => self::AUTOMATIC_MODE_UNKNOWN,
			'absenceStateTrusted' => false,
			'hasBlockingAbsence' => false,
		];
	}
}
