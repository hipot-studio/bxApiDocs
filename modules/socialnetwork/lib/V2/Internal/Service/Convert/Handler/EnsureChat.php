<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\Convert\Handler;

use Bitrix\Socialnetwork\Integration\Im\Chat\Workgroup as WorkgroupChat;
use Bitrix\Socialnetwork\Item\Workgroup;
use Bitrix\Socialnetwork\V2\Internal\Exceptions\ProjectEnsureChatException;

class EnsureChat implements HandlerInterface
{
	/**
	 * @throws ProjectEnsureChatException
	 */
	public function __invoke(Workgroup $group): void
	{
		$groupId = $group->getId();
		if ($groupId <= 0)
		{
			throw new ProjectEnsureChatException(
				sprintf('Group id is invalid: [%s]', $groupId)
			);
		}

		if ($this->hasChat($groupId))
		{
			return;
		}

		if (!$this->createChat($groupId) || !$this->hasChat($groupId))
		{
			throw new ProjectEnsureChatException(
				sprintf('Unable to create chat for the group [%s]', $groupId)
			);
		}
	}

	protected function hasChat(int $groupId): bool
	{
		$chatData = WorkgroupChat::getChatData([
			'group_id' => $groupId,
			'skipAvailabilityCheck' => true,
		]);

		return (int)($chatData[$groupId] ?? 0) > 0;
	}

	protected function createChat(int $groupId): bool
	{
		return WorkgroupChat::createChat(['group_id' => $groupId]);
	}
}
