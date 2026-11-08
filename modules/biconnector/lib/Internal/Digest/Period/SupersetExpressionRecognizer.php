<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Digest\Period;

use Bitrix\BIConnector\Internal\Digest\DigestPeriodSelection;

/**
 * Recognizes a limited part of the Superset time range grammar on the portal, without a call to
 * Superset: calendar tokens (`current calendar month`, `previous calendar week`), rolling ones (`Last month`),
 * relative expressions of the `DATEADD/DATETRUNC/DATETIME("now")` family and absolute pairs of dates.
 *
 * An expression is matched against the canonical expression of each manual entry before it is brought
 * to dates. `now` and `today` count as the same anchor for that match only: they differ by the part of
 * the current day, not by the period meant. The end of a Superset range is exclusive, so the inclusive
 * end of a fixed range is the day before a midnight end and the very day of an end carrying a time.
 */
final class SupersetExpressionRecognizer implements ScreenPeriodRecognizer
{
	private const SEPARATOR_PATTERN = '/\s+:\s+/';
	private const DATE_PATTERN = '/^(\d{4}-\d{2}-\d{2})(?:[T ](\d{2}:\d{2}:\d{2}))?$/';
	private const MIDNIGHT = '00:00:00';
	private const MAX_SHIFT = 10000;

	private const UNITS = [
		'day' => 'day',
		'days' => 'day',
		'week' => 'week',
		'weeks' => 'week',
		'month' => 'month',
		'months' => 'month',
		'quarter' => 'quarter',
		'quarters' => 'quarter',
		'year' => 'year',
		'years' => 'year',
	];

	private const MONTHS_IN_UNIT = [
		'month' => 1,
		'quarter' => 3,
		'year' => 12,
	];

	/**
	 * Canonical expression => code of the manual entry it stands for: the calendar periods of the global
	 * BI setting, as the portal embeds them ({@see \Bitrix\BIConnector\Superset\Dashboard\EmbeddedFilter\DateTime})
	 * and as the date control of Superset names them.
	 *
	 * A rolling expression has no entry and is transferred as the dates it covers. `Last week` is one of them:
	 * Superset reads it as `DATEADD(today, -1, week) : today`, the seven days before today, and the calendar
	 * previous week is `previous calendar week`.
	 */
	private const PRESET_EXPRESSIONS = [
		'datetrunc(today,week):datetrunc(dateadd(today,1,week),week)' => 'current_week',
		'datetrunc(today,month):datetrunc(dateadd(today,1,month),month)' => 'current_month',
		'datetrunc(today,year):datetrunc(dateadd(today,1,year),year)' => 'current_year',
		'datetrunc(dateadd(today,-1,week),week):datetrunc(today,week)' => 'previous_week',
		'datetrunc(dateadd(today,-1,month),month):datetrunc(today,month)' => 'previous_month',
		'datetrunc(dateadd(today,-1,year),year):datetrunc(today,year)' => 'previous_year',
	];

	private readonly \DateTimeImmutable $today;

	/**
	 * @param \DateTimeImmutable|null $today The day relative expressions count from; the current day by default.
	 */
	public function __construct(?\DateTimeImmutable $today = null)
	{
		$this->today = self::toDate(($today ?? new \DateTimeImmutable('today'))->format('Y-m-d'));
	}

	public function recognize(string $timeRange): ?DigestPeriodSelection
	{
		$expression = trim($timeRange);
		$expression = self::expandToken($expression) ?? $expression;

		$sides = preg_split(self::SEPARATOR_PATTERN, $expression);
		if ($sides === false || count($sides) !== 2)
		{
			return null;
		}

		$start = $this->parseSide($sides[0]);
		$end = $this->parseSide($sides[1]);
		if ($start === null || $end === null)
		{
			return null;
		}

		if ($start['isRelative'] || $end['isRelative'])
		{
			$presetCode = self::PRESET_EXPRESSIONS[$start['canonical'] . ':' . $end['canonical']] ?? null;
			if ($presetCode !== null)
			{
				return DigestPeriodSelection::fromStored($presetCode);
			}
		}

		return $this->makeFixedRange($start, $end);
	}

	/**
	 * Calendar tokens are shorthands of relative expressions, so they are matched and computed as those.
	 */
	private static function expandToken(string $token): ?string
	{
		$token = strtolower($token);

		if (preg_match('/^current (?:calendar )?(week|month|quarter|year)$/', $token, $matches))
		{
			$unit = $matches[1];

			return "DATETRUNC(today, {$unit}) : DATETRUNC(DATEADD(today, 1, {$unit}), {$unit})";
		}

		if ($token === 'current day')
		{
			return 'today : DATEADD(today, 1, day)';
		}

		if (preg_match('/^previous (?:calendar )?(week|month|quarter|year)$/', $token, $matches))
		{
			$unit = $matches[1];

			return "DATETRUNC(DATEADD(today, -1, {$unit}), {$unit}) : DATETRUNC(today, {$unit})";
		}

		// Rolling, not calendar: `Last week` is the seven days before today, as Superset counts it.
		if (preg_match('/^last (day|week|month|quarter|year)$/', $token, $matches))
		{
			return "DATEADD(today, -1, {$matches[1]}) : today";
		}

		return null;
	}

	/**
	 * @param array{date: \DateTimeImmutable, hasTime: bool} $start
	 * @param array{date: \DateTimeImmutable, hasTime: bool} $end
	 */
	private function makeFixedRange(array $start, array $end): ?DigestPeriodSelection
	{
		$inclusiveEnd = $end['hasTime'] ? $end['date'] : $end['date']->modify('-1 day');

		$from = $start['date']->format('Y-m-d');
		$to = $inclusiveEnd->format('Y-m-d');
		if (!self::isPlainDate($from) || !self::isPlainDate($to) || $from > $to)
		{
			return null;
		}

		return DigestPeriodSelection::fromBounds(['from' => $from, 'to' => $to]);
	}

	/**
	 * @return array{date: \DateTimeImmutable, hasTime: bool, isRelative: bool, canonical: string}|null
	 */
	private function parseSide(string $side): ?array
	{
		$tokens = self::tokenize($side);
		if ($tokens === null)
		{
			return null;
		}

		$position = 0;
		$node = $this->parseExpression($tokens, $position);

		return $position === count($tokens) ? $node : null;
	}

	/**
	 * @return list<array{0: string, 1: string}>|null Pairs of token type and value; null on a foreign character.
	 */
	private static function tokenize(string $side): ?array
	{
		$patterns = [
			'date' => '\d{4}-\d{2}-\d{2}(?:T\d{2}:\d{2}:\d{2})?',
			'int' => '[+-]?\d+',
			'ident' => '[A-Za-z_]+',
			'string' => '"[^"]*"|\'[^\']*\'',
			'punct' => '[(),]',
		];

		$tokens = [];
		$offset = 0;
		$length = strlen($side);
		while ($offset < $length)
		{
			if (preg_match('/\G\s+/', $side, $matches, 0, $offset))
			{
				$offset += strlen($matches[0]);

				continue;
			}

			$matched = false;
			foreach ($patterns as $type => $pattern)
			{
				if (preg_match('/\G(?:' . $pattern . ')/', $side, $matches, 0, $offset))
				{
					$value = $matches[0];
					$tokens[] = match ($type)
					{
						'ident' => [$type, strtolower($value)],
						'string' => [$type, substr($value, 1, -1)],
						default => [$type, $value],
					};
					$offset += strlen($value);
					$matched = true;

					break;
				}
			}

			if (!$matched)
			{
				return null;
			}
		}

		return $tokens;
	}

	/**
	 * @param list<array{0: string, 1: string}> $tokens
	 *
	 * @return array{date: \DateTimeImmutable, hasTime: bool, isRelative: bool, canonical: string}|null
	 */
	private function parseExpression(array $tokens, int &$position): ?array
	{
		[$type, $value] = $tokens[$position] ?? [null, null];
		$position++;

		if ($type === 'date')
		{
			return self::makeLiteral($value);
		}

		if ($type !== 'ident')
		{
			return null;
		}

		return match ($value)
		{
			'now', 'today' => $this->makeAnchor($value),
			'datetime' => $this->parseDatetime($tokens, $position),
			'dateadd' => $this->parseDateadd($tokens, $position),
			'datetrunc' => $this->parseDatetrunc($tokens, $position),
			default => null,
		};
	}

	/**
	 * @param list<array{0: string, 1: string}> $tokens
	 */
	private function parseDatetime(array $tokens, int &$position): ?array
	{
		if (!self::expect($tokens, $position, 'punct', '('))
		{
			return null;
		}

		[$type, $argument] = $tokens[$position++] ?? [null, null];
		if ($type !== 'string' || !self::expect($tokens, $position, 'punct', ')'))
		{
			return null;
		}

		$argument = strtolower(trim($argument));

		return in_array($argument, ['now', 'today'], true)
			? $this->makeAnchor($argument)
			: self::makeLiteral($argument)
		;
	}

	/**
	 * @param list<array{0: string, 1: string}> $tokens
	 */
	private function parseDateadd(array $tokens, int &$position): ?array
	{
		if (!self::expect($tokens, $position, 'punct', '('))
		{
			return null;
		}

		$operand = $this->parseExpression($tokens, $position);
		if ($operand === null || !self::expect($tokens, $position, 'punct', ','))
		{
			return null;
		}

		[$type, $shift] = $tokens[$position++] ?? [null, null];
		if ($type !== 'int' || abs((int)$shift) > self::MAX_SHIFT || !self::expect($tokens, $position, 'punct', ','))
		{
			return null;
		}

		$unit = self::parseUnit($tokens, $position);
		if ($unit === null || !self::expect($tokens, $position, 'punct', ')'))
		{
			return null;
		}

		$shift = (int)$shift;
		$date = self::shift($operand['date'], $shift, $unit);
		if ($date === null)
		{
			return null;
		}

		return [
			'date' => $date,
			'hasTime' => $operand['hasTime'],
			'isRelative' => $operand['isRelative'],
			'canonical' => "dateadd({$operand['canonical']},{$shift},{$unit})",
		];
	}

	/**
	 * @param list<array{0: string, 1: string}> $tokens
	 */
	private function parseDatetrunc(array $tokens, int &$position): ?array
	{
		if (!self::expect($tokens, $position, 'punct', '('))
		{
			return null;
		}

		$operand = $this->parseExpression($tokens, $position);
		if ($operand === null || !self::expect($tokens, $position, 'punct', ','))
		{
			return null;
		}

		$unit = self::parseUnit($tokens, $position);
		if ($unit === null || !self::expect($tokens, $position, 'punct', ')'))
		{
			return null;
		}

		return [
			'date' => self::truncate($operand['date'], $unit),
			'hasTime' => false,
			'isRelative' => $operand['isRelative'],
			'canonical' => "datetrunc({$operand['canonical']},{$unit})",
		];
	}

	/**
	 * @param list<array{0: string, 1: string}> $tokens
	 */
	private static function parseUnit(array $tokens, int &$position): ?string
	{
		[$type, $value] = $tokens[$position++] ?? [null, null];

		return $type === 'ident' ? (self::UNITS[$value] ?? null) : null;
	}

	/**
	 * @param list<array{0: string, 1: string}> $tokens
	 */
	private static function expect(array $tokens, int &$position, string $type, string $value): bool
	{
		$token = $tokens[$position] ?? null;
		if ($token !== [$type, $value])
		{
			return false;
		}

		$position++;

		return true;
	}

	/**
	 * `now` is the current moment, so as an end it takes the current day in; `today` is its midnight.
	 */
	private function makeAnchor(string $anchor): array
	{
		return [
			'date' => $this->today,
			'hasTime' => $anchor === 'now',
			'isRelative' => true,
			'canonical' => 'today',
		];
	}

	private static function makeLiteral(string $value): ?array
	{
		if (!preg_match(self::DATE_PATTERN, $value, $matches) || !self::isPlainDate($matches[1]))
		{
			return null;
		}

		$time = $matches[2] ?? self::MIDNIGHT;

		return [
			'date' => self::toDate($matches[1]),
			'hasTime' => $time !== self::MIDNIGHT,
			'isRelative' => false,
			'canonical' => $matches[1] . 'T' . $time,
		];
	}

	private static function shift(\DateTimeImmutable $date, int $shift, string $unit): ?\DateTimeImmutable
	{
		if ($unit === 'day' || $unit === 'week')
		{
			$days = $unit === 'week' ? $shift * 7 : $shift;

			return $date->modify(sprintf('%+d days', $days));
		}

		// Superset shifts months the way relativedelta does: the day is clamped to the end of the month.
		$monthIndex = (int)$date->format('Y') * 12 + (int)$date->format('n') - 1 + $shift * self::MONTHS_IN_UNIT[$unit];
		$year = intdiv($monthIndex, 12);
		$month = $monthIndex % 12 + 1;
		if ($monthIndex < 12 || $year > 9999)
		{
			return null;
		}

		$firstDay = self::toDate(sprintf('%04d-%02d-01', $year, $month));
		$day = min((int)$date->format('j'), (int)$firstDay->format('t'));

		return $firstDay->setDate($year, $month, $day);
	}

	private static function truncate(\DateTimeImmutable $date, string $unit): \DateTimeImmutable
	{
		$year = (int)$date->format('Y');
		$month = (int)$date->format('n');

		return match ($unit)
		{
			'day' => $date,
			'week' => $date->modify(sprintf('-%d days', (int)$date->format('N') - 1)),
			'month' => $date->setDate($year, $month, 1),
			'quarter' => $date->setDate($year, intdiv($month - 1, 3) * 3 + 1, 1),
			'year' => $date->setDate($year, 1, 1),
		};
	}

	private static function isPlainDate(string $date): bool
	{
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date))
		{
			return false;
		}

		$parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));

		return $parsed !== false && $parsed->format('Y-m-d') === $date;
	}

	private static function toDate(string $date): \DateTimeImmutable
	{
		return new \DateTimeImmutable($date, new \DateTimeZone('UTC'));
	}
}
