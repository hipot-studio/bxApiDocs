<?php

declare(strict_types=1);

namespace Bitrix\Crm\Service\Timeline\Item;

use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\JobRepository;
use Bitrix\Crm\Integration\AI\Operation\FillItemFieldsFromCallTranscription;
use Bitrix\Crm\Integration\AI\Operation\FillRepeatSaleTips;
use Bitrix\Crm\Integration\AI\Operation\Orchestrator;
use Bitrix\Crm\Integration\AI\Operation\ScoreCall;
use Bitrix\Crm\Integration\AI\Operation\SummarizeCallTranscription;
use Bitrix\Crm\Integration\AI\Operation\TranscribeCallRecording;
use Bitrix\Crm\Integration\AI\Result;
use Bitrix\Crm\Service\Timeline\Context;
use Bitrix\Main\Application;
use Exception;
use InvalidArgumentException;

final class AIActivityService
{
	private const TRANSCRIPTION_LIMIT = 10;

	private ?bool $isAIScope = null;
	private ?bool $isFieldsFillingWrong = null;
	private ?bool $isItemHashValid = null;

	public function __construct(private readonly int $activityId, private readonly Context $context)
	{
		if ($activityId <= 0)
		{
			throw new InvalidArgumentException('Activity ID must be greater than zero');
		}
	}

	public function isAIScope(): bool
	{
		if ($this->isAIScope === null)
		{
			$this->isAIScope = AIManager::isAiCallProcessingEnabled()
				&& in_array(
					$this->context->getEntityTypeId(),
					AIManager::SUPPORTED_ENTITY_TYPE_IDS,
					true
				)
			;
		}

		return $this->isAIScope;
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
			SummarizeCallTranscription::TYPE_ID => $repo->getSummarizeCallTranscriptionResultByActivity($this->activityId, $jobId),
			FillItemFieldsFromCallTranscription::TYPE_ID => $repo->getFillItemFieldsFromCallTranscriptionResult($this->context->getIdentifier(), $this->activityId),
			ScoreCall::TYPE_ID => $repo->getCallScoringResult($this->activityId, $jobId),
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
			$this->activityId,
			['ID', 'FINISHED_TIME'],
			self::TRANSCRIPTION_LIMIT,
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
			$possibleHash = (new Orchestrator())->findPossibleFillFieldsTarget($this->activityId)?->getHash();
			$currentHash = $this->context->getIdentifier()->getHash();

			$this->isItemHashValid = $possibleHash === $currentHash;
		}

		return $this->isItemHashValid;
	}

	private function isValidOperationType(int $type): bool
	{
		return in_array($type, AIManager::getAllOperationTypes(), true);
	}
}
