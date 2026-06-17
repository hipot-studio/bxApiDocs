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
