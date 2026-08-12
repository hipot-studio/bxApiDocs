<?php

namespace Bitrix\BIConnector\DB;

class MysqliSqlHelper extends \Bitrix\Main\DB\MysqliSqlHelper implements BiSqlHelperInterface
{
	public function getSessionTimezoneExpression(): string
	{
		return '@@session.time_zone';
	}

	public function convertTimezone(string $field, string $fromTimezone, string $toTimezone): string
	{
		if ($fromTimezone === $this->getSessionTimezoneExpression())
		{
			$targetOffsetInSeconds = $this->convertTimezoneOffsetToSeconds($toTimezone);
			$sessionOffsetInSeconds = 'TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW())';

			return sprintf(
				'DATE_ADD(%s, INTERVAL (%d - %s) SECOND)',
				$field,
				$targetOffsetInSeconds,
				$sessionOffsetInSeconds
			);
		}

		return sprintf(
			"CONVERT_TZ(%s, %s, '%s')",
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
			return "GROUP_CONCAT({$expression} ORDER BY {$orderBy} SEPARATOR '{$escapedSeparator}')";
		}

		return "GROUP_CONCAT({$expression} SEPARATOR '{$escapedSeparator}')";
	}

	public function getSegmentByDelimiter(string $field, string $delimiter, int $n): string
	{
		if ($n < 1)
		{
			throw new \InvalidArgumentException("Segment index must be >= 1, got {$n}.");
		}

		$escapedDelimiter = $this->forSql($delimiter);
		$delimLen = mb_strlen($delimiter, 'UTF-8');

		// Mirror PostgreSQL split_part: NULL field -> NULL, missing N-th segment -> ''.
		return "IF({$field} IS NULL, NULL, IF("
			. "(CHAR_LENGTH({$field}) - CHAR_LENGTH(REPLACE({$field}, '{$escapedDelimiter}', ''))) / {$delimLen} >= " . ($n - 1)
			. ", SUBSTRING_INDEX(SUBSTRING_INDEX({$field}, '{$escapedDelimiter}', {$n}), '{$escapedDelimiter}', -1)"
			. ", ''))"
		;
	}

	public function getDateDiffSecondsExpression(string $start, string $end): string
	{
		return "TIMESTAMPDIFF(SECOND, {$start}, {$end})";
	}

	public function castToInt(string $expr): string
	{
		// MySQL coerces text to integer implicitly in numeric comparisons - no SQL change needed.
		return $expr;
	}

	public function castIntToChar(string $expr): string
	{
		// MySQL coerces int to text implicitly in comparisons - no SQL change needed.
		return $expr;
	}

	public function castNumericToChar(string $expr): string
	{
		// MySQL coerces numeric to text implicitly in string functions - no SQL change needed.
		return $expr;
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
