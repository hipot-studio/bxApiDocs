<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\Pipeline;

use Bitrix\Crm\Activity\Provider\OpenLine;
use Bitrix\Crm\Integration\AI\JobRepository;
use Bitrix\Crm\Integration\AI\Operation\AnalyzeCommunication;
use Bitrix\Crm\Integration\AI\Operation\ExtractScoringCriteria;
use Bitrix\Crm\Integration\AI\Operation\FillItemFieldsFromCallTranscription;
use Bitrix\Crm\Integration\AI\Operation\FillRepeatSaleTips;
use Bitrix\Crm\Integration\AI\Operation\ScoreCall;
use Bitrix\Crm\Integration\AI\Operation\ScreeningRepeatSaleItem;
use Bitrix\Crm\Integration\AI\Operation\SummarizeCallTranscription;
use Bitrix\Crm\Integration\AI\Operation\TranscribeCallRecording;
use Bitrix\Crm\Integration\AI\Result;

final class StepResultResolver
{
	private readonly JobRepository $jobRepository;
	private readonly TargetResolver $targetResolver;

	public function __construct(?JobRepository $jobRepository = null, ?TargetResolver $targetResolver = null)
	{
		$this->jobRepository = $jobRepository ?? JobRepository::getInstance();
		$this->targetResolver = $targetResolver ?? new TargetResolver();
	}

	public function resolve(string $operationClass, StepContext $context): ?Result
	{
		$activityId = $context->getActivityId();

		return match ($operationClass)
		{
			TranscribeCallRecording::class
				=> $this->jobRepository->getTranscribeCallRecordingResultByActivity($activityId),
			SummarizeCallTranscription::class
				=> $this->jobRepository->getSummarizeCallTranscriptionResultByActivity($activityId),
			FillItemFieldsFromCallTranscription::class
				=> $this->resolveFillResult($context),
			ScoreCall::class
				=> $this->jobRepository->getCallScoringResult($activityId),
			AnalyzeCommunication::class
				=> $this->resolveAnalyzeCommunication($context),
			ExtractScoringCriteria::class
				=> $this->resolveExtractScoringCriteriaResult($context),
			FillRepeatSaleTips::class
				=> $this->jobRepository->getFillRepeatSaleTipsByActivity($activityId),
			ScreeningRepeatSaleItem::class
				=> $this->resolveScreeningRepeatSaleItemResult($context),
			default => null,
		};
	}

	private function resolveFillResult(StepContext $context): ?Result
	{
		$target = $this->targetResolver->findTarget($context->getActivityId());
		if (!$target)
		{
			return null;
		}

		$summarizeResult = $this->jobRepository->getSummarizeCallTranscriptionResultByActivity($context->getActivityId());

		return $this->jobRepository->getFillItemFieldsFromCallTranscriptionResult(
			$target,
			$context->getActivityId(),
			$summarizeResult?->getJobId(),
		);
	}

	private function resolveAnalyzeCommunication(StepContext $context): ?Result
	{
		$activityId = $context->getActivityId();
		$result = $this->jobRepository->getAnalyzeCommunicationResult($activityId);
		if ($context->getActivityProvider() !== OpenLine::getId())
		{
			return $result;
		}

		$summarizeResult = $this->jobRepository->getSummarizeCallTranscriptionResultByActivity($activityId);
		if ($this->isOpenLineAnalyzeResultOutdatedBySummarize($result, $summarizeResult))
		{
			return null;
		}

		return $result;
	}

	private function resolveScreeningRepeatSaleItemResult(StepContext $context): ?Result
	{
		$target = $this->targetResolver->findTarget($context->getActivityId());
		if (!$target)
		{
			return null;
		}

		return $this->jobRepository->isJobOfSameTypeAlreadyExistsForTarget(
			$target,
			ScreeningRepeatSaleItem::TYPE_ID,
		) ? new Result(ScreeningRepeatSaleItem::TYPE_ID) : null;
	}

	private function resolveExtractScoringCriteriaResult(StepContext $context): ?Result
	{
		// ExtractScoringCriteria uses the activity ID as the entity ID
		return $this->jobRepository->getExtractScoringCriteriaResultById($context->getActivityId());
	}

	private function isOpenLineAnalyzeResultOutdatedBySummarize(?Result $result, ?Result $summarizeResult): bool
	{
		if (!$result?->isSuccess() || !$summarizeResult?->isSuccess())
		{
			return false;
		}

		$resultJobId = $result->getJobId() ?? 0;
		$summarizeJobId = $summarizeResult->getJobId() ?? 0;

		return $resultJobId > 0
			&& $summarizeJobId > 0
			&& $summarizeJobId > $resultJobId
		;
	}
}
