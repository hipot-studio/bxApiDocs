<?php

namespace Bitrix\Mobile\Internal\Integration\Main;

use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlExpression;

/**
 * One user option, read for a whole selection of users at once.
 *
 * The read goes to the table rather than through CUserOptions::GetList(): its filter takes
 * one user id at a time, so a selection would cost either a query per user or a read of
 * every record of the option - and the latter grows with the number of users who ever had
 * a record, not with the selection the caller is about.
 *
 * The value is returned as stored. What a record means - a serialized timestamp, a
 * serialized payload - belongs to the option, not to the read, and stays with the caller.
 */
final class UserOptionReader
{
	private const OPTION_TABLE = 'b_user_option';

	/**
	 * How many users one query covers. A selection may be the whole portal, and the
	 * list of an IN has to stay a statement of a bounded size.
	 */
	public const CHUNK_SIZE = 500;

	/**
	 * Stored values of the option, keyed by user id. A user without a record is absent from
	 * the answer rather than present with an empty value.
	 *
	 * @param int[] $userIds
	 * @return array<int, string>
	 */
	public static function read(array $userIds, string $category, string $name): array
	{
		$values = [];

		foreach (array_chunk($userIds, self::CHUNK_SIZE) as $chunk)
		{
			$values += self::readChunk($chunk, $category, $name);
		}

		return $values;
	}

	/**
	 * @param int[] $userIds non-empty
	 * @return array<int, string>
	 */
	private static function readChunk(array $userIds, string $category, string $name): array
	{
		$values = [];
		$rows = Application::getConnection()->query(self::buildSql($userIds, $category, $name));
		while ($row = $rows->fetch())
		{
			$values[(int)$row['USER_ID']] = (string)$row['VALUE'];
		}

		return $values;
	}

	/**
	 * The statement of one chunk. The ids become numbers here rather than being trusted to
	 * arrive as ones: the list placeholder quotes every item it is given, and the ids come
	 * from callers who read them from the database, where a driver hands them out as strings.
	 * A quoted literal against an integer column is a silent cast on MySQL and an error on
	 * PostgreSQL as soon as one item is no number at all - and an error here fails the pass
	 * of the whole chunk, not the one user it came from.
	 *
	 * @param int[] $userIds non-empty
	 */
	private static function buildSql(array $userIds, string $category, string $name): string
	{
		return (new SqlExpression(
			'SELECT USER_ID, VALUE FROM ?# WHERE USER_ID IN (?@) AND CATEGORY = ?s AND NAME = ?s',
			self::OPTION_TABLE,
			array_map('intval', $userIds),
			mb_strtolower($category),
			mb_strtolower($name),
		))->compile();
	}
}
