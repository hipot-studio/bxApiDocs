<?php

declare(strict_types=1);

namespace Bitrix\Crm\Service\Timeline\Item;

use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\JobRepository;
use Bitrix\Crm\Integration\AI\Operation\FillItemFieldsFromCallTranscription;
use Bitrix\Crm\Integration\AI\Operation\FillRepeatSaleTips;
use Bitrix\Crm\Integration\AI\Operation\Scenario;
use Bitrix\Crm\Integration\AI\Operation\ScoreCall;
use Bitrix\Crm\Integration\AI\Operation\ScoreCallV2;
use Bitrix\Crm\Integration\AI\Operation\SummarizeCallTranscription;
use Bitrix\Crm\Integration\AI\Operation\TranscribeCallRecording;
use Bitrix\Crm\Integration\AI\Result;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Timeline\Context;
use Bitrix\Main\Application;
use CCrmActivity;
use Exception;
use InvalidArgumentException;

class AIActivityService
{
	private const TRANSCRIPTION_LIMIT = 10;

	private ?bool $isAIScope = null;
	private ?bool $isFieldsFillingWrong = null;
	private ?bool $isItemHashValid = null;
	private bool $isEmailScope = false;

	private int $summarizeActivityId;
	private int $resultActivityId;

	public function __construct(private readonly int $activityId, private readonly Context $context)
	{
		if ($activityId <= 0)
		{
			throw new InvalidArgumentException('Activity ID must be greater than zero');
		}

		$this->summarizeActivityId = $activityId;
		$this->resultActivityId = $activityId;
	}

	public function withEmailScope(): self
	{
		$clone = clone $this;
		$clone->isEmailScope = true;
		$clone->isItemHashValid = null;

		return $clone;
	}

	public function withSummarizeActivityId(int $summarizeActivityId): self
	{
		$clone = clone $this;
		$clone->summarizeActivityId = $summarizeActivityId > 0 ? $summarizeActivityId : $this->activityId;

		return $clone;
	}

	public function withResultActivityId(int $resultActivityId): self
	{
		$clone = clone $this;
		$clone->resultActivityId = $resultActivityId > 0 ? $resultActivityId : $this->activityId;

		return $clone;
	}

	public function isAIScope(): bool
	{
		if ($this->isAIScope === null)
		{
			$this->isAIScope = AIManager::isAiCallProcessingEnabled()
				&& AIManager::isEntityTypeSupported($this->context->getEntityTypeId())
			;
		}

		return $this->isAIScope;
	}

	public function isScenarioVisible(string $scenario): bool
	{
		if (!Scenario::isEnabledScenario($scenario))
		{
			return false;
		}

		return $scenario !== Scenario::FILL_FIELDS_SCENARIO
			|| AIManager::isFillFieldsEnabledForEntityType($this->context->getEntityTypeId())
		;
	}

	public function getAIJobResult(int $operationType, ?int $jobId = null): ?Result
	{
		if (!$this->isValidOperationType($operationType))
		{
			return null;
		}

		$repo = JobRepository::getInstance();

		return match ($operationType)
		{
			TranscribeCallRecording::TYPE_ID => $repo->getTranscribeCallRecordingResultByActivity($this->activityId),
			SummarizeCallTranscription::TYPE_ID => $repo->getSummarizeCallTranscriptionResultByActivity(
				$this->summarizeActivityId,
				$jobId,
				$this->isEmailScope ? $this->context->getEntityTypeId() : null,
				$this->isEmailScope ? $this->context->getEntityId() : null,
			),
			FillItemFieldsFromCallTranscription::TYPE_ID => $repo->getFillItemFieldsFromCallTranscriptionResult($this->context->getIdentifier(), $this->resultActivityId),
			ScoreCall::TYPE_ID, ScoreCallV2::TYPE_ID => $repo->getCallScoringResult($this->activityId, $jobId),
			FillRepeatSaleTips::TYPE_ID => $repo->getFillRepeatSaleTipsByActivity($this->activityId),
			default => null,
		};
	}

	public function getAILanguage(int $operationType, ?int $jobId = null): string
	{
		$jobResult = $this->getAIJobResult($operationType, $jobId);
		if ($jobResult === null)
		{
			return '';
		}

		$languageId = $jobResult->getLanguageId() ?? Application::getInstance()->getContext()->getLanguage();

		return AIManager::getAvailableLanguageList()[$languageId] ?? '';
	}

	/**
	 * @return array<int, int>
	 *
	 * @throws Exception
	 */
	public function getSummarizeTranscriptionList(): array
	{
		$rawData = JobRepository::getInstance()->getSummarizeTranscriptionData(
			$this->summarizeActivityId,
			['ID', 'FINISHED_TIME'],
			self::TRANSCRIPTION_LIMIT,
			$this->isEmailScope ? $this->context->getEntityTypeId() : null,
			$this->isEmailScope ? $this->context->getEntityId() : null,
		);

		$result = [];
		foreach ($rawData as $item)
		{
			$result[$item->getId()] = $item->getFinishedTime()?->getTimestamp();
		}

		return $result;
	}

	public function isFieldsFillingWrong(): bool
	{
		if ($this->isFieldsFillingWrong === null)
		{
			$jobResult = $this->getAIJobResult(FillItemFieldsFromCallTranscription::TYPE_ID);
			$this->isFieldsFillingWrong = $jobResult && $jobResult->getOperationStatus() === Result::OPERATION_STATUS_CONFLICT;
		}

		return $this->isFieldsFillingWrong;
	}

	public function isItemHashValid(): bool
	{
		if ($this->isItemHashValid === null)
		{
			$identifier = $this->context->getIdentifier();

			// The AI "fill fields" actions belong on the card of every entity that (a) is a supported
			// (Factory-based) target type and (b) is actually one of the activity's own bindings.
			// Previously the current card was compared against a single priority target
			// (TargetResolver -> Deal/Lead), which hid the actions on every other linked entity
			// (e.g. a Contact bound to the same call/chat as a Deal). See fill-fields-any-entity.
			$this->isItemHashValid =
				AIManager::isEntityTypeSupported($identifier->getEntityTypeId())
				&& $this->isEntityBoundToActivity($identifier)
			;
		}

		return $this->isItemHashValid;
	}

	private function isEntityBoundToActivity(ItemIdentifier $identifier): bool
	{
		foreach ($this->getActivityBindings() as $binding)
		{
			if (
				(int)($binding['OWNER_TYPE_ID'] ?? 0) === $identifier->getEntityTypeId()
				&& (int)($binding['OWNER_ID'] ?? 0) === $identifier->getEntityId()
			)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	protected function getActivityBindings(): array
	{
		$bindings = CCrmActivity::GetBindings($this->activityId);

		return is_array($bindings) ? $bindings : [];
	}

	private function isValidOperationType(int $type): bool
	{
		return in_array($type, AIManager::getAllOperationTypes(), true);
	}
}
