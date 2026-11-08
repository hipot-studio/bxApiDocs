<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Extranet;

use Bitrix\Extranet\Service\ServiceContainer;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\ObjectNotFoundException;
use Psr\Container\NotFoundExceptionInterface;

class ExtranetService
{
	public function getExtranetUserGroupId(): ?int
	{
		if (!Loader::includeModule('extranet'))
		{
			return null;
		}

		$groupId = \CExtranet::GetExtranetUserGroupID();

		return is_int($groupId) && $groupId > 0 ? $groupId : null;
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
