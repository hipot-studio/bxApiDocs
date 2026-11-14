<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Public\Provider\Presence;

use Bitrix\Main\ArgumentException;

/**
 * The cursor of a portion of the presence list: the sort key of the last row the portion gave, opaque to the client.
 *
 * The key knows nothing of the tab or the day, so a cursor reads in any tab, and the next portion starts right after
 * its row whatever became of the employee since.
 */
final class PresenceCursor
{
	/**
	 * The longest cursor read. The key of a row is its id and names of b_user, 50 characters each at the most: the
	 * last name and the name, or else the name the row shows alone - the second name in the name format of the site,
	 * or the login. JSON spells a character in six bytes at the most (a control one as \u001f), so the JSON of the
	 * longest key stays under 630 bytes and its base64url under 840 characters. A kilobyte lets the cursor of every
	 * row through; a longer one is refused before it is decoded, as every row of the tab is compared with the key at
	 * the price of its length.
	 */
	private const MAX_LENGTH = 1024;
	private const BASE64URL = '/^[A-Za-z0-9_-]+$/D';
	private const KEY_DEPTH = 2;

	/**
	 * @return string base64url of the JSON of the sort key of the row, without padding
	 */
	public static function of(PresenceIndexRow $row): string
	{
		$json = json_encode(
			$row->sortKey(),
			JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
		);

		return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
	}

	/**
	 * @return array{0: string, 1: string, 2: int} the sort key the cursor was made of
	 * @throws ArgumentException when the cursor was not made by {@see of()}
	 */
	public static function read(string $cursor): array
	{
		if (strlen($cursor) > self::MAX_LENGTH)
		{
			throw new ArgumentException('The cursor is longer than any cursor of the list', 'cursor');
		}

		$json = preg_match(self::BASE64URL, $cursor) === 1 ? base64_decode(strtr($cursor, '-_', '+/'), true) : false;
		if ($json === false)
		{
			throw new ArgumentException('The cursor is not base64url', 'cursor');
		}

		try
		{
			$key = json_decode($json, false, self::KEY_DEPTH, JSON_THROW_ON_ERROR);
		}
		catch (\JsonException $exception)
		{
			throw new ArgumentException('The cursor is not JSON of a sort key', 'cursor', $exception);
		}

		if (!self::isSortKey($key))
		{
			throw new ArgumentException('The cursor is not a sort key', 'cursor');
		}

		return $key;
	}

	private static function isSortKey(mixed $key): bool
	{
		return
			is_array($key)
			&& array_is_list($key)
			&& count($key) === 3
			&& is_string($key[0])
			&& is_string($key[1])
			&& is_int($key[2])
		;
	}
}
