<?php

namespace Bitrix\Crm\Integration\AI\Operation;

use Bitrix\Crm\Activity\Provider\OpenLine;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Enum\GlobalSetting;
use Bitrix\Crm\Integration\AI\JobRepository;
use Bitrix\Crm\Integration\AI\Result;
use Bitrix\Crm\ItemIdentifier;
use CCrmActivity;

/**
 * @deprecated
 */
class OperationState
{
	private ?Result $transcriptionResult = null;
	private ?Result $callScoringResult = null;
	private ?Result $summarizeResult = null;
	private ?Result $fillResult = null;
	private ?Result $analyzeCommunicationResult = null;

	private bool $transcriptionResultLoaded = false;
	private bool $callScoringResultLoaded = false;
	private bool $summarizeResultLoaded = false;
	private bool $fillResultLoaded = false;
	private bool $analyzeCommunicationResultLoaded = false;
	private ?array $entityBindings = null;
	private bool $entityBindingsLoaded = false;
	private array $stateCache = [];
	private ?bool $isOpenLineActivity = null;
	private bool $isOpenLineActivityLoaded = false;

	public function __construct(private readonly int $activityId, private readonly ItemIdentifier $identifier) {}

	// region FullScenario
	public function isLaunchOperationsPending(): bool
	{
		if (!$this->isValidParams())
		{
			return false;
		}

		return $this->getTranscriptionResult()?->isPending()
			|| $this->getSummarizeResult()?->isPending()
			|| $this->getFillResult()?->isPending()
			|| $this->getCallScoringResult()?->isPending()
		;
	}

	public function isLaunchOperationsSuccess(bool $checkBindings = true): bool
	{
		if (!$this->isValidParams())
		{
			return false;
		}

		if ($checkBindings)
		{
			$bindings = $this->fetchEntityBindings();
			foreach ($bindings as $binding)
			{
				$bIdentifier = new ItemIdentifier($binding['OWNER_TYPE_ID'], $binding['OWNER_ID']);
				if ((new self($this->activityId, $bIdentifier))->isLaunchOperationsSuccess(false)
				)
				{
					return true;
				}
			}
		}

		return $this->getTranscriptionResult()?->isSuccess()
			&& $this->getSummarizeResult()?->isSuccess()
			&& $this->getFillResult()?->isSuccess()
			&& $this->getCallScoringResult()?->isSuccess()
		;
	}
	// endregion

	// region FillFieldsScenario
	public function isFillFieldsScenarioPending(): bool
	{
		if (!$this->isValidParams())
		{
			return false;
		}

		$fillResult = $this->getFreshFillResult();
		if (
			$this->getSummarizeResult()?->isPending()
			|| $fillResult?->isPending()
		)
		{
			return true;
		}

		return $this->isFillFieldsScenario()
			&& (
				$this->getTranscriptionResult()?->isPending()
				|| $this->getSummarizeResult()?->isPending()
				|| $fillResult?->isPending()
			)
		;
	}

	public function isFillFieldsScenarioSuccess(): bool
	{
		if (!$this->isValidParams())
		{
			return false;
		}

		$fillResult = $this->getFreshFillResult();
		if ($fillResult?->isPending())
		{
			return false;
		}

		if ($fillResult?->isSuccess())
		{
			return true;
		}

		return (
			$this->isLaunchOperationsSuccess()
			|| $fillResult?->isSuccess()
		);
	}

	public function isFillFieldsScenarioErrorsLimitExceeded(): bool
	{
		if (!$this->isValidParams())
		{
			return true;
		}

		$fillResult = $this->getFreshFillResult();
		if (
			$this->getSummarizeResult()?->isErrorsLimitExceeded()
			|| $fillResult?->isErrorsLimitExceeded()
		)
		{
			return true;
		}

		return (
			$this->isFillFieldsScenario()
			&& (
				$this->getTranscriptionResult()?->isErrorsLimitExceeded()
				|| $this->getSummarizeResult()?->isErrorsLimitExceeded()
				|| $fillResult?->isErrorsLimitExceeded()
			)
		);
	}
	// endregion

	// region ScoreCallScenario
	public function isCallScoringScenarioPending(): bool
	{
		if (!$this->isValidParams())
		{
			return false;
		}

		if ($this->getCallScoringResult()?->isPending())
		{
			return true;
		}

		return $this->isCallScoringScenario()
			&& (
				$this->getTranscriptionResult()?->isPending()
				|| $this->getCallScoringResult()?->isPending()
			)
		;
	}

	public function isCallScoringScenarioSuccess(): bool
	{
		if (!$this->isValidParams())
		{
			return false;
		}

		if ($this->getCallScoringResult()?->isPending())
		{
			return false;
		}

		if ($this->getCallScoringResult()?->isSuccess())
		{
			return true;
		}

		return (
			$this->isLaunchOperationsSuccess()
			|| $this->getCallScoringResult()?->isSuccess()
		);
	}

	public function isCallScoringScenarioErrorsLimitExceeded(): bool
	{
		if (!$this->isValidParams())
		{
			return true;
		}

		if ($this->getCallScoringResult()?->isErrorsLimitExceeded())
		{
			return true;
		}

		return $this->isCallScoringScenario()
			&& (
				$this->getTranscriptionResult()?->isErrorsLimitExceeded()
				|| $this->getCallScoringResult()?->isErrorsLimitExceeded()
			)
		;
	}
	// endregion

	// region FullCallScenario
	public function isFullCallScenarioPending(): bool
	{
		return $this->getCachedState(__FUNCTION__, function() {
			if (!$this->isValidParams())
			{
				return false;
			}

			return $this->hasPendingResult($this->getFullCallScenarioResults());
		});
	}

	public function isFullCallScenarioSuccess(bool $checkBindings = true): bool
	{
		return $this->getCachedState(__FUNCTION__ . ':' . (int)$checkBindings, function() use ($checkBindings) {
			if (!$this->isValidParams())
			{
				return false;
			}

			if ($checkBindings)
			{
				$bindings = $this->fetchEntityBindings();
				foreach ($bindings as $binding)
				{
					$bIdentifier = new ItemIdentifier($binding['OWNER_TYPE_ID'], $binding['OWNER_ID']);
					if ((new self($this->activityId, $bIdentifier))->isFullCallScenarioSuccess(false))
					{
						return true;
					}
				}
			}

			return $this->hasSuccessfulResultsOnly($this->getFullCallScenarioResults());
		});
	}

	public function isFullCallScenarioErrorsLimitExceeded(): bool
	{
		return $this->getCachedState(__FUNCTION__, function() {
			if (!$this->isValidParams())
			{
				return true;
			}

			return $this->hasErrorsLimitExceededResult($this->getFullCallScenarioResults());
		});
	}
	// endregion

	// region FullChatScenario
	public function isFullChatScenarioPending(): bool
	{
		return $this->getCachedState(__FUNCTION__, function() {
			if (!$this->isValidParams())
			{
				return false;
			}

			return $this->hasPendingResult($this->getFullChatScenarioResults());
		});
	}

	public function isFullChatScenarioSuccess(bool $checkBindings = true): bool
	{
		return $this->getCachedState(__FUNCTION__ . ':' . (int)$checkBindings, function() use ($checkBindings) {
			if (!$this->isValidParams())
			{
				return false;
			}

			if ($checkBindings)
			{
				$bindings = $this->fetchEntityBindings();
				foreach ($bindings as $binding)
				{
					$bIdentifier = new ItemIdentifier($binding['OWNER_TYPE_ID'], $binding['OWNER_ID']);
					if ((new self($this->activityId, $bIdentifier))->isFullChatScenarioSuccess(false))
					{
						return true;
					}
				}
			}

			return $this->hasSuccessfulResultsOnly($this->getFullChatScenarioResults());
		});
	}

	public function isFullChatScenarioErrorsLimitExceeded(): bool
	{
		return $this->getCachedState(__FUNCTION__, function() {
			if (!$this->isValidParams())
			{
				return true;
			}

			return $this->hasErrorsLimitExceededResult($this->getFullChatScenarioResults());
		});
	}
	// endregion

	// region AnalyzeCommunicationScenario
	public function isAnalyzeCommunicationScenarioPending(): bool
	{
		if (!$this->isValidParams())
		{
			return false;
		}

		return $this->getFreshAnalyzeCommunicationResult()?->isPending() ?? false;
	}

	public function isAnalyzeCommunicationScenarioSuccess(): bool
	{
		if (!$this->isValidParams())
		{
			return false;
		}

		$result = $this->getFreshAnalyzeCommunicationResult();
		if ($result?->isPending())
		{
			return false;
		}

		return $result?->isSuccess() ?? false;
	}

	public function isAnalyzeCommunicationScenarioErrorsLimitExceeded(): bool
	{
		if (!$this->isValidParams())
		{
			return true;
		}

		return $this->getFreshAnalyzeCommunicationResult()?->isErrorsLimitExceeded() ?? false;
	}
	// endregion

	// region LazyLoadingGetters
	protected function getTranscriptionResult(): ?Result
	{
		if (!$this->transcriptionResultLoaded)
		{
			$this->transcriptionResult = JobRepository::getInstance()
				->getTranscribeCallRecordingResultByActivity($this->activityId);
			$this->transcriptionResultLoaded = true;
		}

		return $this->transcriptionResult;
	}

	protected function getSummarizeResult(): ?Result
	{
		if (!$this->summarizeResultLoaded)
		{
			$this->summarizeResult = JobRepository::getInstance()
				->getSummarizeCallTranscriptionResultByActivity($this->activityId);
			$this->summarizeResultLoaded = true;
		}

		return $this->summarizeResult;
	}

	protected function getCallScoringResult(): ?Result
	{
		if (!$this->callScoringResultLoaded)
		{
			$this->callScoringResult = JobRepository::getInstance()
				->getCallScoringResult($this->activityId);
			$this->callScoringResultLoaded = true;
		}

		return $this->callScoringResult;
	}

	protected function getFillResult(): ?Result
	{
		if (!$this->fillResultLoaded)
		{
			if ($this->isValidParams())
			{
				$this->fillResult = JobRepository::getInstance()->getFillItemFieldsFromCallTranscriptionResult(
					$this->identifier,
					$this->activityId,
					$this->getSummarizeResult()?->getJobId(),
				);
			}
			$this->fillResultLoaded = true;
		}

		return $this->fillResult;
	}

	protected function getAnalyzeCommunicationResult(): ?Result
	{
		if (!$this->analyzeCommunicationResultLoaded)
		{
			$this->analyzeCommunicationResult = JobRepository::getInstance()
				->getAnalyzeCommunicationResult($this->activityId);
			$this->analyzeCommunicationResultLoaded = true;
		}

		return $this->analyzeCommunicationResult;
	}
	// endregion

	// region Utils
	/**
	 * FULL uses summarize as an explicit step, and fill depends on its output.
	 * When fill is enabled, we still track summarize to avoid treating the chain as completed too early.
	 *
	 * @return array<int, Result|null>
	 */
	protected function getFullCallScenarioResults(): array
	{
		$results = [
			$this->getTranscriptionResult(),
		];

		if ($this->shouldTrackSummarizeInFullScenario())
		{
			$results[] = $this->getSummarizeResult();
		}

		if ($this->shouldTrackFillInFullScenario())
		{
			$results[] = $this->getFreshFillResult();
		}

		if ($this->shouldTrackCallScoringInFullCallScenario())
		{
			$results[] = $this->getCallScoringResult();
		}

		if ($this->shouldTrackAnalyzeCommunicationInFullScenario())
		{
			$results[] = $this->getAnalyzeCommunicationResult();
		}

		return $results;
	}

	/**
	 * @return array<int, Result|null>
	 */
	protected function getFullChatScenarioResults(): array
	{
		$results = [];

		if ($this->shouldTrackSummarizeInFullScenario())
		{
			$results[] = $this->getSummarizeResult();
		}

		if ($this->shouldTrackFillInFullScenario())
		{
			$results[] = $this->getFreshFillResult();
		}

		if ($this->shouldTrackAnalyzeCommunicationInFullScenario())
		{
			$results[] = $this->getFreshAnalyzeCommunicationResult();
		}

		return $results;
	}

	protected function isSummarizeEnabled(): bool
	{
		return AIManager::isEnabledInGlobalSettings(GlobalSetting::Summarize);
	}

	protected function isFillFieldsEnabled(): bool
	{
		return AIManager::isEnabledInGlobalSettings(GlobalSetting::FillItemFromCall);
	}

	protected function isCallScoringEnabled(): bool
	{
		return AIManager::isEnabledInGlobalSettings(GlobalSetting::CallAssessment);
	}

	protected function isAnalyzeCommunicationEnabled(): bool
	{
		return AIManager::isEnabledInGlobalSettings(GlobalSetting::AnalyzeCommunication);
	}

	private function isFillFieldsScenario(): bool
	{
		return $this->getTranscriptionResult()?->getNextTypeId() === null
			|| $this->getTranscriptionResult()?->getNextTypeId() === SummarizeCallTranscription::TYPE_ID
		;
	}

	private function isCallScoringScenario(): bool
	{
		return $this->getTranscriptionResult()?->getNextTypeId() === ScoreCall::TYPE_ID;
	}

	private function shouldTrackSummarizeInFullScenario(): bool
	{
		return $this->isSummarizeEnabled() || $this->isFillFieldsEnabled();
	}

	private function shouldTrackFillInFullScenario(): bool
	{
		return $this->isFillFieldsEnabled();
	}

	private function shouldTrackCallScoringInFullCallScenario(): bool
	{
		return $this->isCallScoringEnabled();
	}

	private function shouldTrackAnalyzeCommunicationInFullScenario(): bool
	{
		return $this->isAnalyzeCommunicationEnabled();
	}

	private function getFreshAnalyzeCommunicationResult(): ?Result
	{
		$result = $this->getAnalyzeCommunicationResult();
		if (!$result?->isSuccess() || !$this->isOpenLineActivity())
		{
			return $result;
		}

		$summarizeResult = $this->getSummarizeResult();
		if (!$summarizeResult?->isSuccess())
		{
			return $result;
		}

		$resultJobId = $result->getJobId() ?? 0;
		$summarizeJobId = $summarizeResult->getJobId() ?? 0;
		if ($resultJobId > 0 && $summarizeJobId > $resultJobId)
		{
			return null;
		}

		return $result;
	}

	private function getFreshFillResult(): ?Result
	{
		$result = $this->getFillResult();
		if (!$result?->isSuccess())
		{
			return $result;
		}

		$summarizeResult = $this->getSummarizeResult();
		if (!$summarizeResult?->isSuccess())
		{
			return $result;
		}

		$resultParentJobId = $result->getParentJobId() ?? 0;
		$summarizeJobId = $summarizeResult->getJobId() ?? 0;

		if (
			$resultParentJobId > 0
			&& $summarizeJobId > 0
			&& $resultParentJobId !== $summarizeJobId
		)
		{
			return null;
		}

		return $result;
	}

	protected function isOpenLineActivity(): bool
	{
		if (!$this->isOpenLineActivityLoaded)
		{
			$activity = CCrmActivity::GetByID($this->activityId, false);
			$this->isOpenLineActivity = ($activity['PROVIDER_ID'] ?? null) === OpenLine::getId();
			$this->isOpenLineActivityLoaded = true;
		}

		return $this->isOpenLineActivity ?? false;
	}

	/**
	 * @param array<int, Result|null> $results
	 */
	private function hasPendingResult(array $results): bool
	{
		foreach ($results as $result)
		{
			if ($result?->isPending())
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<int, Result|null> $results
	 */
	private function hasSuccessfulResultsOnly(array $results): bool
	{
		if (empty($results))
		{
			return false;
		}

		foreach ($results as $result)
		{
			if (
				$result === null
				|| $result->isPending()
				|| $result->isErrorsLimitExceeded()
				|| !$result->isSuccess()
			)
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * @param array<int, Result|null> $results
	 */
	private function hasErrorsLimitExceededResult(array $results): bool
	{
		foreach ($results as $result)
		{
			if ($result?->isErrorsLimitExceeded())
			{
				return true;
			}
		}

		return false;
	}

	private function isValidParams(): bool
	{
		return $this->activityId > 0
			&& in_array(
				$this->identifier->getEntityTypeId(),
				AIManager::SUPPORTED_ENTITY_TYPE_IDS,
				true
			)
		;
	}

	private function fetchEntityBindings(): array
	{
		if (!$this->entityBindingsLoaded)
		{
			$bindings = CCrmActivity::GetBindings($this->activityId);
			$bindings = is_array($bindings) ? $bindings : [];

			$this->entityBindings = array_filter(
				$bindings,
				fn(array $row) => in_array(
					(int)$row['OWNER_TYPE_ID'],
					AIManager::SUPPORTED_ENTITY_TYPE_IDS,
					true
				) && $this->identifier->getEntityTypeId() !== (int)$row['OWNER_TYPE_ID']
			);
			$this->entityBindingsLoaded = true;
		}

		return $this->entityBindings;
	}

	private function getCachedState(string $cacheKey, callable $callback): bool
	{
		if (!array_key_exists($cacheKey, $this->stateCache))
		{
			$this->stateCache[$cacheKey] = $callback();
		}

		return $this->stateCache[$cacheKey];
	}
	// endregion
}
