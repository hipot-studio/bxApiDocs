<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Internals\Counter;

/**
 * Keeps comment counter values already read for the user in this hit, as [userId][taskId] => value.
 *
 * The values mirror rows of the counter table, so they are part of the counter cache of the user and must be
 * dropped wherever that cache is dropped: a memo outliving a reset would turn the reset into a no-op for
 * everyone reading through it.
 */
class CommentsCountCache
{
	/** @var array<int, array<int, int>> */
	private static array $values = [];

	/**
	 * @param int[] $taskIds
	 * @return array<int, int> values known for this hit only, tasks never read are absent from the result
	 */
	public static function get(int $userId, array $taskIds): array
	{
		$known = self::$values[$userId] ?? [];
		if (empty($known))
		{
			return [];
		}

		return array_intersect_key($known, array_fill_keys($taskIds, true));
	}

	/**
	 * @param array<int, int> $values
	 */
	public static function set(int $userId, array $values): void
	{
		foreach ($values as $taskId => $value)
		{
			self::$values[$userId][(int)$taskId] = (int)$value;
		}
	}

	public static function drop(int $userId): void
	{
		unset(self::$values[$userId]);
	}

	public static function dropAll(): void
	{
		self::$values = [];
	}
}
