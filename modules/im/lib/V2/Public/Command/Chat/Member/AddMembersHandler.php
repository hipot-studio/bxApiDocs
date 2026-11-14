<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Public\Command\Chat\Member;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\ChatError;
use Bitrix\Im\V2\Chat\GroupChat;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\MessageError;
use Bitrix\Im\V2\Recent\NewMembersRecentWriter;
use Bitrix\Im\V2\Relation\AddUsersConfig;
use Bitrix\Im\V2\RelationCollection;

final class AddMembersHandler
{
	public function __construct(
		private readonly NewMembersRecentWriter $recentWriter,
	)
	{}

	public function __invoke(AddMembersCommand $command): AddMembersResult
	{
		$result = new AddMembersResult();

		$chat = Chat::getInstance($command->getChatId());
		if (!$chat->isExist())
		{
			return $result->addError(new ChatError(ChatError::NOT_FOUND));
		}
		if (!$chat instanceof GroupChat)
		{
			return $result->addError(new ChatError(ChatError::WRONG_CHAT_TYPE));
		}

		$unreadMarkedMessageId = $command->getUnreadMarkedMessageId();
		if ($unreadMarkedMessageId !== null && !$this->belongsToChat($unreadMarkedMessageId, $chat))
		{
			return $result->addError(new MessageError(MessageError::NOT_FOUND));
		}

		$userIds = $command->getUserIds();
		$existingMemberIds = $this->getVisibleMemberIds($this->getRelations($chat, $userIds));

		$chat->withContextUser(0)->addUsers($userIds, new AddUsersConfig(
			hideHistory: $command->isHideHistory(),
			withMessage: $command->isWithMessage(),
			mutedUserIds: $command->getMutedUserIds(),
			addToRecent: $command->isAddToRecent(),
			unreadMarkedMessageId: $unreadMarkedMessageId,
		));

		$relations = $this->getRelations($chat, $userIds);
		$memberIds = $this->getVisibleMemberIds($relations);

		if ($command->isAddToRecent() && $existingMemberIds !== [])
		{
			$this->recentWriter->writeMissing($chat, $relations->filterByUserIds($existingMemberIds), $unreadMarkedMessageId);
		}

		$result
			->setAddedUserIds(array_diff($memberIds, $existingMemberIds))
			->setNotAddedUserIds(array_diff($userIds, $memberIds))
		;

		if ($memberIds === [])
		{
			$result->addError(new ChatError(ChatError::USERS_NOT_ADDED));
		}

		return $result;
	}

	private function belongsToChat(int $messageId, Chat $chat): bool
	{
		$message = new Message($messageId);

		return $message->getId() === $messageId && $message->getChatId() === $chat->getChatId();
	}

	/**
	 * @param int[] $userIds
	 */
	private function getRelations(Chat $chat, array $userIds): RelationCollection
	{
		return $chat->getRelationFacade()?->getRawByUserIds($userIds) ?? new RelationCollection();
	}

	/**
	 * @return int[]
	 */
	private function getVisibleMemberIds(RelationCollection $relations): array
	{
		$userIds = [];
		foreach ($relations as $relation)
		{
			if (!$relation->isHidden())
			{
				$userIds[] = (int)$relation->getUserId();
			}
		}

		return $userIds;
	}
}
