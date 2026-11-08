<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Repository\Application;

final class AppRowCache
{
	private const IDENTIFIER_FIELDS = ['ID', 'CLIENT_ID', 'CODE'];

	/**
	 * @var array<string, ?array<string, mixed>>
	 */
	private static array $rows = [];

	/**
	 * @param \Closure(): ?array<string, mixed> $read
	 * @return ?array<string, mixed>
	 */
	public static function remember(string $ormField, int|string $value, \Closure $read): ?array
	{
		$key = self::key($ormField, $value);

		if (array_key_exists($key, self::$rows))
		{
			return self::$rows[$key];
		}

		$row = $read();
		self::$rows[$key] = $row;

		if ($row !== null)
		{
			foreach (self::rowKeys($row) as $rowKey)
			{
				self::$rows[$rowKey] = $row;
			}
		}

		return $row;
	}

	public static function forget(int|string|null ...$values): void
	{
		foreach ($values as $value)
		{
			self::forgetValue($value);
		}
	}

	public static function flush(): void
	{
		self::$rows = [];
	}

	private static function forgetValue(int|string|null $value): void
	{
		if ($value === null || $value === '')
		{
			return;
		}

		foreach (self::IDENTIFIER_FIELDS as $ormField)
		{
			$key = self::key($ormField, $value);
			$row = self::$rows[$key] ?? null;

			unset(self::$rows[$key]);

			if ($row === null)
			{
				continue;
			}

			foreach (self::rowKeys($row) as $rowKey)
			{
				unset(self::$rows[$rowKey]);
			}
		}
	}

	/**
	 * @param array<string, mixed> $row
	 * @return list<string>
	 */
	private static function rowKeys(array $row): array
	{
		$keys = [];

		foreach (self::IDENTIFIER_FIELDS as $ormField)
		{
			$value = $row[$ormField] ?? null;

			if ($value === null || $value === '')
			{
				continue;
			}

			$keys[] = self::key($ormField, (string)$value);
		}

		return $keys;
	}

	private static function key(string $ormField, int|string $value): string
	{
		return $ormField . '=' . $value;
	}
}
