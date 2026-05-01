<?php

namespace Bitrix\Rest\Internal\Integration\Rest\EventHandlers;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Rest\APAuth\PasswordTable;
use Bitrix\Rest\Internal\Entity\SystemUser\ResourceType;
use Bitrix\Rest\Internal\Repository\SystemUser\SystemUserRepository;

class PasswordEventHandler
{
	public static function onAfterDelete(array $data): void
	{
		if (!isset($data['USER_ID']))
		{
			return;
		}

		$userId = (int)$data['USER_ID'];

		$webhooksCount = PasswordTable::getCount([
			'=USER_ID' => $userId,
			'=ACTIVE' => PasswordTable::ACTIVE,
		]);

		if ($webhooksCount === 0)
		{
			/** @var SystemUserRepository $systemUserRepository */
			$systemUserRepository = ServiceLocator::getInstance()->get(SystemUserRepository::class);
			$systemUser = $systemUserRepository->getByResourceIdAndResourceType($userId, ResourceType::WEBHOOK);
			if ($systemUser !== null)
			{
				$user = new \CUser();
				$user->Update($systemUser->getUserId(), ['ACTIVE' => 'N']);

				$systemUserRepository->deleteByUserId($systemUser->getUserId());
			}
		}
	}
}