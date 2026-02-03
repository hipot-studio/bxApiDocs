<?php

namespace Bitrix\Call\Integration\AI\Outcome;

use Bitrix\Main\Localization\Loc;
use Bitrix\Call\Integration;
use Bitrix\Call\Integration\AI\MentionService;
use Bitrix\Call\Integration\AI\Task\TranscriptionOverview;

/*
{
	"topic": "string or null",
	"meeting_type": {
		"explanation": "string",
		"type_tag": "string"
	},
	"agenda": {
		"is_mentioned": "bool",
		"explanation": "string or null",
		"quote": "string or null"
	},
	"detailed_takeaways": "long string or null",
	"agreements": [
		{
			"agreement": "string or null",
			"quote": "string or null"
		}
	],
	"action_items": [
		{
			"action_item": "string or null",
			"quote": "string or null"
		}
	],
	"meetings": [
		{
			"meeting": "string or null",
			"quote": "string or null"
		}
	]
}
*/

class Overview extends AISenseContent
{
	public string $topic = '';
	public ?\stdClass $meetingType = null;
	public ?\stdClass $agenda = null;
	public array $actionItems = [];
	public array $meetings = [];
	public array $agreements = [];
	public string $detailedTakeaways = '';

	//region: deprecated fields for version 2
	public ?\stdClass $calendar = null;
	//region: deprecated fields for version 1
	public ?\stdClass $efficiency = null;
	public int $efficiencyValue = -1;
	public array $tasks = [];
	public ?\stdClass $meetingDetails = null;
	public bool $isExceptionMeeting = false;
	//endregion


	public function __construct(?Integration\AI\Outcome $outcome = null)
	{
		if ($outcome)
		{
			$this->callId = $outcome->getCallId();
			$this->version = (int)($outcome->getProperty('version')?->getContent() ?? 1);

			$fieldsMap = [
				'topic' => 'topic',
				'detailedTakeaways' => 'detailed_takeaways',
			];
			foreach ($fieldsMap as $field => $prop)
			{
				$value = $outcome->getProperty($prop);
				if ($value)
				{
					$this->{$field} = $value->getContent();
				}
			}

			$fieldsMap = [
				'meetingType' => 'meeting_type',
				'agenda' => 'agenda',
				'efficiency' => 'efficiency',
			];
			if ($this->version <= 1)
			{
				$fieldsMap['meetingDetails'] = 'meeting_details';
			}
			if ($this->version <= 2)
			{
				$fieldsMap['calendar'] = 'calendar';
			}
			foreach ($fieldsMap as $field => $prop)
			{
				$value = $outcome->getProperty($prop)?->getStructure();
				if (is_array($value))
				{
					$this->{$field} = $this->convertObjectStructure($value);
				}
			}

			$fieldsMap = [
				'actionItems' => 'action_items',
				'meetings' => 'meetings',
				'agreements' => 'agreements',
			];
			if ($this->version <= 1)
			{
				$fieldsMap['tasks'] = 'tasks';
			}
			foreach ($fieldsMap as $field => $prop)
			{
				$values = $outcome->getProperty($prop)?->getStructure();
				if (is_array($values))
				{
					$this->{$field} = [];
					foreach ($values as $row)
					{
						if (is_array($row) && !empty($row))
						{
							$obj = $this->convertObjectStructure($row);
							if (!empty($obj))
							{
								$this->{$field}[] = $obj;
							}
						}
					}
				}
			}

			if ($this->version <= 1)
			{
				if ($this->meetingDetails)
				{
					$this->isExceptionMeeting = (bool)($this->meetingDetails?->isExceptionMeeting);
				}

				$this->calcEfficiency();
			}
		}
	}

	/**
	 * @return array
	 */
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

		$result = [];

		if ($this?->detailedTakeaways)
		{
			$result['detailedTakeaways'] = $replaceMentions($this->detailedTakeaways);
		}
		if ($this?->topic)
		{
			$result['topic'] = $replaceMentions($this->topic);
		}
		if ($this?->meetingType)
		{
			$result['meetingType'] = [];
			if ($this->meetingType?->explanation)
			{
				$result['meetingType']['explanation'] = $replaceMentions($this->meetingType->explanation);
			}
			if ($this->meetingType?->typeTag)
			{
				$result['meetingType']['typeTag'] = $this->meetingType->typeTag;
				$result['meetingType']['title'] = $this->getMeetingTypeTitle($this->meetingType->typeTag);
			}
		}
		if ($this?->agenda)
		{
			$result['agenda'] = [];
			if ($this->agenda?->explanation)
			{
				$result['agenda']['explanation'] = $replaceMentions($this->agenda->explanation);
			}
			if ($this->agenda?->quote)
			{
				$result['agenda']['quote'] = $replaceMentions($this->agenda->quote);
			}
		}
		if ($this?->agreements)
		{
			$result['agreements'] = [];
			foreach ($this->agreements as $i => $row)
			{
				if ($row?->agreement)
				{
					$result['agreements'][$i] = [
						'agreement' => $replaceMentions($row->agreement)
					];
					if ($row?->quote)
					{
						$result['agreements'][$i]['quote'] = $replaceMentions($row->quote);
					}
				}
			}
		}
		if ($this?->meetings)
		{
			$result['meetings'] = [];
			foreach ($this->meetings as $i => $row)
			{
				if ($row?->meeting)
				{
					$meeting = $row->meeting;
					$result['meetings'][$i] = [
						'meeting' => $replaceMentions($meeting),
						'meetingMentionLess' => $mentionService->removeBbMentions($meeting),
					];
					if ($row?->quote)
					{
						$result['meetings'][$i]['quote'] = $replaceMentions($row->quote);
					}
				}
			}
		}
		if ($this?->actionItems)
		{
			$result['actionItems'] = [];
			foreach ($this->actionItems as $i => $row)
			{
				if ($row?->actionItem)
				{
					$actionItem = $row->actionItem;
					$result['actionItems'][$i] = [
						'actionItem' => $replaceMentions($actionItem),
						'actionItemMentionLess' => $mentionService->removeBbMentions($actionItem)
					];
					if ($row?->quote)
					{
						$result['actionItems'][$i]['quote'] = $replaceMentions($row->quote);
					}
				}
			}
		}

		//region: deprecated fields for version 1
		if ($this->version <= 2)
		{
			if ($this?->calendar)
			{
				$result['calendar'] = [
					'overhead' => $this->calendar->overhead,
				];
			}
		}
		if ($this->version <= 1)
		{
			if ($this?->meetingDetails)
			{
				$result['meetingDetails'] = [
					'type' => $this->meetingDetails->type,
				];
			}
			if ($this?->efficiency)
			{
				$result['efficiency'] = [
					'type' => $this->meetingDetails->type,
					'agendaClearlyStated' => (bool)$this->efficiency?->agendaClearlyStated?->value,
					'agendaItemsCovered' => (bool)$this->efficiency?->agendaItemsCovered?->value,
					'conclusionsAndActionsOutlined' => (bool)$this->efficiency?->conclusionsAndActionsOutlined?->value,
				];
			}
			if ($this->efficiencyValue)
			{
				$result['efficiencyValue'] = $this->efficiencyValue;
			}
			if ($this?->tasks)
			{
				$result['tasks'] = [];
				foreach ($this->tasks as  $i => $row)
				{
					if ($row?->task)
					{
						$task = $row->task;
						$result['tasks'][$i] = [
							'task' => $replaceMentions($task),
							'taskMentionLess' => $mentionService->removeBbMentions($task),
						];
						if ($row?->quote)
						{
							$result['tasks'][$i]['quote'] = $replaceMentions($row->quote);
						}
					}
				}
			}

			$result['isExceptionMeeting'] = $this->isExceptionMeeting;
		}
		//endregion

		return $result;
	}

	private function getMeetingTypeTitle(string $meetingType): string
	{
		$title = Loc::getMessage("CALL_MEETING_TYPE_".mb_strtoupper($meetingType));
		if (empty($title))
		{
			$title = Loc::getMessage('CALL_MEETING_TYPE_UNDEFINED');
		}
		return $title ?: '';
	}

	/**
	 * @deprecated compatibility only with version 1
	 * @return int
	 */
	private function calcEfficiency(): int
	{
		if (!empty($this->efficiency))
		{
			$this->efficiencyValue = 0;

			$isPersist = function ($field): bool
			{
				if (!empty($this->efficiency->{$field}))
				{
					if (
						isset($this->efficiency->{$field}->value)
						&& (bool)$this->efficiency->{$field}->value
					)
					{
						return true;
					}
					elseif (
						is_bool($this->efficiency->{$field})
						&& $this->efficiency->{$field}
					)
					{
						return true;
					}
				}
				return false;
			};

			if ($this->isExceptionMeeting)
			{
				$this->efficiencyValue += 25; // #1
				$this->efficiencyValue += 25; // #3
				if ($isPersist('agendaItemsCovered'))
				{
					$this->efficiencyValue += 25;
				}
			}
			else
			{
				$efficiencyWeights = [
					'agendaClearlyStated' => 25, // #1
					'agendaItemsCovered' => 25, // #2
					'conclusionsAndActionsOutlined' => 25,// #3
				];
				foreach ($efficiencyWeights as $field => $weight)
				{
					if ($isPersist($field))
					{
						$this->efficiencyValue += $weight;
					}
				}
			}

			// #4
			if ($this->calendar)
			{
				$this->efficiencyValue += $this->calendar->overhead ? 0 : 25;
			}
			else
			{
				$this->efficiencyValue += 25;
			}

			return $this->efficiencyValue;
		}

		return -1;
	}
}