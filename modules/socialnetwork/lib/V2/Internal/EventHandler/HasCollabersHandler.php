<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\EventHandler;

use Bitrix\Socialnetwork\Collab\Registry\CollabRegistry;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Bitrix\Socialnetwork\V2\Internal\Integration\Extranet\Service\ExtranetUserService;

class HasCollabersHandler
{
	public static function onUserAdd(int $relationId, array &$fields): void
	{
		$groupId = (int)($fields['GROUP_ID'] ?? 0);
		$userId = (int)($fields['USER_ID'] ?? 0);

		if ($groupId <= 0 || $userId <= 0)
		{
			return;
		}

		if (!self::isCollab($groupId))
		{
			return;
		}

		$extranetService = new ExtranetUserService();

		if (!$extranetService->isCollaber($userId))
		{
			return;
		}

		Container::getInstance()->getHasCollabersService()->storeFlag($groupId, true);
	}

	public static function onUserDelete(int $relationId, array $fields): void
	{
		$groupId = (int)($fields['GROUP_ID'] ?? 0);
		$userId = (int)($fields['USER_ID'] ?? 0);

		if ($groupId <= 0 || $userId <= 0)
		{
			return;
		}

		if (!self::isCollab($groupId))
		{
			return;
		}

		$extranetService = new ExtranetUserService();

		if (!$extranetService->isCollaber($userId))
		{
			return;
		}

		Container::getInstance()->getHasCollabersService()->updateOption($groupId, excludeUserId: $userId);
	}

	private static function isCollab(int $groupId): bool
	{
		return CollabRegistry::getInstance()->get($groupId) !== null;
	}
}
