<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\AiAssistant\Service;

use Bitrix\Call\DTO\FollowUp\Outcome\OverviewDto;
use Bitrix\Socialnetwork\V2\Internal\Integration\Calendar\Dto\GroupMeetingDto;

class ProjectMeetingResponseMapper
{
	/**
	 * Builds the output structure of a single meeting. Empty overview branches are omitted.
	 *
	 * @return array<string, mixed>
	 */
	public function map(GroupMeetingDto $meeting, ?OverviewDto $overview): array
	{
		$item = [
			'meetingId' => $meeting->meetingId,
			'name' => $meeting->name,
			'date' => gmdate(DATE_ATOM, $meeting->date->getTimestamp()),
		];

		if ($overview === null)
		{
			return $item;
		}

		$this->addString($item, 'topic', $overview->topic);
		$this->addObject($item, 'agenda', $overview->agenda, ['explanation', 'quote']);
		$this->addList($item, 'agreements', $overview->agreements, 'agreement');
		$this->addList($item, 'actionItems', $overview->actionItems, 'actionItem');
		$this->addList($item, 'meetings', $overview->meetings, 'meeting');
		$this->addString($item, 'detailedTakeaways', $overview->detailedTakeaways);

		return $item;
	}

	/**
	 * @param array<string, mixed> $target
	 */
	private function addString(array &$target, string $field, ?string $value): void
	{
		if ($value !== null && $value !== '')
		{
			$target[$field] = $value;
		}
	}

	/**
	 * @param array<string, mixed> $target
	 * @param array<string, mixed>|null $source
	 * @param string[] $fields
	 */
	private function addObject(array &$target, string $field, ?array $source, array $fields): void
	{
		if ($source === null)
		{
			return;
		}

		$value = $this->projectFields($source, $fields);
		if ($value !== [])
		{
			$target[$field] = $value;
		}
	}

	/**
	 * @param array<string, mixed> $target
	 * @param array<int, array<string, mixed>>|null $source
	 */
	private function addList(array &$target, string $field, ?array $source, string $textField): void
	{
		if ($source === null)
		{
			return;
		}

		$value = [];
		foreach ($source as $row)
		{
			if (!is_array($row))
			{
				continue;
			}

			$mapped = $this->projectFields($row, [$textField, 'quote']);
			if (isset($mapped[$textField]))
			{
				$value[] = $mapped;
			}
		}

		if ($value !== [])
		{
			$target[$field] = $value;
		}
	}

	/**
	 * @param array<string, mixed> $source
	 * @param string[] $fields
	 * @return array<string, string>
	 */
	private function projectFields(array $source, array $fields): array
	{
		$result = [];
		foreach ($fields as $field)
		{
			$value = $source[$field] ?? null;
			if (is_string($value) && $value !== '')
			{
				$result[$field] = $value;
			}
		}

		return $result;
	}
}
