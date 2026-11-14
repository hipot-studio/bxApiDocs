<?php

declare(strict_types=1);

namespace Bitrix\Call\Service;

use Bitrix\Call\Model\CallTable;
use Bitrix\Call\Model\CallUserLogTable;

/**
 * Which side of a call of the portal the owner of a journal row is on.
 *
 * The side is told by the creator of the call rather than by the status of the row: everybody who enters
 * a group call gets the same status, and only one of them started it.
 */
final class CallLogDirectionResolver
{
	/**
	 * How many calls the memory of creators may hold before it is let go of whole.
	 *
	 * A hit touches a handful of calls. A process that outlives a hit - an agent, a console command - would
	 * otherwise keep every call it ever wrote a row about.
	 */
	private const CALL_INITIATOR_MEMORY_LIMIT = 1000;

	/**
	 * Creator of a call by call id, null when the call could not be read.
	 *
	 * Static rather than per instance: a change of state is announced by one event per participant, and
	 * every event builds its own resolver - a memory per instance would be empty every time, and a room of
	 * a thousand would pay a query per person. The creator of a call does not change once it is made.
	 *
	 * @var array<int, int|null>
	 */
	private static array $callInitiators = [];

	/**
	 * The creator of the call is on the outgoing side, everybody else on the incoming one. A call that
	 * cannot be read any more is answered by the status, the way the journal has always read such a row.
	 */
	public function forCallParticipant(int $callId, int $userId, string $status): string
	{
		$initiatorId = $this->getCallInitiatorId($callId);
		if ($initiatorId === null)
		{
			return $this->fromStatus($status);
		}

		return $initiatorId === $userId
			? CallUserLogTable::DIRECTION_OUTGOING
			: CallUserLogTable::DIRECTION_INCOMING
		;
	}

	/**
	 * The side a status stands for: the rule the journal answered by before it kept a side.
	 */
	public function fromStatus(string $status): string
	{
		return $status === CallUserLogTable::STATUS_INITIATED
			? CallUserLogTable::DIRECTION_OUTGOING
			: CallUserLogTable::DIRECTION_INCOMING
		;
	}

	/**
	 * Drops the memory of creators.
	 *
	 * @internal For tests only: they run in random order within one process, and a creator remembered by
	 * one of them would answer for the call of another.
	 */
	public static function forgetInitiators(): void
	{
		self::$callInitiators = [];
	}

	private function getCallInitiatorId(int $callId): ?int
	{
		if ($callId <= 0)
		{
			return null;
		}

		if (!array_key_exists($callId, self::$callInitiators))
		{
			if (count(self::$callInitiators) >= self::CALL_INITIATOR_MEMORY_LIMIT)
			{
				self::$callInitiators = [];
			}

			$row = CallTable::getList([
				'select' => ['INITIATOR_ID'],
				'filter' => ['=ID' => $callId],
				'limit' => 1,
			])->fetch();

			$initiatorId = $row ? (int)$row['INITIATOR_ID'] : 0;
			self::$callInitiators[$callId] = $initiatorId > 0 ? $initiatorId : null;
		}

		return self::$callInitiators[$callId];
	}
}
