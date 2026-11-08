<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\AiAssistant\Service;

use Bitrix\Call\Call;
use Bitrix\Call\DTO\FollowUp\Outcome\OverviewDto;
use Bitrix\Call\Service\FollowUpReader;
use Bitrix\Main\Type\DateTime;
use Bitrix\Socialnetwork\V2\Internal\Exceptions\InvalidMeetingPeriodException;
use Bitrix\Socialnetwork\V2\Internal\Integration\AiAssistant\Dto\ProjectMeetingDto;
use Bitrix\Socialnetwork\V2\Internal\Integration\Calendar\Dto\GroupMeetingDto;
use Bitrix\Socialnetwork\V2\Internal\Integration\Calendar\Service\CalendarListService;

class ProjectMeetingsService
{
	private const CALL_START_TOLERANCE_SECONDS = 30 * 60;
	private const CALL_EARLY_START_TOLERANCE_SECONDS = 30 * 60;

	private const CALL_PAGE_SIZE = 200;
	private const CALL_SCAN_LIMIT = 500;

	/**
	 * Only what a meeting is built from: the fields the response mapper reads, plus chatId and
	 * startDate to place a call into the slot of one meeting instance. Narrow overview paths keep
	 * `heavy` off, so the page limit stays at MAX_LIMIT_LIGHT.
	 */
	private const CALL_SELECT = [
		'callId',
		'chatId',
		'startDate',
		'overview.topic',
		'overview.agenda',
		'overview.agreements',
		'overview.actionItems',
		'overview.meetings',
		'overview.detailedTakeaways',
	];

	private const ISO_8601_FORMATS = [
		'Y-m-d\TH:i:sP',
		'Y-m-d\TH:i:s.uP',
		'!Y-m-d\TH:i:s',
		'!Y-m-d',
	];

	public function __construct(
		private readonly CalendarListService $calendarListService,
		private readonly FollowUpReader $followUpReader,
	)
	{
	}

	/**
	 * Returns finished project meetings with optional call overviews, newest first.
	 * Project access must be checked by the caller; per-event calendar access is enforced by the
	 * calendar list service, and per-meeting call access by the follow-up reader
	 * (direct participant or access granted by the associated entity).
	 *
	 * @return list<ProjectMeetingDto>
	 * @throws InvalidMeetingPeriodException
	 */
	public function getProjectMeetings(int $projectId, ?string $from, ?string $to, int $limit, int $userId): array
	{
		if ($userId <= 0)
		{
			return [];
		}

		[$fromDate, $toDate] = $this->parseWindow($from, $to);

		$meetings = $this->calendarListService->getFinishedProjectMeetings(
			groupId: $projectId,
			userId: $userId,
			from: $fromDate,
			to: $toDate,
			limit: $limit,
		);
		if ($meetings === [])
		{
			return [];
		}

		$overviewByMeeting = $this->loadOverviews($meetings, $userId);

		$items = [];
		foreach ($meetings as $index => $meeting)
		{
			$items[] = new ProjectMeetingDto($meeting, $overviewByMeeting[$index] ?? null);
		}

		return $items;
	}

	/**
	 * Resolves the newest contentful overview of each meeting. The meeting window is closed, so one
	 * bounded set of finished calls covers the meeting window, and each call already carries its
	 * overview. Access is enforced by the reader, so no per-call access check happens here.
	 *
	 * @param list<GroupMeetingDto> $meetings
	 * @return array<int, OverviewDto> Overview keyed by the meeting index in $meetings.
	 */
	private function loadOverviews(array $meetings, int $userId): array
	{
		$chatIds = [];
		foreach ($meetings as $meeting)
		{
			if ($meeting->chatId > 0)
			{
				$chatIds[$meeting->chatId] = true;
			}
		}
		if ($chatIds === [])
		{
			return [];
		}

		[$windowFrom, $windowTo] = $this->combinedWindow($meetings);
		$calls = $this->readFinishedCalls(array_keys($chatIds), $windowFrom, $windowTo, $userId);
		if ($calls === [])
		{
			return [];
		}

		$callsByChat = [];
		$overviewByCall = [];
		foreach ($calls as $call)
		{
			$callsByChat[$call['CHAT_ID']][] = $call;
			if ($this->hasOverviewContent($call['OVERVIEW']))
			{
				$overviewByCall[$call['ID']] = $call['OVERVIEW'];
			}
		}

		$out = [];
		foreach ($this->assignCallsToMeetings($meetings, $callsByChat) as $index => $callIds)
		{
			foreach ($callIds as $callId)
			{
				if (isset($overviewByCall[$callId]))
				{
					$out[$index] = $overviewByCall[$callId];
					break;
				}
			}
		}

		return $out;
	}

	/**
	 * A requested block reaches the response even for a call that has no overview of its own, so an
	 * overview counts only when it carries something the response would show. Otherwise a bare block
	 * on the newest call would shadow the real overview of an earlier call of the same meeting.
	 */
	private function hasOverviewContent(?OverviewDto $overview): bool
	{
		if ($overview === null)
		{
			return false;
		}

		return (string)$overview->topic !== ''
			|| (string)$overview->detailedTakeaways !== ''
			|| !empty($overview->agenda)
			|| !empty($overview->agreements)
			|| !empty($overview->actionItems)
			|| !empty($overview->meetings)
		;
	}

	/**
	 * A bounded set of the finished calls of these chats the user may read, newest first, each with
	 * its AI overview. Pages are followed up to CALL_SCAN_LIMIT so older meetings are not silently
	 * skipped when the first FollowUpReader page is full.
	 *
	 * @param int[] $chatIds
	 * @return list<array{ID: int, CHAT_ID: int, START_TS: int, OVERVIEW: ?OverviewDto}>
	 */
	protected function readFinishedCalls(
		array $chatIds,
		DateTime $windowFrom,
		DateTime $windowTo,
		int $userId,
	): array
	{
		if ($userId <= 0)
		{
			return [];
		}

		$select = $this->followUpReader->validateSelect(self::CALL_SELECT);
		if (!$select->isSuccess())
		{
			throw new \LogicException('The internal project meeting call select is invalid.');
		}

		$calls = [];
		$afterCursor = null;
		$scanned = 0;
		do
		{
			$pageLimit = min(self::CALL_PAGE_SIZE, self::CALL_SCAN_LIMIT - $scanned);
			$pagination = ['limit' => $pageLimit];
			if ($afterCursor !== null)
			{
				$pagination['afterCursor'] = $afterCursor;
			}

			$page = $this->followUpReader->list(
				normalizedSelect: $select->getData(),
				dateFrom: $windowFrom,
				dateTo: $windowTo,
				participantFilter: $userId,
				order: ['startDate' => 'desc'],
				pagination: $pagination,
				mentionFormat: FollowUpReader::MENTION_FORMAT_NONE,
				chatIds: $chatIds,
				stateFilter: Call::STATE_FINISHED,
			);

			$pageCount = 0;
			foreach ($page['items'] as $item)
			{
				$pageCount++;
				$startTimestamp = $item->startDate === null ? false : strtotime($item->startDate);
				if ((int)$item->callId <= 0 || $startTimestamp === false)
				{
					continue;
				}

				$calls[] = [
					'ID' => (int)$item->callId,
					'CHAT_ID' => (int)$item->chatId,
					'START_TS' => $startTimestamp,
					'OVERVIEW' => $item->overview,
				];
			}
			$scanned += $pageCount;
			$afterCursor = $page['hasMore'] ? ($page['afterCursor'] ?? null) : null;
		}
		while ($scanned < self::CALL_SCAN_LIMIT && $pageCount > 0 && $afterCursor !== null);

		return $calls;
	}

	/**
	 * Assigns every call to at most one meeting. Meetings of one chat are instances of the same
	 * recurrence whose tolerance windows overlap, so a call is placed by its start time into the slot
	 * of a single instance. A call started before the slot belongs to the preceding instance — even to
	 * one outside the response — and is left out instead of being borrowed by the next meeting.
	 *
	 * @param list<GroupMeetingDto> $meetings
	 * @param array<int, list<array{ID: int, START_TS: int}>> $callsByChat
	 * @return array<int, list<int>> Call ids by meeting index, newest call first.
	 */
	private function assignCallsToMeetings(array $meetings, array $callsByChat): array
	{
		$callIdsByMeeting = [];
		foreach ($this->meetingIndexesByChat($meetings) as $chatId => $indexes)
		{
			$calls = $callsByChat[$chatId] ?? [];
			if ($calls === [])
			{
				continue;
			}

			foreach ($this->ownershipWindows($meetings, $indexes) as $index => [$ownFrom, $ownTo])
			{
				$callIds = $this->matchCalls($calls, $ownFrom, $ownTo);
				if ($callIds !== [])
				{
					$callIdsByMeeting[$index] = $callIds;
				}
			}
		}
		ksort($callIdsByMeeting);

		return $callIdsByMeeting;
	}

	/**
	 * @param list<GroupMeetingDto> $meetings
	 * @return array<int, list<int>> Meeting indexes by chat id, ordered by meeting start.
	 */
	private function meetingIndexesByChat(array $meetings): array
	{
		$indexesByChat = [];
		foreach ($meetings as $index => $meeting)
		{
			if ($meeting->chatId > 0)
			{
				$indexesByChat[$meeting->chatId][] = $index;
			}
		}

		foreach ($indexesByChat as &$indexes)
		{
			usort(
				$indexes,
				static fn (int $left, int $right): int =>
					$meetings[$left]->date->getTimestamp() <=> $meetings[$right]->date->getTimestamp(),
			);
		}
		unset($indexes);

		return $indexesByChat;
	}

	/**
	 * Bounds of the call start owned by each meeting of one chat: its own window, narrowed by the
	 * boundaries with the neighbouring instances.
	 *
	 * @param list<GroupMeetingDto> $meetings
	 * @param list<int> $indexes Meeting indexes of one chat, ordered by start.
	 * @return array<int, array{0: int, 1: int}> Start timestamp bounds by meeting index.
	 */
	private function ownershipWindows(array $meetings, array $indexes): array
	{
		$windows = [];
		foreach ($indexes as $position => $index)
		{
			$meeting = $meetings[$index];
			[$windowFrom, $windowTo] = $this->meetingWindow($meeting);
			$ownFrom = $windowFrom->getTimestamp() - self::CALL_EARLY_START_TOLERANCE_SECONDS;
			$ownTo = $windowTo->getTimestamp();

			$previousIndex = $indexes[$position - 1] ?? null;
			if ($previousIndex !== null)
			{
				$ownFrom = max($ownFrom, $this->ownershipBoundary($meetings[$previousIndex], $meeting));
			}
			elseif ($meeting->previousOccurrenceEndDate !== null)
			{
				$ownFrom = max(
					$ownFrom,
					$this->ownershipBoundaryFromTimestamps(
						$meeting->previousOccurrenceEndDate->getTimestamp(),
						$meeting->date->getTimestamp(),
					),
				);
			}

			$nextIndex = $indexes[$position + 1] ?? null;
			if ($nextIndex !== null)
			{
				$ownTo = min($ownTo, $this->ownershipBoundary($meeting, $meetings[$nextIndex]) - 1);
			}

			$windows[$index] = [$ownFrom, $ownTo];
		}

		return $windows;
	}

	/**
	 * A call started in the gap between two instances belongs to the closer one, so the boundary lies
	 * midway between them; instances that overlap are split at the start of the later one.
	 */
	private function ownershipBoundary(GroupMeetingDto $earlier, GroupMeetingDto $later): int
	{
		return $this->ownershipBoundaryFromTimestamps(
			$earlier->endDate->getTimestamp(),
			$later->date->getTimestamp(),
		);
	}

	private function ownershipBoundaryFromTimestamps(int $earlierEnd, int $laterStart): int
	{
		return $earlierEnd < $laterStart
			? intdiv($earlierEnd + $laterStart, 2)
			: $laterStart
		;
	}

	/**
	 * Finished calls started inside the bounds owned by the meeting, latest first. The caller supplies
	 * one page ordered by start date, so preserving row order is enough.
	 *
	 * @param list<array{ID: int, START_TS: int}> $calls Calls of the meeting's chat.
	 * @return list<int> Call ids ordered by start date, newest first.
	 */
	private function matchCalls(array $calls, int $ownFrom, int $ownTo): array
	{
		$callIds = [];
		foreach ($calls as $call)
		{
			if ($call['START_TS'] < $ownFrom || $call['START_TS'] > $ownTo)
			{
				continue;
			}

			$callIds[] = $call['ID'];
		}

		return $callIds;
	}

	/**
	 * Union of every meeting's own interval for the single batched lookup.
	 *
	 * @param list<GroupMeetingDto> $meetings
	 * @return array{0: DateTime, 1: DateTime}
	 */
	private function combinedWindow(array $meetings): array
	{
		$minFrom = null;
		$maxTo = null;
		foreach ($meetings as $meeting)
		{
			if ($meeting->chatId <= 0)
			{
				continue;
			}
			[$from, $to] = $this->meetingWindow($meeting);
			$fromTs = $from->getTimestamp() - self::CALL_EARLY_START_TOLERANCE_SECONDS;
			$toTs = $to->getTimestamp();
			$minFrom = $minFrom === null ? $fromTs : min($minFrom, $fromTs);
			$maxTo = $maxTo === null ? $toTs : max($maxTo, $toTs);
		}

		return [
			DateTime::createFromTimestamp($minFrom ?? 0),
			DateTime::createFromTimestamp($maxTo ?? 0),
		];
	}

	/**
	 * @return array{0: DateTime, 1: DateTime}
	 */
	private function meetingWindow(GroupMeetingDto $meeting): array
	{
		$startTimestamp = $meeting->date->getTimestamp();
		if ($meeting->isAllDay)
		{
			$fallbackDayEnd = (new \DateTimeImmutable('@' . $startTimestamp))
				->setTimezone(new \DateTimeZone('UTC'))
				->setTime(23, 59, 59)
				->getTimestamp()
			;
			$endTimestamp = max($meeting->endDate->getTimestamp(), $fallbackDayEnd);
		}
		else
		{
			$endTimestamp = $meeting->endDate->getTimestamp() + self::CALL_START_TOLERANCE_SECONDS;
		}

		return [
			DateTime::createFromTimestamp($startTimestamp),
			DateTime::createFromTimestamp($endTimestamp),
		];
	}

	/**
	 * @return array{0: ?DateTime, 1: ?DateTime}
	 * @throws InvalidMeetingPeriodException
	 */
	private function parseWindow(?string $from, ?string $to): array
	{
		$fromDate = $this->parseBoundary($from, false);
		$toDate = $this->parseBoundary($to, true);
		if (
			$fromDate !== null
			&& $toDate !== null
			&& $fromDate->getTimestamp() > $toDate->getTimestamp()
		)
		{
			throw new InvalidMeetingPeriodException('Invalid date range: "from" must not be later than "to".');
		}

		return [$fromDate, $toDate];
	}

	/**
	 * Parses an ISO 8601 window boundary. UTC is assumed when the offset is omitted.
	 * A date-only upper boundary is inclusive: it expands to the end of that day.
	 *
	 * @throws InvalidMeetingPeriodException
	 */
	private function parseBoundary(?string $value, bool $isUpperBoundary): ?DateTime
	{
		if ($value === null)
		{
			return null;
		}

		$normalized = str_ends_with($value, 'Z') ? substr($value, 0, -1) . '+00:00' : $value;

		foreach (self::ISO_8601_FORMATS as $format)
		{
			$parsed = \DateTimeImmutable::createFromFormat($format, $normalized, new \DateTimeZone('UTC'));
			$errors = \DateTimeImmutable::getLastErrors() ?: ['warning_count' => 0, 'error_count' => 0];
			if ($parsed === false || $errors['warning_count'] > 0 || $errors['error_count'] > 0)
			{
				continue;
			}

			if ($isUpperBoundary && $format === '!Y-m-d')
			{
				$parsed = $parsed->setTime(23, 59, 59);
			}

			return DateTime::createFromTimestamp($parsed->getTimestamp());
		}

		throw new InvalidMeetingPeriodException(
			sprintf(
				'Invalid date "%s". Use ISO 8601: "YYYY-MM-DD" or "YYYY-MM-DDTHH:MM:SS",'
				. ' optionally with a timezone offset (UTC is assumed when omitted).',
				$value,
			),
		);
	}
}
