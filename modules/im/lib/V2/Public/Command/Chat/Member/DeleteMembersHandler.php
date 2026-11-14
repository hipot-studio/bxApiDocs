<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Public\Command\Chat\Member;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\ChatError;
use Bitrix\Im\V2\Chat\GroupChat;
use Bitrix\Im\V2\Relation\DeleteUserConfig;

final class DeleteMembersHandler
{
	public function __invoke(DeleteMembersCommand $command): DeleteMembersResult
	{
		$result = new DeleteMembersResult();

		$chat = Chat::getInstance($command->getChatId());
		if (!$chat->isExist())
		{
			return $result->addError(new ChatError(ChatError::NOT_FOUND));
		}
		if (!$chat instanceof GroupChat)
		{
			return $result->addError(new ChatError(ChatError::WRONG_CHAT_TYPE));
		}

		$systemChat = $chat->withContextUser(0);
		$config = new DeleteUserConfig(withMessage: false, withNotification: false);
		$memberUserIds = $this->getMemberUserIds($chat, $command->getUserIds());

		foreach ($memberUserIds as $userId)
		{
			$deleteResult = $systemChat->deleteUser($userId, $config);
			if (!$deleteResult->isSuccess())
			{
				$result->addErrors($deleteResult->getErrors());
			}
		}

		$remainingUserIds = $this->getMemberUserIds($chat, $memberUserIds);

		return $result->setDeletedUserIds(array_diff($memberUserIds, $remainingUserIds));
	}

	/**
	 * @param int[] $userIds
	 * @return int[]
	 */
	private function getMemberUserIds(Chat $chat, array $userIds): array
	{
		return $chat->getRelationFacade()?->getRawByUserIds($userIds)->getUserIds() ?? [];
	}
}
