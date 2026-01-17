<?php

namespace Bitrix\Call\Integration\AI\Outcome;

use Bitrix\Call\Integration;
use Bitrix\Call\Integration\AI\MentionService;

class Transcription extends AISenseContent
{
	/** @var array<array{start: string, end: string, userId: int, user: string, text: string}> */
	public array $transcriptions = [];

	public string $language = '';

	public bool $isEmpty = true;


	public function __construct(?Integration\AI\Outcome $outcome = null)
	{
		if ($outcome)
		{
			$this->callId = $outcome->getCallId();
			$this->version = (int)($outcome->getProperty('version')?->getContent() ?? 1);
			$this->language = $outcome->getProperty('language')?->getContent() ?? '';

			$transcriptions = $outcome->getProperty('transcriptions')?->getStructure();
			if (is_array($transcriptions))
			{
				$users = [];
				foreach ($transcriptions as $row)
				{
					$row['text'] = trim($row['text']);
					if (empty($row['text']))
					{
						continue;
					}
					$obj = new \stdClass;
					$obj->userId = (int)$row['user_id'];

					if (!isset($users[$obj->userId]))
					{
						$user = \Bitrix\Im\User::getInstance($obj->userId);
						$users[$obj->userId] = $user->getFullName(false) ?: "User{$obj->userId}";
					}
					$obj->start = $row['start_time_formatted'];
					$obj->end = $row['end_time_formatted'];
					$obj->user = $users[$obj->userId];
					$obj->text = $row['text'];

					$this->transcriptions[] = $obj;
				}
				$this->isEmpty = empty($this->transcriptions);
			}
		}
	}

	public function toRestFormat(string $mentionFormat = 'bb'): array
	{
		$result = [];
		foreach ($this->transcriptions as $row)
		{
			if (!empty($row->text))
			{
				$result[] = [
					'userId' => $row->userId,
					'user' => $row->user,
					'start' => $row->start,
					'end' => $row->end,
					'text' => $this->getMentionService()->replaceBBMentions($row->text),
				];
			}
		}

		return $result;
	}

	public function prepareTextForAi(): string
	{
		$content = '';
		foreach ($this->transcriptions as $row)
		{
			$userName = addslashes($this->getMentionService()->getAIMention($row->userId, $this->callId));
			$text = addslashes($row->text);
			// "00:00-00:45", "user", "phrase",
			$content .= sprintf('"%s-%s", "%s", "%s"', $row->start, $row->end, $userName, $text). "\n";
		}

		return $content;
	}

	/**
	 * Returns speaker list for call participants who spoke more than threshold seconds.
	 * @return array<array{userId: int, duration: int, talkPercentage: int}>
	 * @param int $thresholdSeconds
	 */
	public function prepareSpeakersList(int $thresholdSeconds = 30): array
	{
		$speakerTimes = [];
		foreach ($this->transcriptions as $row)
		{
			$startSeconds = $this->timeToSeconds($row->start);
			$endSeconds = $this->timeToSeconds($row->end);
			$duration = $endSeconds - $startSeconds;

			if (!isset($speakerTimes[$row->userId]))
			{
				$speakerTimes[$row->userId] = [
					'userId' => $row->userId,
					'duration' => 0,
					'talkPercentage' => 0,
				];
			}
			$speakerTimes[$row->userId]['duration'] += $duration;
		}
		$totalDuration = array_sum(array_column($speakerTimes, 'duration'));
		$speakerTimes = array_filter($speakerTimes, fn($speaker) => $speaker['duration'] >= $thresholdSeconds);
		array_walk(
			$speakerTimes,
			function (&$speaker) use ($totalDuration)
			{
				$speaker['talkPercentage'] = $totalDuration > 0 ? round($speaker['duration'] / $totalDuration * 100) : 0;
			}
		);

		return $speakerTimes;
	}

	/**
	 * Builds speaker list for participants.
	 * @param int $thresholdSeconds
	 * @return string
	 */
	public function buildSpeakersListForAi(int $thresholdSeconds = 30): string
	{
		$speakers = array_keys($this->prepareSpeakersList($thresholdSeconds));
		$activeSpeakers = [];
		foreach ($speakers as $userId)
		{
			$activeSpeakers[] = $this->getMentionService()->getAIMention($userId, $this->callId);
		}

		return implode(",\n", $activeSpeakers);
	}

	/**
	 * Converts time format MM:SS to seconds
	 * @param string $time
	 * @return int
	 */
	private function timeToSeconds(string $time): int
	{
		$parts = explode(':', $time);
		if (count($parts) === 2)
		{
			return (int)$parts[0] * 60 + (int)$parts[1];
		}
		elseif (count($parts) === 3)
		{
			return (int)$parts[0] * 3600 + (int)$parts[1] * 60 + (int)$parts[2];
		}

		return 0;
	}
}