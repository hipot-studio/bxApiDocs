<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Cli\Key;

use Bitrix\Main\Type\DateTime;

/**
 * Output layer shared by the key commands: the supported formats and the machine-readable shape
 * of a key row. Keeps the commands themselves on input parsing and printing only.
 */
final class KeyReport
{
	public const FORMAT_TABLE = 'table';
	public const FORMAT_JSON = 'json';

	public static function isKnownFormat(string $format): bool
	{
		return in_array($format, [self::FORMAT_TABLE, self::FORMAT_JSON], true);
	}

	public static function presentRow(array $row, bool $suspicious): array
	{
		return [
			'id' => (int)$row['ID'],
			'appId' => (int)$row['APP_ID'],
			'createdBy' => (int)$row['CREATED_BY'],
			'active' => (string)$row['ACTIVE'],
			'dateCreate' => self::formatDate($row['DATE_CREATE'] ?? null),
			'lastActivityDate' => self::formatDate($row['LAST_ACTIVITY_DATE'] ?? null),
			'connection' => (string)$row['CONNECTION'],
			'authorHasOperation' => (bool)$row['authorHasOperation'],
			'wasUsed' => (bool)$row['wasUsed'],
			'suspicious' => $suspicious,
		];
	}

	private static function formatDate(mixed $value): ?string
	{
		return $value instanceof DateTime ? $value->format('Y-m-d H:i:s') : ($value === null ? null : (string)$value);
	}
}
