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
	private const MAX_TIMEZONE_OFFSET = 50400;
	private const MIN_TIMEZONE_OFFSET = -43200;

	private Chat $chat;
	private int $userTimeOffset;
	private ?int $startId = null;
	private ?DateTime $tariffLimitDate = null;
	private bool $isTariffLimitResolved = false;

	public function __construct(Chat $chat, int $userTimeOffset)
	{
		$this->chat = $chat;
		$this->userTimeOffset = $userTimeOffset;
	}

	// Accepts the client UTC offset in seconds as the source of truth; falls back to the portal user timezone.
	public static function createForClientOffset(Chat $chat, ?int $clientUtcOffset): self
	{
		if ($clientUtcOffset === null)
		{
			return new self($chat, \CTimeZone::GetOffset());
		}

		$clientUtcOffset = max(min($clientUtcOffset, self::MAX_TIMEZONE_OFFSET), self::MIN_TIMEZONE_OFFSET);

		return new self($chat, $clientUtcOffset - (int)date('Z'));
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

		$queryResult = $this->getVisibleMessagesQuery()
			->registerRuntimeField(new ExpressionField('MESSAGE_DAY', $this->getUserDayExpression(), 'DATE_CREATE'))
			->setSelect(['MESSAGE_DAY'])
			->where('DATE_CREATE', '>=', $this->toServerTime($from))
			->where('DATE_CREATE', '<', $this->toServerTime((clone $to)->add('1 day')))
			->setGroup(['MESSAGE_DAY'])
			->setOrder(['MESSAGE_DAY' => 'ASC'])
			->exec()
		;

		$days = [];
		while ($row = $queryResult->fetch())
		{
			$days[] = $this->formatDayValue($row['MESSAGE_DAY']);
		}

		return $result->setResult(new Calendar(
			$days,
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

		$row = $this->getVisibleMessagesQuery()
			->setSelect(['ID', 'DATE_CREATE'])
			->where('DATE_CREATE', '>=', $this->toServerTime($date))
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
			$this->toUserDateString($row['DATE_CREATE']),
		));
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

	private function getBoundaryDate(string $direction): ?string
	{
		$row = $this->getVisibleMessagesQuery()
			->setSelect(['ID', 'DATE_CREATE'])
			->setOrder(['DATE_CREATE' => $direction, 'ID' => $direction])
			->setLimit(1)
			->fetch()
		;

		if ($row === false || !($row['DATE_CREATE'] instanceof DateTime))
		{
			return null;
		}

		return $this->toUserDateString($row['DATE_CREATE']);
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

	private function getUserDayExpression(): string
	{
		$sqlHelper = Application::getConnection()->getSqlHelper();

		if ($this->userTimeOffset === 0)
		{
			return $sqlHelper->getDatetimeToDateFunction('%s');
		}

		return $sqlHelper->getDatetimeToDateFunction(
			$sqlHelper->addSecondsToDateTime($this->userTimeOffset, '%s')
		);
	}

	// Day boundaries come from the user's wall clock, so the moment is shifted back to server time before comparing with DATE_CREATE.
	private function toServerTime(Date $date): DateTime
	{
		return DateTime::createFromTimestamp($date->getTimestamp() - $this->userTimeOffset);
	}

	private function toUserDateString(DateTime $dateTime): string
	{
		return DateTime::createFromTimestamp($dateTime->getTimestamp() + $this->userTimeOffset)->format(self::DATE_FORMAT);
	}
}
