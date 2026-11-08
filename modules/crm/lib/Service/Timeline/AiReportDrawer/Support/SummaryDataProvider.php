<?php

namespace Bitrix\Crm\Service\Timeline\AiReportDrawer\Support;

use Bitrix\Crm\Integration\AI\AiMessageProvider;
use Bitrix\Crm\Integration\AI\ErrorCode as AIErrorCode;
use Bitrix\Crm\Integration\AI\JobRepository;
use Bitrix\Crm\Integration\AI\Model\QueueTable;
use Bitrix\Crm\Integration\AI\Operation\SummarizeCallTranscription;
use Bitrix\Crm\Integration\AI\Operation\TranscribeCallRecording;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class SummaryDataProvider
{
	public function __construct(private readonly JobRepository $jobRepository)
	{
	}

	public function loadTranscript(int $activityId): Result
	{
		$result = new Result();
		$transcriptResult = $this->jobRepository->getTranscribeCallRecordingResultByActivity($activityId);
		if ($transcriptResult === null)
		{
			return $result->addError(new Error('Call transcription not found'));
		}

		if (!$transcriptResult->isSuccess())
		{
			return $result->addErrors($transcriptResult->getErrors());
		}

		$payload = $transcriptResult->getPayload();
		if ($payload === null)
		{
			return $result->addError(AIErrorCode::getPayloadNotFoundError());
		}

		return $result->setData([
			'payload' => $payload->toArray(),
			'createdAt' => $this->getJobFinishedTimestamp(
				$activityId,
				TranscribeCallRecording::TYPE_ID,
				$transcriptResult->getJobId(),
			),
		]);
	}

	public function loadSummary(
		int $activityId,
		?int $jobId = null,
		?int $ownerTypeId = null,
		?int $ownerId = null,
	): Result
	{
		$result = new Result();
		$summaryResult = $this->jobRepository->getSummarizeCallTranscriptionResultByActivity(
			$activityId,
			$jobId,
			$ownerTypeId,
			$ownerId,
		);
		if ($summaryResult === null)
		{
			return $result->addError(new Error('Summary result is not found'));
		}

		if (!$summaryResult->isSuccess())
		{
			return $result->addErrors($summaryResult->getErrors());
		}

		$payload = $summaryResult->getPayload();
		if ($payload === null)
		{
			return $result->addError(AIErrorCode::getPayloadNotFoundError());
		}

		return $result->setData([
			'payload' => $payload->toArray(),
			'createdAt' => $this->getJobFinishedTimestamp(
				$activityId,
				SummarizeCallTranscription::TYPE_ID,
				$summaryResult->getJobId(),
			),
		]);
	}

	public function getSummaryAiLanguage(
		int $activityId,
		?int $jobId = null,
		?int $ownerTypeId = null,
		?int $ownerId = null,
	): ?string
	{
		$summarizeResult = $this->jobRepository->getSummarizeCallTranscriptionResultByActivity(
			$activityId,
			$jobId,
			$ownerTypeId,
			$ownerId,
		);
		if ($summarizeResult === null)
		{
			return null;
		}

		$languageId = $summarizeResult->getLanguageId();
		$language = AiMessageProvider::getLanguageTitleByLanguageId($languageId);

		return AiMessageProvider::getJobLanguageMessageData($language)['html'] ?? null;
	}

	public function getTranscriptAiLanguage(int $activityId): ?string
	{
		$transcriptResult = $this->jobRepository->getTranscribeCallRecordingResultByActivity($activityId);
		if ($transcriptResult === null)
		{
			return null;
		}

		$languageId = $transcriptResult->getLanguageId();
		$language = AiMessageProvider::getLanguageTitleByLanguageId($languageId);

		return AiMessageProvider::getJobLanguageMessageData($language)['html'] ?? null;
	}

	public function loadSummaryHistory(
		int $activityId,
		?int $ownerTypeId = null,
		?int $ownerId = null,
	): array
	{
		$result = $this->jobRepository->getSummarizeCallTranscriptionHistoryByActivity(
			$activityId,
			ownerTypeId: $ownerTypeId,
			ownerId: $ownerId,
		);
		foreach ($result as $index => $summary)
		{
			$languageId = $summary['languageId'] ?? null;
			if (!is_string($languageId) || $languageId === '')
			{
				$result[$index]['aiLanguage'] = null;
				unset($result[$index]['languageId']);
				continue;
			}

			$language = AiMessageProvider::getLanguageTitleByLanguageId($languageId);
			$result[$index]['aiLanguage'] = AiMessageProvider::getJobLanguageMessageData($language)['html'] ?? null;
			unset($result[$index]['languageId']);
		}

		return $result;
	}

	private function getJobFinishedTimestamp(int $activityId, int $typeId, ?int $jobId = null): ?int
	{
		if ($activityId <= 0)
		{
			return null;
		}

		$query = QueueTable::query()
			->setSelect(['ID', 'FINISHED_TIME'])
			->where('ENTITY_TYPE_ID', \CCrmOwnerType::Activity)
			->where('ENTITY_ID', $activityId)
			->where('TYPE_ID', $typeId)
			->addOrder('ID', 'DESC')
		;

		if ($jobId !== null)
		{
			$query->where('ID', $jobId);
		}

		$job = $query
			->setLimit(1)
			->fetchObject()
		;

		return $job?->getFinishedTime()?->getTimestamp();
	}
}
