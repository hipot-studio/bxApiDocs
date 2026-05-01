<?php

namespace Bitrix\Rest\Internal\Integration\Main\EventHandlers;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Rest\Internal\Repository\SystemUser\SystemUserRepository;

class UserEventHandler
{
	public static function onAfterUserDelete(int $userId): void
	{
		/** @var SystemUserRepository $systemUserRepository */
		$systemUserRepository = ServiceLocator::getInstance()->get(SystemUserRepository::class);
		$systemUserRepository->deleteByUserId($userId);
	}
}