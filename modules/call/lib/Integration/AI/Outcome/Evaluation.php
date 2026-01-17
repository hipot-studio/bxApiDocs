<?php

namespace Bitrix\Call\Integration\AI\Outcome;

use Bitrix\Call\Integration;
use Bitrix\Call\Integration\AI\MentionService;
use Bitrix\Call\Integration\AI\Task\MeetingEvaluationTask;
use Bitrix\Main\Localization\Loc;


class Evaluation extends AISenseContent
{
	public array $meetingEvaluation = [];
	public int $efficiencyValue = -1;
	public ?\stdClass $calendar = null;/** @see MeetingEvaluationTask::buildOutcome */

	public function __construct(?Integration\AI\Outcome $outcome = null)
	{
		if ($outcome)
		{
			$this->callId = $outcome->getCallId();
			$this->version = (int)($outcome->getProperty('version')?->getContent() ?? 1);

			$totalCriteria = 1; // calendar meeting always included
			$positiveCriteria = 1;// non calendar meeting always positive

			$value = $outcome->getProperty('calendar')?->getStructure();
			if (is_array($value))
			{
				$this->calendar = $this->convertObjectStructure($value);
				if ($this->calendar->overhead === true)
				{
					$positiveCriteria --; // got time overhead
				}
			}

			$values = $outcome->getProperty('meeting_evaluation')?->getStructure();
			if ($values)
			{
				foreach ($values as $criteria => $value)
				{
					if (!is_bool($value['value']))
					{
						continue;
					}
					$value['criteria'] = $criteria;
					$key = $this->generateFieldKey($criteria);
					$this->meetingEvaluation[$key] = $value;
					if ($value['value'] === true)
					{
						$totalCriteria ++;
						$positiveCriteria ++;
					}
					elseif ($value['value'] === false)
					{
						$totalCriteria ++;
					}
				}
			}

			if ($totalCriteria > 0)
			{
				$this->efficiencyValue = ceil(100 / $totalCriteria * $positiveCriteria);
			}
		}
	}

	/**
	 * @return array
	 */
	public function toRestFormat(string $mentionFormat = 'bb'): array
	{
		$mentionService = MentionService::getInstance();

		$result = [
			'efficiencyValue' =>  $this->efficiencyValue,
		];
		if ($this?->calendar)
		{
			$result['calendar'] = [
				'overhead' => $this->calendar->overhead,
			];
		}
		if ($this->meetingEvaluation)
		{
			foreach ($this->meetingEvaluation as $key => $value)
			{
				if (is_bool($value['value']))
				{
					$result[$key] = [
						'value' => $value['value'],
						'criteria' => $value['criteria'],
						'thoughts' => match ($mentionFormat)
						{
							'html' => $mentionService->replaceBBMentions($value['thoughts']),
							'none' => $mentionService->removeBBMentions($value['thoughts']),
							default => $value['thoughts'],//bb
						},
						'title' => $this->getCriteriaTitle($value['criteria']),
					];
				}
			}
		}

		return $result;
	}

	private function getCriteriaTitle(string $criteria): string
	{
		$title = Loc::getMessage('CALL_MEETING_CRITERIA_'.mb_strtoupper($criteria));
		return !empty($title) ? $title : '';
	}
}