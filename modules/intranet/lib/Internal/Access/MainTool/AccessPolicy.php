<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Access\MainTool;

use Bitrix\Intranet\Internal\Config\Feature\MainToolPickerRedesignFlag;
use Bitrix\Intranet\Internal\Integration\Extranet\ExtranetService;
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

	public function canManageAll(): bool
	{
		$currentUser = CurrentUser::get();
		$userId = (int)$currentUser->getId();
		if ($userId <= 0)
		{
			return false;
		}

		return
			$currentUser->isAdmin()
			|| (Loader::includeModule('bitrix24') && \CBitrix24::IsPortalAdmin($userId))
		;
	}

	public function canUsePicker(): bool
	{
		return $this->isUserInAudience((int)CurrentUser::get()->getId());
	}

	public function isUserInAudience(int $userId): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		$extranet = new ExtranetService();
		if ($extranet->isAvailable() && $extranet->isExtranet())
		{
			return false;
		}

		$user = new User($userId);
		if (
			$user->isExternal()
			|| !Loader::includeModule('humanresources')
			|| !$user->isIntranet()
		)
		{
			return false;
		}

		return $extranet->isMainToolPickerAllowedByRole($userId);
	}
}
