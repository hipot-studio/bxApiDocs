<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Public\Provider\Member;

use Bitrix\Im\Model\RelationTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Common\Normalizer;
use Bitrix\Im\V2\Public\Dto\Member\ChatMemberItem;
use Bitrix\Im\V2\RelationCollection;

final class ChatMembersProvider
{
	private const MAX_PAGE_SIZE = 1000;
	private const CHAT_IDS_CHUNK_SIZE = 500;

	/**
	 * @param int[] $userIds
	 * @return array<int, ChatMemberItem> keyed by user id
	 */
	public function getRawByUserIds(int $chatId, array $userIds): array
	{
		$userIds = Normalizer::toUniquePositiveIntegers($userIds);
		if ($chatId <= 0 || $userIds === [])
		{
			return [];
		}

		$members = [];
		$relations = Chat::getInstance($chatId)->getRelationFacade()?->getRawByUserIds($userIds) ?? new RelationCollection();
		foreach ($relations as $relation)
		{
			$member = ChatMemberItem::fromRelation($relation);
			$members[$member->userId] = $member;
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

		return array_map(static fn(array $row): int => (int)$row['USER_ID'], $rows);
	}

	/**
	 * @param int[] $chatIds
	 * @return array<int, ChatMemberItem> keyed by chat id
	 */
	public function getUserMemberships(int $userId, array $chatIds): array
	{
		$chatIds = Normalizer::toUniquePositiveIntegers($chatIds);
		if ($userId <= 0 || $chatIds === [])
		{
			return [];
		}

		$memberships = [];
		foreach (array_chunk($chatIds, self::CHAT_IDS_CHUNK_SIZE) as $chatIdsChunk)
		{
			$rows = RelationTable::query()
				->setSelect(['CHAT_ID', 'USER_ID', 'REASON', 'IS_HIDDEN', 'NOTIFY_BLOCK'])
				->where('USER_ID', $userId)
				->whereIn('CHAT_ID', $chatIdsChunk)
				->fetchAll()
			;

			foreach ($rows as $row)
			{
				$member = ChatMemberItem::fromRow($row);
				$memberships[$member->chatId] = $member;
			}
		}

		return $memberships;
	}
}
