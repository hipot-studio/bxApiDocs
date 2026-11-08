<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Im;

use Bitrix\Im\V2\Public\Command\Chat\Member\AddMembersCommand;
use Bitrix\Im\V2\Public\Command\Chat\Member\AddMembersResult;
use Bitrix\Im\V2\Public\Command\Chat\Member\DeleteMembersCommand;
use Bitrix\Im\V2\Public\Command\Chat\Member\DeleteMembersResult;
use Bitrix\Im\V2\Message\MessageError;
use Bitrix\Main\Result;

class ChatMembershipWriter
{
	/**
	 * @param int[] $userIds new members and members whose adding has to be finished
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
		if ($userIds === [])
		{
			return new AddedMembers();
		}

		$result = $this->runAdd($chatId, $userIds, $mutedUserIds, $unreadMarkedMessageId);
		if ($unreadMarkedMessageId !== null && $this->isMessageMissing($result))
		{
			// the marked message was deleted: members still arrive, only without the unread mark
			$result = $this->runAdd($chatId, $userIds, $mutedUserIds, null);
		}
		if (!$result instanceof AddMembersResult)
		{
			throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
		}
		if (!$result->isSuccess() && $result->getNotAddedUserIds() === [])
		{
			throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
		}

		return new AddedMembers($result->getAddedUserIds(), $result->getNotAddedUserIds());
	}

	/**
	 * @param int[] $userIds
	 * @param int[] $mutedUserIds
	 */
	private function runAdd(int $chatId, array $userIds, array $mutedUserIds, ?int $unreadMarkedMessageId): Result
	{
		return (new AddMembersCommand(
			chatId: $chatId,
			userIds: $userIds,
			mutedUserIds: $mutedUserIds,
			addToRecent: true,
			unreadMarkedMessageId: $unreadMarkedMessageId,
		))->run();
	}

	private function isMessageMissing(Result $result): bool
	{
		return $result->getErrorCollection()->getErrorByCode(MessageError::NOT_FOUND) !== null;
	}

	/**
	 * @param int[] $userIds
	 * @throws \RuntimeException
	 */
	public function delete(int $chatId, array $userIds): void
	{
		foreach (array_chunk($userIds, DeleteMembersCommand::MAX_USER_COUNT) as $chunk)
		{
			$result = (new DeleteMembersCommand($chatId, $chunk))->run();
			if (!$result->isSuccess() || !$result instanceof DeleteMembersResult)
			{
				throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
			}
		}
	}
}
