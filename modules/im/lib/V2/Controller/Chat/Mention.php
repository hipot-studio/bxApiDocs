<?php

namespace Bitrix\Im\V2\Controller\Chat;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Controller\BaseController;
use Bitrix\Im\V2\Entity\User\UserBot;
use Bitrix\Im\V2\Entity\User\UserCollection;
use Bitrix\Im\V2\RelationCollection;
use Bitrix\Im\V2\Entity\User\User;

class Mention extends BaseController
{
	/**
	 * @restMethod im.v2.Chat.Mention.list
	 */
	public function listAction(Chat $chat, array $order = [], int $limit = self::DEFAULT_LIMIT): ?array
	{
		$limit = $this->getLimit($limit);
		$relationFilter = $this->getRelationFilter($chat->getChatId());
		$relationOrder = $this->prepareRelationOrder($order);

		$users = RelationCollection::find($relationFilter, $relationOrder, $limit)->getUsers();
		$filteredUsers = $this->filterUsersForMention($users);

		return $this->toRestFormat($filteredUsers);
	}

	protected function getRelationFilter(int $chatId): array
	{
		$filter = [
			'ACTIVE' => true,
			'CHAT_ID' => $chatId,
			'IS_HIDDEN' => false,
		];

		$currentUserId = $this->getCurrentUser()?->getId();
		if (isset($currentUserId))
		{
			$filter['!USER_ID'] = $currentUserId;
		}

		return $filter;
	}

	protected function filterUsersForMention(UserCollection $users): UserCollection
	{
		return $users->filter(
			fn(User $user) => !($user instanceof UserBot && $user->isHidden())
		);
	}

	protected function prepareRelationOrder(array $order): array
	{
		return match (true)
		{
			isset($order['id']) => ['ID' => strtoupper($order['id'])],
			isset($order['lastSendMessageId']) => ['LAST_SEND_MESSAGE_ID' => strtoupper($order['lastSendMessageId'])],
			isset($order['userId']) => ['USER_ID' => strtoupper($order['userId'])],
			default => [],
		};
	}
}