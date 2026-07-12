<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Agent;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\LoaderException;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;

class GroupTaskChatLinker extends AbstractGroupChatLinker
{
	/**
	 * @return array<int, int|null> taskId => ?chatId
	 * @throws LoaderException
	 * @throws ArgumentException
	 */
	protected function getChatIds(int $groupId, string $mode, int $lastId): array
	{
		$taskService = Container::getInstance()->getTaskListService();

		return match ($mode)
		{
			self::MODE_LAST_ACTIVE => $taskService->getTaskChatsByGroupByActivityDesc($groupId, self::LIMIT),
			self::MODE_IN_ORDER => $taskService->getTaskChatsByGroupByIdDesc($groupId, $lastId, self::LIMIT),
			default => [],
		};
	}
}
