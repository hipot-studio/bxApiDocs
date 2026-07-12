<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\Convert\Handler;

use Bitrix\Socialnetwork\Item\Workgroup;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Bitrix\Socialnetwork\V2\Internal\Exceptions\ProjectChatMemberSyncException;
use Bitrix\Socialnetwork\V2\Infrastructure\Agent\ProjectChatMemberSyncAgent;

class SyncProjectChatMembers implements HandlerInterface
{
	/**
	 * @throws ProjectChatMemberSyncException
	 */
	public function __invoke(Workgroup $group): void
	{
		$chatId = $group->getChatId();

		if ($chatId <= 0)
		{
			throw new ProjectChatMemberSyncException('Chat was not created for the group');
		}

		ProjectChatMemberSyncAgent::bind(
			delay: 1,
			withArguments: [
				$group->getId(),
				$chatId,
			],
		);
	}
}
