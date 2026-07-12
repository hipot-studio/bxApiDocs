<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Agent;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\LoaderException;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;

class GroupEventsChatLinker extends AbstractGroupChatLinker
{
	/**
	 * @return array<int, int|null> eventId => ?chatId
	 * @throws LoaderException
	 * @throws ArgumentException
	 */
	protected function getChatIds(int $groupId, string $mode, int $lastId): array
	{
		$service = Container::getInstance()->getCalendarListService();

		return match ($mode)
		{
			self::MODE_LAST_ACTIVE => $service->getEventsChatsByGroupByActivityDesc($groupId, self::LIMIT),
			self::MODE_IN_ORDER => $service->getEventsChatsByGroupByIdDesc($groupId, $lastId, self::LIMIT),
			default => [],
		};
	}
}
