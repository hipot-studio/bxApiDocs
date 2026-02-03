<?php

namespace Bitrix\Call\Integration\AI\Outcome;

use Bitrix\Call\Integration;
use Bitrix\Call\Integration\AI\MentionService;
use Bitrix\Main\Localization\Loc;

/*
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
*/

class Insights extends AISenseContent
{
	public bool $speakerEvaluationAvailable = false;
	public array $speakerAnalysis = [];
	public array $meetingStrengths = [];
	public array $meetingWeaknesses = [];
	public string $speechStyleInfluence = '';
	public string $engagementLevel = '';
	public string $areasOfResponsibility = '';
	public string $finalRecommendations = '';

	//region: deprecated fields for version 1
	public array $insights = [];
	//endregion

	public function __construct(?Integration\AI\Outcome $outcome = null)
	{
		if ($outcome)
		{
			$this->callId = $outcome->getCallId();
			$this->version = (int)($outcome->getProperty('version')?->getContent() ?? 1);

			$fieldsMap = [
				'speakerAnalysis' => 'speaker_analysis',
				'meetingStrengths' => 'meeting_strengths',
				'meetingWeaknesses' => 'meeting_weaknesses',
			];
			if ($this->version <= 1)
			{
				$fieldsMap['insights'] = 'insights';
			}
			foreach ($fieldsMap as $field => $prop)
			{
				$values = $outcome->getProperty($prop)?->getStructure();
				if (is_array($values))
				{
					$this->{$field} = [];
					foreach ($values as $row)
					{
						if (is_array($row))
						{
							$obj = $this->convertObjectStructure($row);
							if (!empty($obj))
							{
								$this->{$field}[] = $obj;
							}
						}
						else
						{
							$this->{$field}[] = $row;
						}
					}
				}
			}

			$fieldsMap = [
				'speechStyleInfluence' => 'speech_style_influence',
				'engagementLevel' => 'engagement_level',
				'areasOfResponsibility' => 'areas_of_responsibility',
				'finalRecommendations' => 'final_recommendations',
			];
			foreach ($fieldsMap as $field => $prop)
			{
				$value = $outcome->getProperty($prop);
				if ($value)
				{
					$this->{$field} = $value->getContent();
				}
			}

			$this->speakerEvaluationAvailable = $this->isSpeakerEvaluationAvailable();

			if (
				$this->version > 1
				&& $this->speakerEvaluationAvailable
				&& !empty($this->speakerAnalysis)
			)
			{
				$originalSpeakerAnalysis = $outcome->getProperty('speaker_analysis')?->getStructure() ?? [];
				foreach ($this->speakerAnalysis as $pos => &$analysis)
				{
					$originalAnalysis = $originalSpeakerAnalysis[$pos] ?? [];
					$analysis->efficiencyValue = null;
					$analysis->userId = $this->getMentionService()->detectUserIdByBBMentions($analysis->speaker);
					if ($analysis->evaluationCriteria)
					{
						$totalCriteria = 0;
						$positiveCriteria = 0;
						foreach ($originalAnalysis['evaluation_criteria'] as $criteria => $value)
						{
							if (!is_bool($value))
							{
								continue;
							}
							$key = $this->generateFieldKey($criteria);
							$analysis->evaluationCriteria->{$key} = [
								'value' => $value,
								'criteria' => $criteria,
								'title' => $this->getCriteriaTitle($criteria),
							];
							if ($value === true)
							{
								$totalCriteria++;
								$positiveCriteria++;
							}
							elseif ($value === false)
							{
								$totalCriteria++;
							}
						}
						if ($totalCriteria > 0)
						{
							$analysis->efficiencyValue = ceil(100 / $totalCriteria * $positiveCriteria);
						}
					}
				}
			}
		}
	}

	public function toRestFormat(string $mentionFormat = 'bb'): array
	{
		$mentionService = MentionService::getInstance();
		$replaceMentions = function (string $value) use ($mentionService, $mentionFormat)
		{
			return match ($mentionFormat)
			{
				'html' => $mentionService->replaceBBMentions($value),
				'none' => $mentionService->removeBBMentions($value),
				default => $value,//bb
			};
		};

		$result = ['speakerEvaluationAvailable' => $this->speakerEvaluationAvailable];

		$fields = [
			'speakerAnalysis' => ['detailedInsight', 'evaluationCriteria', 'userId', 'efficiencyValue'],
			'meetingStrengths' => ['strengthExplanation', 'strengthTitle'],
			'meetingWeaknesses' => ['weaknessTitle', 'weaknessExplanation'],
		];
		if ($this->version <= 1)
		{
			$fields['insights'] = ['detailedInsight'];
		}
		foreach ($fields as $field => $subFields)
		{
			if ($this?->{$field})
			{
				$result[$field] = [];
				foreach ($this->{$field} as $i => $row)
				{
					$result[$field][$i] = [];
					foreach ($subFields as $subField)
					{
						if ($row?->{$subField})
						{
							if (is_string($row?->{$subField}))
							{
								$result[$field][$i][$subField] = $replaceMentions($row->{$subField});
							}
							elseif (is_object($row?->{$subField}))
							{
								$result[$field][$i][$subField] = [];
								foreach ($row?->{$subField} as $sub => $rowSub)
								{
									$result[$field][$i][$subField][$sub] = $rowSub;
								}
							}
							else
							{
								$result[$field][$i][$subField] = $row->{$subField};
							}
						}
					}
				}
			}
		}
		foreach (['speechStyleInfluence', 'engagementLevel', 'areasOfResponsibility', 'finalRecommendations'] as $field)
		{
			if ($this?->{$field})
			{
				$result[$field] = $replaceMentions($this->{$field});
			}
		}

		return $result;
	}

	protected function isSpeakerEvaluationAvailable(): bool
	{
		return \Bitrix\Main\Application::getInstance()->getLicense()->isCis();
	}

	private function getCriteriaTitle(string $criteria): string
	{
		$title = Loc::getMessage('CALL_SPEAKER_EVALUATION_CRITERIA_' . mb_strtoupper($criteria));
		return !empty($title) ? $title : '';
	}
}