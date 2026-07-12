<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\EventLog;

use Bitrix\Im\Model\EventLogTable;
use Bitrix\Im\V2\Common\PeriodAgentTrait;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Json;

class EventService
{
	use PeriodAgentTrait;

	private const EXPIRY_HOURS = 24;
	private const CLEAN_LIMIT = 1000;
	private const AGENT_SHORT_PERIOD = 60;
	private const AGENT_LONG_PERIOD = 3600;

	private PendingCache $pendingCache;

	public function __construct(?PendingCache $pendingCache = null)
	{
		$this->pendingCache = $pendingCache ?? new PendingCache();
	}

	public function fetchEventsForRest(array $userIds, int $offset = 0, int $limit = 100): array
	{
		$limit = min(1000, max(1, $limit));
		$events = $this->fetchEvents($userIds, $offset, $limit);

		$formattedEvents = [];
		$nextOffset = $offset;
		foreach ($events as $event)
		{
			$formattedEvents[] = [
				'eventId' => $event['id'],
				'type' => $event['eventType'],
				'date' => $event['dateCreate'] instanceof DateTime
					? $event['dateCreate']->format('c')
					: (string)$event['dateCreate'],
				'data' => $event['eventData'],
			];

			$nextOffset = max($nextOffset, $event['id'] + 1);
		}

		return [
			'events' => $formattedEvents,
			'nextOffset' => $nextOffset,
			'hasMore' => count($events) >= $limit,
		];
	}

	public function fetchEvents(array $userIds, int $offset = 0, int $limit = 100): array
	{
		if (empty($userIds))
		{
			return [];
		}

		$offset = max(0, $offset);
		$limit = min(1000, max(1, $limit));

		if ($offset > 0)
		{
			EventLogTable::deleteBatch(['=USER_ID' => $userIds, '<ID' => $offset]);
			foreach ($userIds as $userId)
			{
				$this->pendingCache->invalidate($userId);
			}
		}

		$allCached = true;
		foreach ($userIds as $userId)
		{
			if (!$this->pendingCache->has($userId))
			{
				$allCached = false;
				break;
			}
		}

		if ($allCached)
		{
			return [];
		}

		$query = EventLogTable::query()
			->setSelect(['ID', 'EVENT_TYPE', 'EVENT_DATA', 'DATE_CREATE'])
			->whereIn('USER_ID', $userIds)
			->setOrder(['ID' => 'ASC'])
			->setLimit($limit)
		;

		if ($offset > 0)
		{
			$query->where('ID', '>=', $offset);
		}

		$rows = $query->fetchAll();

		if (empty($rows))
		{
			foreach ($userIds as $userId)
			{
				$this->pendingCache->set($userId);
			}

			return [];
		}

		$result = [];
		foreach ($rows as $row)
		{
			$result[] = [
				'id' => (int)$row['ID'],
				'eventType' => $row['EVENT_TYPE'],
				'eventData' => Json::decode($row['EVENT_DATA']),
				'dateCreate' => $row['DATE_CREATE'],
			];
		}

		return $result;
	}

	public static function cleanAgent(): string
	{
		$expireDate = new DateTime();
		$expireDate->add('-' . self::EXPIRY_HOURS . ' hours');

		$rows = EventLogTable::query()
			->setSelect(['ID'])
			->where('DATE_CREATE', '<', $expireDate)
			->setLimit(self::CLEAN_LIMIT)
			->fetchAll()
		;

		$ids = array_column($rows, 'ID');
		if (!empty($ids))
		{
			EventLogTable::deleteBatch(['=ID' => $ids]);
		}

		self::calculateAndSetPeriod(true, count($ids) >= self::CLEAN_LIMIT);

		return self::getAgentName();
	}

	private static function getAgentName(): string
	{
		return 'Bitrix\Im\V2\EventLog\EventService::cleanAgent();';
	}

	private static function calculateAndSetPeriod(bool $fromAgent, bool $hasMore): void
	{
		self::setPeriodByName(
			$fromAgent,
			self::getAgentName(),
			static fn () => $hasMore ? self::AGENT_SHORT_PERIOD : self::AGENT_LONG_PERIOD
		);
	}

	protected static function isAgentPeriodShort(int $newPeriod): bool
	{
		return $newPeriod === self::AGENT_SHORT_PERIOD;
	}
}
