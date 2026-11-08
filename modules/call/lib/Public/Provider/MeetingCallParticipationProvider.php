<?php

declare(strict_types=1);

namespace Bitrix\Call\Public\Provider;

use Bitrix\Call\Call;
use Bitrix\Call\Model\CallTable;
use Bitrix\Call\Model\CallUserTable;
use Bitrix\Call\Recent;
use Bitrix\Main\Type\DateTime;

final class MeetingCallParticipationProvider
{
	public const PARTICIPATED = 'participated';
	public const NOT_PARTICIPATED = 'notParticipated';
	public const UNKNOWN = 'unknown';

	private const MEETING_LIMIT = 500;
	private const CALL_LIMIT = 2000;
	private const CALL_SELECTION_DEPTH = 86400;
	private const ACTIVE_CALL_MAX_DURATION = Recent::ACTIVE_CALLS_DEPTH_HOURS * 3600;

	/**
	 * @param array<string, array{chatId: int, windowFrom: \DateTimeInterface, windowTo: \DateTimeInterface}> $meetings
	 *
	 * @return array<string, string> meeting key to participation status
	 */
	public function getForMeetings(
		array $meetings,
		int $userId,
		\DateTimeInterface $windowFrom,
		\DateTimeInterface $windowTo,
	): array
	{
		$statuses = array_fill_keys(array_keys($meetings), self::UNKNOWN);
		if ($userId <= 0 || $windowFrom >= $windowTo)
		{
			return $statuses;
		}

		$normalizedMeetings = $this->normalizeMeetings(
			$meetings,
			$windowFrom->getTimestamp(),
			$windowTo->getTimestamp(),
		);
		if ($normalizedMeetings === [])
		{
			return $statuses;
		}

		$chatIds = array_values(array_unique(array_column($normalizedMeetings, 'chatId')));

		$from = DateTime::createFromTimestamp($windowFrom->getTimestamp());
		$to = DateTime::createFromTimestamp($windowTo->getTimestamp());
		$calls = CallTable::query()
			->setSelect(['ID', 'CHAT_ID', 'STATE', 'START_DATE', 'END_DATE'])
			->setFilter([
				'@CHAT_ID' => $chatIds,
				'>=START_DATE' => DateTime::createFromTimestamp(
					$windowFrom->getTimestamp() - self::CALL_SELECTION_DEPTH,
				),
				'<START_DATE' => $to,
				[
					'LOGIC' => 'OR',
					'>END_DATE' => $from,
					'=END_DATE' => null,
				],
			])
			->setLimit(self::CALL_LIMIT + 1)
			->exec()
			->fetchAll()
		;
		if (count($calls) > self::CALL_LIMIT)
		{
			return $statuses;
		}

		$callIds = array_map(static fn(array $call): int => (int)$call['ID'], $calls);
		$usersByCall = [];
		if ($callIds !== [])
		{
			$userRows = CallUserTable::query()
				->setSelect(['CALL_ID', 'FIRST_JOINED'])
				->whereIn('CALL_ID', $callIds)
				->where('USER_ID', $userId)
				->exec()
			;
			while ($user = $userRows->fetch())
			{
				$usersByCall[(int)$user['CALL_ID']] = $user;
			}
		}

		return array_replace($statuses, $this->resolveStatuses($normalizedMeetings, $calls, $usersByCall));
	}

	/**
	 * @return array<string, array{chatId: int, from: int, to: int}>
	 */
	private function normalizeMeetings(array $meetings, int $windowFrom, int $windowTo): array
	{
		$normalizedMeetings = [];
		foreach ($meetings as $key => $meeting)
		{
			if (count($normalizedMeetings) >= self::MEETING_LIMIT)
			{
				break;
			}

			$chatId = is_array($meeting) ? (int)($meeting['chatId'] ?? 0) : 0;
			$meetingFrom = is_array($meeting) ? ($meeting['windowFrom'] ?? null) : null;
			$meetingTo = is_array($meeting) ? ($meeting['windowTo'] ?? null) : null;
			if (
				!is_string($key)
				|| $key === ''
				|| $chatId <= 0
				|| !$meetingFrom instanceof \DateTimeInterface
				|| !$meetingTo instanceof \DateTimeInterface
			)
			{
				continue;
			}

			$fromTimestamp = max($windowFrom, $meetingFrom->getTimestamp());
			$toTimestamp = min($windowTo, $meetingTo->getTimestamp());
			if ($fromTimestamp >= $toTimestamp)
			{
				continue;
			}

			$normalizedMeetings[$key] = [
				'chatId' => $chatId,
				'from' => $fromTimestamp,
				'to' => $toTimestamp,
			];
		}

		return $normalizedMeetings;
	}

	private function resolveStatuses(array $meetings, array $calls, array $usersByCall): array
	{
		$meetingsByChat = [];
		foreach ($meetings as $key => $meeting)
		{
			$meetingsByChat[$meeting['chatId']][$key] = $meeting;
		}

		$matchingCalls = array_fill_keys(array_keys($meetings), []);
		$hasAmbiguousCall = array_fill_keys(array_keys($meetings), false);
		foreach ($calls as $call)
		{
			$callFrom = $this->timestamp($call['START_DATE'] ?? null);
			if ($callFrom === null)
			{
				continue;
			}

			// an unfinished call cannot outlive Recent::finishOldCallsAgent
			$callTo = $this->timestamp($call['END_DATE'] ?? null) ?? $callFrom + self::ACTIVE_CALL_MAX_DURATION;

			$matchedMeetingKeys = [];
			foreach ($meetingsByChat[(int)($call['CHAT_ID'] ?? 0)] ?? [] as $key => $meeting)
			{
				if ($callFrom < $meeting['to'] && $callTo > $meeting['from'])
				{
					$matchedMeetingKeys[] = $key;
				}
			}

			if (count($matchedMeetingKeys) === 1)
			{
				$matchingCalls[$matchedMeetingKeys[0]][] = $call;
			}
			elseif (count($matchedMeetingKeys) > 1)
			{
				foreach ($matchedMeetingKeys as $key)
				{
					$hasAmbiguousCall[$key] = true;
				}
			}
		}

		$statuses = array_fill_keys(array_keys($meetings), self::UNKNOWN);
		foreach (array_keys($meetings) as $key)
		{
			if ($matchingCalls[$key] === [])
			{
				continue;
			}

			$allFinished = true;
			$allHaveParticipantRow = true;
			foreach ($matchingCalls[$key] as $call)
			{
				$callId = (int)$call['ID'];
				$user = $usersByCall[$callId] ?? null;
				if ($user !== null && ($user['FIRST_JOINED'] ?? null) !== null)
				{
					$statuses[$key] = self::PARTICIPATED;

					continue 2;
				}
				$allHaveParticipantRow = $allHaveParticipantRow && $user !== null;
				$allFinished = $allFinished
					&& (string)($call['STATE'] ?? '') === Call::STATE_FINISHED
					&& ($call['END_DATE'] ?? null) !== null
				;
			}

			if (!$hasAmbiguousCall[$key] && $allFinished && $allHaveParticipantRow)
			{
				$statuses[$key] = self::NOT_PARTICIPATED;
			}
		}

		return $statuses;
	}

	private function timestamp(mixed $value): ?int
	{
		if ($value instanceof DateTime || $value instanceof \DateTimeInterface)
		{
			return $value->getTimestamp();
		}

		return null;
	}
}
