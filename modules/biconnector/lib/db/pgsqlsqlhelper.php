<?php

namespace Bitrix\BIConnector\DB;

class PgsqlSqlHelper extends \Bitrix\Main\DB\PgsqlSqlHelper implements BiSqlHelperInterface
{
	public function quote($identifier)
	{
		// Quote each dot-separated segment like the core MySQL helper (alias.column ->
		// "alias"."column"); stripping existing quotes first keeps it idempotent.
		$identifier = str_replace('"', '', (string)$identifier);
		$segments = explode('.', $identifier);
		foreach ($segments as $i => $segment)
		{
			$segments[$i] = parent::quote($segment);
		}

		return implode('.', $segments);
	}

	public function getSessionTimezoneExpression(): string
	{
		return "current_setting('TimeZone')";
	}

	public function convertTimezone(string $field, string $fromTimezone, string $toTimezone): string
	{
		if ($fromTimezone === $this->getSessionTimezoneExpression())
		{
			// Bitrix stores datetime in the application server local time and does not set the PG
			// session timezone, so current_setting('TimeZone') is unreliable here. We compute the
			// shift in PHP: target offset minus the application server offset, then add it as an
			// interval. make_interval(secs => ...) avoids the POSIX sign inversion of a bare
			// "AT TIME ZONE '+03:00'" literal.
			$targetOffsetInSeconds = $this->convertTimezoneOffsetToSeconds($toTimezone);
			$appOffsetInSeconds = (new \DateTime('now'))->getOffset();
			$deltaSeconds = $targetOffsetInSeconds - $appOffsetInSeconds;

			return sprintf(
				'(%s::timestamp + make_interval(secs => %d))',
				$field,
				$deltaSeconds
			);
		}

		return sprintf(
			"(%s::timestamp AT TIME ZONE %s) AT TIME ZONE '%s'",
			$field,
			$fromTimezone,
			$this->forSql($toTimezone)
		);
	}

	public function getGroupConcatExpression(string $expression, string $orderBy = '', string $separator = ','): string
	{
		$escapedSeparator = $this->forSql($separator);
		if ($orderBy !== '')
		{
			return "string_agg(({$expression})::text, '{$escapedSeparator}' ORDER BY {$orderBy})";
		}

		return "string_agg(({$expression})::text, '{$escapedSeparator}')";
	}

	public function getSegmentByDelimiter(string $field, string $delimiter, int $n): string
	{
		if ($n < 1)
		{
			throw new \InvalidArgumentException("Segment index must be >= 1, got {$n}.");
		}

		$escapedDelimiter = $this->forSql($delimiter);

		return "split_part({$field}, '{$escapedDelimiter}', {$n})";
	}

	public function getDateDiffSecondsExpression(string $start, string $end): string
	{
		return "TRUNC(EXTRACT(EPOCH FROM (({$end})::timestamp - ({$start})::timestamp)))::int";
	}

	public function castToInt(string $expr): string
	{
		// NULLIF guard prevents "invalid input syntax for integer" when $expr evaluates to an empty string.
		return "CAST(NULLIF(({$expr}), '') AS integer)";
	}

	public function castIntToChar(string $expr): string
	{
		return "CAST({$expr} AS varchar)";
	}

	public function castNumericToChar(string $expr): string
	{
		return "CAST({$expr} AS varchar)";
	}

	private function convertTimezoneOffsetToSeconds(string $timezoneOffset): int
	{
		if (
			!preg_match(
				'/^(?<sign>[+-])(?<hours>\d{2}):(?<minutes>\d{2})$/',
				$timezoneOffset,
				$matches
			)
		)
		{
			throw new \InvalidArgumentException("Unsupported timezone offset: {$timezoneOffset}");
		}

		$seconds = ((int)$matches['hours'] * 3600) + ((int)$matches['minutes'] * 60);

		return $matches['sign'] === '-' ? -$seconds : $seconds;
	}
}
