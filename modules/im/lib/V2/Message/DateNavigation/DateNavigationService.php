<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\DateNavigation;

use Bitrix\Im\Model\MessageTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Result;
use Bitrix\Im\V2\TariffLimit\Limit;
use Bitrix\Main\Application;
use Bitrix\Main\ObjectException;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;

class DateNavigationService
{
	// Datepicker shows one month at a time, so a request covers the visible month plus a neighbour for prefetch.
	// Wider windows make the per-day GROUP BY scan the whole range (temp table + filesort) with no product need.
	public const MAX_CALENDAR_RANGE_DAYS = 62;

	private const DATE_FORMAT = 'Y-m-d';
	private const SECONDS_IN_DAY = 86400;

	private Chat $chat;
	private UserTimezone $userTimezone;
	private ?int $startId = null;
	private ?DateTime $tariffLimitDate = null;
	private bool $isTariffLimitResolved = false;

	public function __construct(Chat $chat, UserTimezone $userTimezone)
	{
		$this->chat = $chat;
		$this->userTimezone = $userTimezone;
	}

	/**
	 * The timezone identifier is the preferred input and wins over the offset when both are sent:
	 * it is the only one of the two that knows what the offset was on each date of the request.
	 * An identifier the server does not know is answered with null - taking the offset instead
	 * would answer a wrong day without anything telling the client the identifier was dropped.
	 */
	public static function createForClient(Chat $chat, ?string $timezoneId, ?int $clientUtcOffset): ?self
	{
		$userTimezone = ($timezoneId === null || $timezoneId === '')
			? UserTimezone::createByClientOffset($clientUtcOffset)
			: UserTimezone::createById($timezoneId)
		;

		return $userTimezone === null ? null : new self($chat, $userTimezone);
	}

	public static function parseDate(string $value): ?Date
	{
		try
		{
			$date = new Date($value, self::DATE_FORMAT);
		}
		catch (ObjectException)
		{
			return null;
		}

		// Round-trip check rejects overflowed dates silently normalized by the parser (e.g. 2026-02-31).
		return $date->format(self::DATE_FORMAT) === $value ? $date : null;
	}

	/**
	 * Returns dates (in user time) having at least one visible message within the range (inclusive),
	 * and chat message history boundaries for calendar navigation.
	 */
	public function getCalendar(Date $from, Date $to): Result
	{
		$result = new Result();

		$rangeInDays = (int)(($to->getTimestamp() - $from->getTimestamp()) / self::SECONDS_IN_DAY);
		if ($rangeInDays < 0 || $rangeInDays > self::MAX_CALENDAR_RANGE_DAYS)
		{
			return $result->addError(new DateNavigationError(DateNavigationError::WRONG_RANGE));
		}

		$segments = $this->userTimezone->getSegments(
			$this->userTimezone->getDayStart($from),
			$this->userTimezone->getDayStart((clone $to)->add('1 day')),
		);

		$days = [];
		foreach ($segments as $segment)
		{
			foreach ($this->getDaysOfSegment($segment) as $day)
			{
				$days[$day] = true;
			}
		}

		// the day a clock change falls on is answered by both parts around it, and the parts of a
		// range are asked for one by one, so the calendar is assembled instead of concatenated
		ksort($days, SORT_STRING);

		return $result->setResult(new Calendar(
			array_keys($days),
			$this->getBoundaryDate('ASC'),
			$this->getBoundaryDate('DESC'),
		));
	}

	/**
	 * Returns the first visible message of the requested date (in user time).
	 * If the date has no messages, falls forward to the nearest date with messages.
	 */
	public function resolveDate(Date $date): Result
	{
		$result = new Result();

		// the tiebreaker picks the row, and the row is the answer: messages of one second are told
		// apart by ID, otherwise the same date resolves into different messages from call to call
		$row = $this->getVisibleMessagesQuery()
			->setSelect(['ID', 'DATE_CREATE'])
			->where('DATE_CREATE', '>=', $this->userTimezone->getDayStart($date))
			->setOrder(['DATE_CREATE' => 'ASC', 'ID' => 'ASC'])
			->setLimit(1)
			->fetch()
		;

		if ($row === false)
		{
			return $result->addError(new DateNavigationError(DateNavigationError::MESSAGE_NOT_FOUND));
		}

		return $result->setResult(new ResolvedDate(
			(int)$row['ID'],
			$this->userTimezone->getDay($row['DATE_CREATE']),
		));
	}

	/**
	 * @return string[]
	 */
	private function getDaysOfSegment(TimeSegment $segment): array
	{
		$queryResult = $this->getVisibleMessagesQuery()
			->registerRuntimeField(
				new ExpressionField('MESSAGE_DAY', $this->getUserDayExpression($segment), 'DATE_CREATE')
			)
			->setSelect(['MESSAGE_DAY'])
			->where('DATE_CREATE', '>=', $segment->getFrom())
			->where('DATE_CREATE', '<', $segment->getTo())
			->setGroup(['MESSAGE_DAY'])
			->exec()
		;

		$days = [];
		while ($row = $queryResult->fetch())
		{
			$days[] = $this->formatDayValue($row['MESSAGE_DAY']);
		}

		return $days;
	}

	private function getVisibleMessagesQuery(): Query
	{
		$query = MessageTable::query()->where('CHAT_ID', $this->chat->getChatId());

		$startId = $this->startId ??= $this->chat->getStartId();
		if ($startId > 0)
		{
			$query->where('ID', '>=', $startId);
		}

		$tariffLimitDate = $this->getTariffLimitDate();
		if ($tariffLimitDate !== null)
		{
			$query->where('DATE_CREATE', '>=', $tariffLimitDate);
		}

		return $query;
	}

	private function getTariffLimitDate(): ?DateTime
	{
		if ($this->isTariffLimitResolved)
		{
			return $this->tariffLimitDate;
		}

		$this->isTariffLimitResolved = true;
		$limit = Limit::getInstance();
		$filterable = (new Message())->setChatId($this->chat->getChatId());

		if ($limit->shouldFilterByDate($filterable))
		{
			$this->tariffLimitDate = $limit->getLimitDate();
		}

		return $this->tariffLimitDate;
	}

	/**
	 * The answer is the day of the boundary message and never the message itself, so the rows sharing
	 * the boundary DATE_CREATE are interchangeable here and the order among them decides nothing -
	 * which is why nothing but DATE_CREATE is read. An ID tiebreaker would only cost the index path:
	 * measured on PostgreSQL, it turns 0.02-0.05 ms into 15.8-146 ms on chats of 200-310 thousand
	 * messages. Compare with resolveDate(), where the row picked IS the answer and the tiebreaker stays.
	 */
	private function getBoundaryDate(string $direction): ?string
	{
		$row = $this->getVisibleMessagesQuery()
			->setSelect(['DATE_CREATE'])
			->setOrder(['DATE_CREATE' => $direction])
			->setLimit(1)
			->fetch()
		;

		if ($row === false || !($row['DATE_CREATE'] instanceof DateTime))
		{
			return null;
		}

		return $this->userTimezone->getDay($row['DATE_CREATE']);
	}

	// The DB driver converts a native DATE column by result metadata: mysqli returns Type\Date, pgsql returns a string.
	private function formatDayValue(mixed $value): string
	{
		if ($value instanceof Date)
		{
			return $value->format(self::DATE_FORMAT);
		}

		return mb_substr((string)$value, 0, 10);
	}

	private function getUserDayExpression(TimeSegment $segment): string
	{
		$sqlHelper = Application::getConnection()->getSqlHelper();
		$userTimeOffset = $segment->getUserTimeOffset();

		if ($userTimeOffset === 0)
		{
			return $sqlHelper->getDatetimeToDateFunction('%s');
		}

		return $sqlHelper->getDatetimeToDateFunction(
			$sqlHelper->addSecondsToDateTime($userTimeOffset, '%s')
		);
	}
}
