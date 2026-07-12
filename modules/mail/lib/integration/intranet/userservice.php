<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\Intranet;

use Bitrix\Intranet\Enum\InvitationStatus;
use Bitrix\Intranet\Service\ServiceContainer;
use Bitrix\Intranet\Service\UserService as IntranetUserService;
use Bitrix\Main\Loader;

final class UserService
{
	public static function isUserFired(int $userId): bool
	{
		$userRepository = ServiceContainer::getInstance()->userRepository();
		$intranetUser = $userRepository->getUserById($userId);

		return $intranetUser->getInviteStatus() === InvitationStatus::FIRED;
	}

	public static function getAdminUserIds(): array
	{
		if (!Loader::includeModule('intranet'))
		{
			return [];
		}

		return (new IntranetUserService())->getAdminUserIds();
	}
}
