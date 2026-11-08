<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Im;

use Bitrix\Im\Model\RelationTable;
use Bitrix\Im\V2\Relation\Reason;

final class CompatChatRelations extends ChatRelations
{
	private const MAX_PAGE_SIZE = 1000;
	private const IDS_CHUNK_SIZE = 500;

	/**
	 * @param int[] $userIds
	 * @return array<int, ChatMember>
	 */
	public function getMembers(int $chatId, array $userIds): array
	{
		$userIds = self::normalizeIds($userIds);
		if ($chatId <= 0 || $userIds === [])
		{
			return [];
		}

		$members = [];
		foreach (array_chunk($userIds, self::IDS_CHUNK_SIZE) as $chunk)
		{
			$rows = RelationTable::query()
				->setSelect(['USER_ID', 'REASON', 'IS_HIDDEN'])
				->where('CHAT_ID', $chatId)
				->whereIn('USER_ID', $chunk)
				->fetchAll()
			;
			foreach ($rows as $row)
			{
				$members[(int)$row['USER_ID']] = self::toMember($row);
			}
		}

		return $members;
	}

	/**
	 * @return int[]
	 */
	public function getMemberIdsAfter(int $chatId, int $afterUserId, int $limit): array
	{
		if ($chatId <= 0 || $limit <= 0)
		{
			return [];
		}

		$rows = RelationTable::query()
			->setSelect(['USER_ID'])
			->where('CHAT_ID', $chatId)
			->where('USER_ID', '>', max(0, $afterUserId))
			->setOrder(['USER_ID' => 'ASC'])
			->setLimit(min($limit, self::MAX_PAGE_SIZE))
			->fetchAll()
		;

		return array_map(static fn (array $row): int => (int)$row['USER_ID'], $rows);
	}

	/**
	 * @param int[] $chatIds
	 * @return array<int, ChatMember>
	 */
	public function getUserMemberships(int $userId, array $chatIds): array
	{
		$chatIds = self::normalizeIds($chatIds);
		if ($userId <= 0 || $chatIds === [])
		{
			return [];
		}

		$memberships = [];
		foreach (array_chunk($chatIds, self::IDS_CHUNK_SIZE) as $chunk)
		{
			$rows = RelationTable::query()
				->setSelect(['CHAT_ID', 'REASON', 'IS_HIDDEN'])
				->where('USER_ID', $userId)
				->whereIn('CHAT_ID', $chunk)
				->fetchAll()
			;
			foreach ($rows as $row)
			{
				$memberships[(int)$row['CHAT_ID']] = self::toMember($row);
			}
		}

		return $memberships;
	}

	private static function toMember(array $row): ChatMember
	{
		return new ChatMember(
			Reason::tryFrom((string)$row['REASON']) ?? Reason::DEFAULT,
			$row['IS_HIDDEN'] === 'Y' || $row['IS_HIDDEN'] === true,
		);
	}

	/**
	 * @return int[]
	 */
	private static function normalizeIds(array $ids): array
	{
		return array_values(array_unique(array_filter(
			array_map('intval', $ids),
			static fn (int $id): bool => $id > 0,
		)));
	}
}
