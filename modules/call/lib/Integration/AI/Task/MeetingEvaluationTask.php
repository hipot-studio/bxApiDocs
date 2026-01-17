<?php

namespace Bitrix\Call\Integration\AI\Task;

use Bitrix\Main\Result;
use Bitrix\Call\Integration\AI\Outcome;
use Bitrix\Call\Integration\AI\SenseType;
use Bitrix\Call\Integration\AI\CallAIError;
use Bitrix\Call\Integration\AI\MentionService;
use Bitrix\Call\Integration\AI\CallAISettings;
use Bitrix\Call\Integration\AI\Outcome\Overview;
use Bitrix\Call\Integration\AI\Outcome\Transcription;
use Bitrix\Call\Integration\AI\Outcome\OutcomeCollection;

class MeetingEvaluationTask extends AITask
{
	public const PROMPT_ID = 'meeting_eval_by_type';

	/**
	 * Outcome version for compatibility with previous variant.
	 * @return int
	 */
	public function getVersion(): int
	{
		return 2;
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

		$callId = $outcome->getCallId();

		$outcomeCollection = OutcomeCollection::getOutcomesByCallId($callId, [SenseType::TRANSCRIBE->value]);
		$transcription = $outcomeCollection->getOutcomeByType(SenseType::TRANSCRIBE->value)?->getSenseContent();
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

		/** @var Overview $overview */
		$overview = $outcome->getSenseContent();
		$meetingType = 'undefined';
		if (!empty($overview?->meetingType?->typeTag))
		{
			$meetingType = $overview->meetingType->typeTag;
		}

		$payload = new \Bitrix\AI\Payload\Prompt(static::PROMPT_ID);
		$payload->setMarkers([
			'meeting_type' => $meetingType,
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
		return SenseType::EVALUATION->value;
	}

	/**
	 * Allows outputting the chat error message then a task failed.
	 * @return bool
	 */
	public function allowNotifyTaskFailed(): bool
	{
		return true;
	}

	public function filterResult(array $jsonData): array
	{
		$mentionService = MentionService::getInstance();
		$mentionService->loadMentionsForCall($this->getCallId());

		/*
			{
				"meeting_evaluation":
				{
					"dialogue_constructive":
					{
						"thoughts":"string",
						"value":"true/false/null"
					},...
				}
			}
		 */
		foreach ($jsonData['meeting_evaluation'] as &$row)
		{
			$row['thoughts'] = $mentionService->replaceAiMentions($row['thoughts']);
			$row['value'] = match ($row['value'])
			{
				'true', true => true,
				'false', false => false,
				default => null
			};
		}

		return $jsonData;
	}

	/**
	 * @param \Bitrix\AI\Result $aiResult
	 * @return Outcome
	 */
	public function buildOutcome(\Bitrix\AI\Result $aiResult): Outcome
	{
		$outcome = parent::buildOutcome($aiResult);
		$meeting = $this->getMeetingEvent($this->getCallId());
		if ($meeting)
		{
			$outcome->setProperty('calendar', $meeting);
		}

		return $outcome;
	}
}