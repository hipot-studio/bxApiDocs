<?php

namespace Bitrix\Crm\Controller\Timeline\Trait;

use Bitrix\Crm\Integration\AI\ErrorCode as AIErrorCode;
use Bitrix\Main\Error;

trait CopilotResponseLoader
{
	use ActivityLoader;

	final protected function loadTranscript(int $activityId): ?array
	{
		$transcriptResult = $this->jobRepository->getTranscribeCallRecordingResultByActivity($activityId);
		if (is_null($transcriptResult))
		{
			$this->addError(new Error('CoPilot call transcription not found'));

			return null;
		}

		if (!$transcriptResult->isSuccess())
		{
			$this->addErrors($transcriptResult->getErrors());

			return null;
		}

		$payload = $transcriptResult->getPayload();
		if (is_null($payload))
		{
			$this->addError(AIErrorCode::getPayloadNotFoundError());

			return null;
		}

		return $payload->toArray();
	}

	final protected function loadSummary(int $activityId, ?int $jobId = null): ?array
	{
		$summaryResult = $this->jobRepository->getSummarizeCallTranscriptionResultByActivity($activityId, $jobId);
		if (is_null($summaryResult))
		{
			$this->addError(new Error('CoPilot summary result is not found'));

			return null;
		}

		if (!$summaryResult->isSuccess())
		{
			$this->addErrors($summaryResult->getErrors());

			return null;
		}

		$payload = $summaryResult->getPayload();
		if (is_null($payload))
		{
			$this->addError(AIErrorCode::getPayloadNotFoundError());

			return null;
		}

		return $payload->toArray();
	}
}
