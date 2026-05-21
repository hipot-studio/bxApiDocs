<?php

namespace Bitrix\Crm\Integration\AI\Operation;

use Bitrix\Crm\Integration\AI\AIManager;
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

	private bool $transcriptionResultLoaded = false;
	private bool $callScoringResultLoaded = false;
	private bool $summarizeResultLoaded = false;
	private bool $fillResultLoaded = false;

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

		if (
			$this->getSummarizeResult()?->isPending()
			|| $this->getFillResult()?->isPending()
		)
		{
			return true;
		}

		return $this->isFillFieldsScenario()
			&& (
				$this->getTranscriptionResult()?->isPending()
				|| $this->getSummarizeResult()?->isPending()
				|| $this->getFillResult()?->isPending()
			)
		;
	}

	public function isFillFieldsScenarioSuccess(): bool
	{
		if (!$this->isValidParams())
		{
			return false;
		}

		if ($this->getFillResult()?->isPending())
		{
			return false;
		}

		if ($this->getFillResult()?->isSuccess())
		{
			return true;
		}

		return (
			$this->isLaunchOperationsSuccess()
			|| $this->getFillResult()?->isSuccess()
		);
	}

	public function isFillFieldsScenarioErrorsLimitExceeded(): bool
	{
		if (!$this->isValidParams())
		{
			return true;
		}

		if (
			$this->getSummarizeResult()?->isErrorsLimitExceeded()
			|| $this->getFillResult()?->isErrorsLimitExceeded()
		)
		{
			return true;
		}

		return (
			$this->isFillFieldsScenario()
			&& (
				$this->getTranscriptionResult()?->isErrorsLimitExceeded()
				|| $this->getSummarizeResult()?->isErrorsLimitExceeded()
				|| $this->getFillResult()?->isErrorsLimitExceeded()
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

	// region LazyLoadingGetters
	private function getTranscriptionResult(): ?Result
	{
		if (!$this->transcriptionResultLoaded)
		{
			$this->transcriptionResult = JobRepository::getInstance()
				->getTranscribeCallRecordingResultByActivity($this->activityId);
			$this->transcriptionResultLoaded = true;
		}

		return $this->transcriptionResult;
	}

	private function getSummarizeResult(): ?Result
	{
		if (!$this->summarizeResultLoaded)
		{
			$this->summarizeResult = JobRepository::getInstance()
				->getSummarizeCallTranscriptionResultByActivity($this->activityId);
			$this->summarizeResultLoaded = true;
		}

		return $this->summarizeResult;
	}

	private function getCallScoringResult(): ?Result
	{
		if (!$this->callScoringResultLoaded)
		{
			$this->callScoringResult = JobRepository::getInstance()
				->getCallScoringResult($this->activityId);
			$this->callScoringResultLoaded = true;
		}

		return $this->callScoringResult;
	}

	private function getFillResult(): ?Result
	{
		if (!$this->fillResultLoaded)
		{
			if ($this->isValidParams())
			{
				$this->fillResult = JobRepository::getInstance()->getFillItemFieldsFromCallTranscriptionResult($this->identifier, $this->activityId);
			}
			$this->fillResultLoaded = true;
		}

		return $this->fillResult;
	}
	// endregion

	// region Utils
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

	private function isValidParams(): bool
	{
		return $this->activityId >= 0
			&& in_array(
				$this->identifier->getEntityTypeId(),
				AIManager::SUPPORTED_ENTITY_TYPE_IDS,
				true
			)
		;
	}

	private function fetchEntityBindings(): array
	{
		$bindings = CCrmActivity::GetBindings($this->activityId);
		$bindings = is_array($bindings) ? $bindings : [];

		return array_filter(
			$bindings,
			fn(array $row) => in_array(
				(int)$row['OWNER_TYPE_ID'],
				AIManager::SUPPORTED_ENTITY_TYPE_IDS,
				true
			) && $this->identifier->getEntityTypeId() !== (int)$row['OWNER_TYPE_ID']
		);
	}
	// endregion
}
