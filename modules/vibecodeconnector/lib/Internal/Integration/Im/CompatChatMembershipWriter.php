<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Im;

use Bitrix\Im\Model\RecentTable;
use Bitrix\Im\Recent;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\GroupChat;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Relation;
use Bitrix\Im\V2\Relation\AddUsersConfig;
use Bitrix\Im\V2\Relation\DeleteUserConfig;
use Bitrix\Im\V2\RelationCollection;
use Bitrix\Main\Type\DateTime;

final class CompatChatMembershipWriter extends ChatMembershipWriter
{
	private const RAISE_DEPTH = 2;
	private const IDS_CHUNK_SIZE = 500;

	public function __construct(
		private readonly CompatChatRelations $relations = new CompatChatRelations(),
	) {}

	/**
	 * @param int[] $userIds
	 * @param int[] $mutedUserIds
	 * @throws \RuntimeException
	 */
	public function add(
		int $chatId,
		array $userIds,
		array $mutedUserIds = [],
		?int $unreadMarkedMessageId = null,
	): AddedMembers
	{
		$userIds = self::normalizeIds($userIds);
		if ($userIds === [])
		{
			return new AddedMembers();
		}

		$chat = $this->loadGroupChat($chatId)->withContextUser(0);
		if ($unreadMarkedMessageId !== null && !self::belongsToChat($unreadMarkedMessageId, $chat))
		{
			$unreadMarkedMessageId = null;
		}

		$members = $this->relations->getMembers($chatId, $userIds);
		$existingMemberIds = self::filterVisible($members);
		$mutedUserIds = array_values(array_intersect(self::normalizeIds($mutedUserIds), $userIds));
		$arrivingMutedIds = array_values(array_diff($mutedUserIds, $existingMemberIds));

		$this->addHidden($chat, array_values(array_diff($arrivingMutedIds, array_keys($members))));
		$hiddenMutedIds = $this->muteHidden($chatId, $arrivingMutedIds);
		if ($arrivingMutedIds !== [])
		{
			$chat->getRelationFacade()?->cleanCache();
		}

		$chat->addUsers(
			array_values(array_diff($userIds, array_diff($arrivingMutedIds, $hiddenMutedIds))),
			new AddUsersConfig(hideHistory: false, withMessage: false),
		);
		$memberIds = $this->getVisibleMemberIds($chatId, $userIds);
		$addedUserIds = array_values(array_diff($memberIds, $existingMemberIds));

		$this->muteMembers($chat, array_values(array_intersect($existingMemberIds, $mutedUserIds)));

		$lastActivity = new DateTime();
		$this->writeRecent($chat, $addedUserIds, $unreadMarkedMessageId, $lastActivity);
		$this->writeRecent(
			$chat,
			array_values(array_diff($existingMemberIds, $this->getUserIdsWithRecentItem($chatId, $existingMemberIds))),
			$unreadMarkedMessageId,
			$lastActivity,
		);

		return new AddedMembers($addedUserIds, array_values(array_diff($userIds, $memberIds)));
	}

	/**
	 * @param int[] $userIds
	 * @throws \RuntimeException
	 */
	public function delete(int $chatId, array $userIds): void
	{
		$userIds = self::normalizeIds($userIds);
		if ($userIds === [])
		{
			return;
		}

		$chat = $this->loadGroupChat($chatId)->withContextUser(0);
		$config = new DeleteUserConfig(withMessage: false, withNotification: false);
		$errors = [];
		foreach (array_keys($this->relations->getMembers($chatId, $userIds)) as $userId)
		{
			$result = $chat->deleteUser($userId, $config);
			if (!$result->isSuccess())
			{
				array_push($errors, ...$result->getErrorMessages());
			}
		}

		if ($errors !== [])
		{
			throw new \RuntimeException(implode('; ', $errors));
		}
	}

	/**
	 * @param int[] $userIds
	 */
	private function addHidden(Chat $chat, array $userIds): void
	{
		if ($userIds === [])
		{
			return;
		}

		$chat->addUsers($userIds, new AddUsersConfig(
			hideHistory: false,
			withMessage: false,
			hiddenUserIds: $userIds,
		));
	}

	/**
	 * @param int[] $userIds
	 * @return int[]
	 * @throws \RuntimeException
	 */
	private function muteHidden(int $chatId, array $userIds): array
	{
		$mutedIds = [];
		foreach (array_chunk($userIds, self::IDS_CHUNK_SIZE) as $chunk)
		{
			$relations = RelationCollection::find(['CHAT_ID' => $chatId, 'USER_ID' => $chunk]);
			foreach ($relations as $relation)
			{
				if ($relation->isHidden())
				{
					$relation->setNotifyBlock(true);
					$mutedIds[] = (int)$relation->getUserId();
				}
			}

			$result = $relations->save(true);
			if (!$result->isSuccess())
			{
				throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
			}
		}

		return $mutedIds;
	}

	/**
	 * @param int[] $userIds
	 * @throws \RuntimeException
	 */
	private function muteMembers(Chat $chat, array $userIds): void
	{
		if ($userIds === [])
		{
			return;
		}

		$chat->getRelationsByUserIds($userIds);
		$errors = [];
		foreach ($userIds as $userId)
		{
			$result = $chat->mute(true, $userId);
			if (!$result->isSuccess())
			{
				array_push($errors, ...$result->getErrorMessages());
			}
		}

		if ($errors !== [])
		{
			throw new \RuntimeException(implode('; ', $errors));
		}
	}

	/**
	 * @throws \RuntimeException
	 */
	private function loadGroupChat(int $chatId): Chat
	{
		$chat = Chat::getInstance($chatId);
		if (!$chat->isExist() || !$chat instanceof GroupChat)
		{
			throw new \RuntimeException('Chat ' . $chatId . ' is not a group chat');
		}

		return $chat;
	}

	private static function belongsToChat(int $messageId, Chat $chat): bool
	{
		$message = new Message($messageId);

		return $message->getId() === $messageId && $message->getChatId() === $chat->getChatId();
	}

	/**
	 * @param int[] $userIds
	 * @return int[]
	 */
	private function getVisibleMemberIds(int $chatId, array $userIds): array
	{
		return self::filterVisible($this->relations->getMembers($chatId, $userIds));
	}

	/**
	 * @param array<int, ChatMember> $members
	 * @return int[]
	 */
	private static function filterVisible(array $members): array
	{
		$memberIds = [];
		foreach ($members as $userId => $member)
		{
			if (!$member->isHidden)
			{
				$memberIds[] = $userId;
			}
		}

		return $memberIds;
	}

	/**
	 * @param int[] $userIds
	 */
	private function writeRecent(Chat $chat, array $userIds, ?int $markedId, DateTime $lastActivity): void
	{
		if ($userIds === [])
		{
			return;
		}

		$relations = self::filterSeeingLastMessage($chat, RelationCollection::find([
			'CHAT_ID' => (int)$chat->getChatId(),
			'USER_ID' => $userIds,
		]));
		$seeingUserIds = array_values($relations->getUserIds());
		if ($seeingUserIds === [])
		{
			return;
		}

		Recent::raiseChat($chat, $relations, $lastActivity, self::RAISE_DEPTH);
		$this->raiseAncestors($chat, $seeingUserIds, $lastActivity);

		if ($markedId === null)
		{
			return;
		}

		foreach ($seeingUserIds as $userId)
		{
			Recent::unread($chat->getDialogId(), true, $userId, $markedId);
		}
	}

	/**
	 * @param int[] $userIds
	 */
	private function raiseAncestors(Chat $chat, array $userIds, DateTime $lastActivity): void
	{
		for ($depth = 1; $depth <= self::RAISE_DEPTH && $userIds !== [] && $chat->hasParent(); $depth++)
		{
			$chat = $chat->getParentChat();
			if ($chat === null)
			{
				return;
			}

			$relations = $chat->getRelationsByUserIds($userIds);
			Recent::raiseChat(
				$chat,
				self::filterSeeingLastMessage($chat, $relations),
				$lastActivity,
				self::RAISE_DEPTH,
			);
			$userIds = array_values($relations->getUserIds());
		}
	}

	private static function filterSeeingLastMessage(Chat $chat, RelationCollection $relations): RelationCollection
	{
		$lastMessageId = (int)$chat->getLastMessageId();

		return $relations->filter(
			static fn (Relation $relation): bool => (int)$relation->getStartId() <= $lastMessageId,
		);
	}

	/**
	 * @param int[] $userIds
	 * @return int[]
	 */
	private function getUserIdsWithRecentItem(int $chatId, array $userIds): array
	{
		$result = [];
		foreach (array_chunk($userIds, self::IDS_CHUNK_SIZE) as $chunk)
		{
			$rows = RecentTable::query()
				->setSelect(['USER_ID'])
				->where('ITEM_CID', $chatId)
				->whereIn('USER_ID', $chunk)
				->fetchAll()
			;
			foreach ($rows as $row)
			{
				$result[] = (int)$row['USER_ID'];
			}
		}

		return $result;
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
