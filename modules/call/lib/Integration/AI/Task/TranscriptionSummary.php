<?php

namespace Bitrix\Call\Integration\AI\Task;

use Bitrix\Main\Result;
use Bitrix\Call\Integration\AI\Outcome;
use Bitrix\Call\Integration\AI\SenseType;
use Bitrix\Call\Integration\AI\CallAIError;
use Bitrix\Call\Integration\AI\MentionService;
use Bitrix\Call\Integration\AI\CallAISettings;
use Bitrix\Call\Integration\AI\Outcome\Transcription;

class TranscriptionSummary extends AITask
{
	public const PROMPT_ID = 'meeting_summarization';

	/**
	 * Outcome version for compatibility with previous variant.
	 * @return int
	 */
	public function getVersion(): int
	{
		return 1;
	}

	/**
	 * Provides payload for AI task.
	 * @param Outcome $payload
	 * @return self
	 */
	public function setPayload($payload): AITask
	{
		if ($payload instanceof Outcome)
		{
			$this->task
				->setType($this->getAISenseType())
				->setCallId($payload->getCallId())
				->setOutcome($payload)
				->setOutcomeId($payload->getId())
			;
			if ($payload->getLanguageId())
			{
				$this->task->setLanguageId($payload->getLanguageId());
			}
		}

		return $this;
	}

	/**
	 * @return Result<\Bitrix\AI\Payload\IPayload>
	 */
	public function getAIPayload(): Result
	{
		$result = new Result;

		$outcome = $this->task->getOutcome() ?? $this->task->fillOutcome();
		if (!$outcome)
		{
			return $result->addError(new CallAIError(CallAIError::AI_EMPTY_PAYLOAD_ERROR));// Empty outcome content
		}

		$call = \Bitrix\Im\Call\Registry::getCallWithId($outcome->getCallId());
		if (!$call)
		{
			return $result->addError(new CallAIError(CallAIError::AI_EMPTY_PAYLOAD_ERROR));// Empty outcome content
		}

		$transcription = $outcome->getSenseContent();
		if (
			!($transcription instanceof Transcription)
			|| $transcription->isEmpty
		)
		{
			return $result->addError(new CallAIError(CallAIError::AI_EMPTY_PAYLOAD_ERROR));// Empty outcome content
		}

		if (
			!empty($transcription->language)
			&& $this->task->getLanguageId() !== $transcription->language
		)
		{
			$this->task->setLanguageId($transcription->language);
		}

		$payload = new \Bitrix\AI\Payload\Prompt(static::PROMPT_ID);
		$payload->setMarkers([
			'transcripts' => $transcription->prepareTextForAi(),
		]);

		return $result->setData(['payload' => $payload]);
	}

	public function getAIEngineCategory(): string
	{
		return \Bitrix\AI\Engine\Enum\Category::TEXT->value;
	}

	public function getAIEngineCode(): string
	{
		$engineItem = (new \Bitrix\AI\Tuning\Manager)->getItem(CallAISettings::TRANSCRIPTION_OVERVIEW_ENGINE);
		if (isset($engineItem))
		{
			$code = $engineItem->getValue();
		}
		elseif (\Bitrix\Call\Integration\AI\CallAISettings::isB24Mode())
		{
			$code = 'ChatGPT'; /** @see \Bitrix\Bitrix24\Integration\AI\Engine\ChatGPT::ENGINE_CODE */
		}
		else
		{
			$code = 'ItSolution'; /** @see \Bitrix\AI\Engine\Cloud\ItSolution::ENGINE_CODE */
		}

		return $code;
	}

	public function getAISenseType(): string
	{
		return SenseType::SUMMARY->value;
	}

	public function filterResult(array $jsonData): array
	{
		$mentionService = MentionService::getInstance();
		$mentionService->loadMentionsForCall($this->getCallId());

		foreach ($jsonData['call_summary'] as &$row)
		{
			$row['title'] = $mentionService->replaceAiMentions($row['title']);
			$row['summary'] = $mentionService->replaceAiMentions($row['summary']);
		}

		return $jsonData;
	}

	/**
	 * Allows outputting the chat error message then a task failed.
	 * @return bool
	 */
	public function allowNotifyTaskFailed(): bool
	{
		return true;
	}
}