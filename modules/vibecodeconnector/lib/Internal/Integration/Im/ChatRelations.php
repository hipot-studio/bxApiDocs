<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Im;

use Bitrix\Im\V2\Public\Dto\Member\ChatMemberItem;
use Bitrix\Im\V2\Public\Provider\Member\ChatMembersProvider;

class ChatRelations
{
	/**
	 * @param int[] $userIds
	 * @return array<int, ChatMember> keyed by user id
	 */
	public function getMembers(int $chatId, array $userIds): array
	{
		return array_map(
			static fn (ChatMemberItem $member): ChatMember => self::toMember($member),
			$this->provider()->getRawByUserIds($chatId, $userIds),
		);
	}

	/**
	 * @return int[]
	 */
	public function getMemberIdsAfter(int $chatId, int $afterUserId, int $limit): array
	{
		return $this->provider()->getMemberIdsAfter($chatId, $afterUserId, $limit);
	}

	/**
	 * @param int[] $chatIds
	 * @return array<int, ChatMember> keyed by chat id
	 */
	public function getUserMemberships(int $userId, array $chatIds): array
	{
		return array_map(
			static fn (ChatMemberItem $member): ChatMember => self::toMember($member),
			$this->provider()->getUserMemberships($userId, $chatIds),
		);
	}

	private function provider(): ChatMembersProvider
	{
		return new ChatMembersProvider();
	}

	private static function toMember(ChatMemberItem $member): ChatMember
	{
		return new ChatMember($member->reason, $member->isHidden);
	}
}
