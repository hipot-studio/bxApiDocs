<?php

namespace Bitrix\Call\Integration\AI\Task;

use Bitrix\Main\Result;
use Bitrix\Main\Web\Json;
use Bitrix\Call\Integration\AI\Outcome;
use Bitrix\Call\Integration\AI\SenseType;
use Bitrix\Call\Integration\AI\CallAIError;
use Bitrix\Call\Integration\AI\MentionService;
use Bitrix\Call\Integration\AI\CallAISettings;
use Bitrix\Call\Integration\AI\Outcome\Overview;
use Bitrix\Call\Integration\AI\Outcome\Transcription;
use Bitrix\Call\Integration\AI\Outcome\OutcomeCollection;

class TranscriptionInsights extends AITask
{
	public const
		PROMPT_ID = 'meeting_insights_reasoning_eu',
		PROMPT_ID_CIS = 'meeting_insights_reasoning_cis',
		PROMPT_ID_CIS_EVALUATION = 'meeting_insights_reasoning_cis_updated'
	;

	protected static string
		$promptFields =<<<JSON
			{
				"insights": [
					{
						"speaker": "string or null",
						"detailed_insight": "string or null"
					}
				],
				"speaker_analysis": [
					{
						"speaker": "string or null",
						"detailed_insight": "string or null",
						"evaluation_criteria": 
						{
							"politeness": "bool",
							"positive_attitude": "bool",
							"stays_on_topic": "bool",
							"communication_clarity": "bool",
							"individual_contribution": "bool",
							"time_management_conciseness": "bool"
						}
					}
				],
				"meeting_strengths": [
					{
						"strength_title": "string",
						"strength_explanation": "string"
					}
				],
				"meeting_weaknesses": [
					{
						"weakness_title": "string",
						"weakness_explanation": "string"
					}
				],
				"speech_style_influence": "string or null",
				"engagement_level": "string or null",
				"areas_of_responsibility": "string or null",
				"final_recommendations": "string"
			}
		JSON
	;

	/**
	 * Outcome version for compatibility with previous variant.
	 * @return int
	 */
	public function getVersion(): int
	{
		return in_array(\Bitrix\Main\Application::getInstance()->getLicense()->getRegion(), ['ru', 'by']) ? 2 : 1;
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

		$callId = $outcome->getCallId();
		$call = \Bitrix\Im\Call\Registry::getCallWithId($callId);
		if (!$call)
		{
			return $result->addError(new CallAIError(CallAIError::AI_EMPTY_PAYLOAD_ERROR));// Empty outcome content
		}

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
		if (empty($overview?->meetingType?->typeTag))
		{
			$meetingType = $overview->meetingType->typeTag;
		}

		$payload = new \Bitrix\AI\Payload\Prompt($this->getPromptCode());
		$markers = [
			'meeting_type' => $meetingType,
			'transcripts' => $transcription->prepareTextForAi(),
		];

		$meeting = $this->getMeetingEvent($this->getCallId());
		if ($meeting)
		{
			$markers['meeting_name'] = $meeting->title;
			$markers['meeting_description'] = $meeting->description;
		}

		$speakersList = $transcription->buildSpeakersListForAi();
		if (!empty($speakersList))
		{
			$markers['speakers_list'] = $speakersList;
		}

		$payload->setMarkers($markers);

		return $result->setData(['payload' => $payload]);
	}

	protected function getPromptCode(): string
	{
		$promptCode = self::PROMPT_ID;
		if (\Bitrix\Main\Application::getInstance()->getLicense()->isCis())
		{
			$promptCode = self::PROMPT_ID_CIS;
			if (in_array(\Bitrix\Main\Application::getInstance()->getLicense()->getRegion(), ['ru', 'by']))
			{
				$promptCode = self::PROMPT_ID_CIS_EVALUATION;
			}
		}
		return $promptCode;
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
		return SenseType::INSIGHTS->value;
	}

	protected static function getAIPromptFields(): array
	{
		static $fields;
		if (empty($fields))
		{
			$fields = Json::decode(static::$promptFields);
		}
		return $fields;
	}

	public function filterResult(array $jsonData): array
	{
		$mentionService = MentionService::getInstance();
		$mentionService->loadMentionsForCall($this->getCallId());

		$fields = static::getAIPromptFields();
		$fieldsConvert = [];
		$fieldsBool = [];
		($findFieldToConvert = static function (array $fields) use (&$findFieldToConvert, &$fieldsConvert, &$fieldsBool)
		{
			foreach ($fields as $code => $field)
			{
				if (is_array($field))
				{
					$findFieldToConvert($field);
				}
				elseif (is_string($field) && str_contains($field, 'string'))
				{
					$fieldsConvert[$code] = true;
				}
				elseif (is_string($field) && $field == 'bool')
				{
					$fieldsBool[$code] = true;
				}
				else
				{
					$fieldsConvert[$code] = true;
				}
			}
		})($fields);

		($convert = static function (array &$jsonData) use (&$convert, $fieldsConvert, $mentionService, $fieldsBool)
		{
			foreach ($jsonData as $code => &$field)
			{
				if (is_array($field))
				{
					$convert($field);
				}
				elseif (isset($fieldsBool[$code]))
				{
					$field = match ($field)
					{
						'true', true => true,
						'false', false => false,
						default => null
					};
				}
				elseif (is_string($field) && isset($fieldsConvert[$code]))
				{
					$field = $mentionService->replaceAiMentions($field);
				}
			}
		})($jsonData);

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
