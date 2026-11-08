<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\MainTool;

use Bitrix\Extranet\Enum\User\ExtranetRole;
use Bitrix\Extranet\Service\ServiceContainer;
use Bitrix\Intranet\User;
use Bitrix\Main\Config\Feature;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;

class AccessPolicy
{
	public static function isFeatureEnabled(): bool
	{
		return Feature::isEnabled(MainToolPickerRedesignFlag::class, new Feature\Context(null));
	}

	public function canManageAll(int $userId): bool
	{
		$currentUser = CurrentUser::get();
		if ($userId <= 0 || (int)$currentUser->getId() !== $userId)
		{
			return false;
		}

		return $currentUser->isAdmin()
			|| (Loader::includeModule('bitrix24') && \CBitrix24::IsPortalAdmin($userId));
	}

	public function canUsePicker(int $userId): bool
	{
		if ($userId <= 0 || (int)CurrentUser::get()->getId() !== $userId)
		{
			return false;
		}

		return $this->isUserInAudience($userId);
	}

	public function isUserInAudience(int $userId): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		$isExtranetAvailable = Loader::includeModule('extranet');
		if ($isExtranetAvailable && \CExtranet::IsExtranetSite())
		{
			return false;
		}

		$user = new User($userId);
		if ($user->isExternal() || !$user->isIntranet())
		{
			return false;
		}

		if ($isExtranetAvailable)
		{
			$extranetUser = ServiceContainer::getInstance()
				->getExtranetUserRepository()
				->getByUserId($userId)
			;
			if ($extranetUser?->getRole() === ExtranetRole::Collaber)
			{
				return false;
			}
		}

		return true;
	}
}
