<?php

namespace Bitrix\Call\Integration\AI\Outcome;

use Bitrix\Call\Integration;
use Bitrix\Call\Integration\AI\MentionService;

/*
{
	"call_summary": [
		{
			"timestamp": "string or null",
			"title": "string or null",
			"summary": "string or null"
		}
	]
}
*/

class Summary extends AISenseContent
{
	/** @var array<array{start: string, end: string, title: string, summary: string}> */
	public array $summary = [];

	public function __construct(?Integration\AI\Outcome $outcome = null)
	{
		if ($outcome)
		{
			$this->callId = $outcome->getCallId();
			$this->version = (int)($outcome->getProperty('version')?->getContent() ?? 1);

			$summary = $outcome->getProperty('call_summary')?->getStructure();
			if (!$summary)
			{
				$summary = $outcome->getProperty('summary')?->getStructure();
			}

			if (is_array($summary))
			{
				foreach ($summary as $row)
				{
					if (!empty($row['summary']) || !empty($row['topic']) || !empty($row['title']))
					{
						$obj = new \stdClass;
						$time = explode('–', $row['timestamp']);
						$obj->start = $time[0];
						$obj->end = $time[1];
						$obj->title = $row['title'] ?? ($row['topic'] ?? '');
						$obj->summary = $row['summary'] ?? '';
						$this->summary[] = $obj;
					}
				}
				$this->isEmpty = empty($this->summary);
			}
		}
	}

	public function toRestFormat(string $mentionFormat = MentionService::FORMAT_BB): array
	{
		$replaceMentions = fn (string $value): string => $this->applyMentionFormat($value, $mentionFormat);

		$result = [];

		foreach ($this->summary as $row)
		{
			if (!empty($row->title) || !empty($row->summary))
			{
				$result[]  = [
					'start' => $row->start,
					'end' => $row->end,
					'title' => $replaceMentions($row->title),
					'summary' => $replaceMentions($row->summary),
				];
			}
		}

		return $result;
	}
}