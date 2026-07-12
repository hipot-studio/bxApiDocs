<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Im\Repository;

use Bitrix\Socialnetwork\V2\Internal\Integration\Im\Repository\ChatMemberRepositoryInterface;
use Bitrix\Im\V2\RelationCollection;

class ChatMemberRepository implements ChatMemberRepositoryInterface
{
	public function getMemberUserIds(int $chatId): array
	{
		$userIds = RelationCollection::find(
			filter: [
				'CHAT_ID' => $chatId,
				'ACTIVE' => true,
				'IS_HIDDEN' => false,
				'ONLY_INTERNAL_TYPE' => true,
			],
			select: ['ID', 'USER_ID'],
		)->getUserIds();

		$userIds = array_map(
			static fn (mixed $userId): int => (int)$userId,
			$userIds,
		);

		$userIds = array_filter(
			$userIds,
			static fn (int $userId): bool => $userId > 0,
		);

		return array_values($userIds);
	}
}
