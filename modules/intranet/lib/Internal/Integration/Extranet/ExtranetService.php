<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Extranet;

use Bitrix\Extranet\Enum\User\ExtranetRole;
use Bitrix\Extranet\Service\ServiceContainer;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\ObjectNotFoundException;
use Psr\Container\NotFoundExceptionInterface;

class ExtranetService
{
	public function isAvailable(): bool
	{
		return Loader::includeModule('extranet');
	}

	public function getExtranetUserGroupId(): ?int
	{
		if (!Loader::includeModule('extranet'))
		{
			return null;
		}

		$groupId = \CExtranet::GetExtranetUserGroupID();

		return is_int($groupId) && $groupId > 0 ? $groupId : null;
	}

	public function getExtranetSiteId(): string|false
	{
		return \CExtranet::GetExtranetSiteID();
	}

	public function isTransferToIntranetAllowedByRegistryRole(int $userId): bool
	{
		$registry = ServiceContainer::getInstance()->getExtranetUserRepository()->getByUserId($userId);

		return $registry === null
			|| in_array($registry->getRole(), [ExtranetRole::Extranet, ExtranetRole::Collaber], true);
	}

	public function isExtranet(): bool
	{
		if (!Loader::includeModule('extranet'))
		{
			$extranetSiteId = Option::get('extranet', 'extranet_site');

			return $extranetSiteId === SITE_ID;
		}

		return \CExtranet::IsExtranetSite();
	}

	public function isCollaberInvitationEnabled(): bool
	{
		if (!Loader::includeModule('extranet'))
		{
			return false;
		}

		return \Bitrix\Extranet\PortalSettings::getInstance()->isEnabledCollabersInvitation();
	}

	public function isMainToolPickerAllowedByRole(int $userId): bool
	{
		try
		{
			if (!Loader::includeModule('extranet'))
			{
				return true;
			}

			$user = ServiceContainer::getInstance()->getExtranetUserRepository()->getByUserId($userId);

			return $user?->getRole() !== ExtranetRole::Collaber;
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	public function isCollaber(int $userId): bool
	{
		if (!Loader::includeModule('extranet'))
		{
			return false;
		}

		try
		{
			return ServiceContainer::getInstance()->getCollaberService()->isCollaberById($userId);
		}
		catch (NotFoundExceptionInterface|ObjectNotFoundException|\Error)
		{
			return false;
		}
	}
}
