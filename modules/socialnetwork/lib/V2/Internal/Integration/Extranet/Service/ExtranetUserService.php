<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Extranet\Service;

use Bitrix\Extranet\Service\ServiceContainer;
use Bitrix\Main\Loader;
use CExtranet;

class ExtranetUserService
{
	public function isCollaber(int $userId): bool
	{
		if (!Loader::includeModule('extranet'))
		{
			return false;
		}

		return ServiceContainer::getInstance()
			->getCollaberService()
			->isCollaberById($userId)
		;
	}

	public function isExtranet(int $userId): bool
	{
		if (!Loader::includeModule('extranet'))
		{
			return false;
		}

		return !CExtranet::IsIntranetUser('', $userId);
	}

	/**
	 * @return int[]
	 */
	public function getCollaberIds(): array
	{
		if (!Loader::includeModule('extranet'))
		{
			return [];
		}

		return ServiceContainer::getInstance()
			->getCollaberService()
			->getCollaberIds()
		;
	}

	public function getExtranetSiteId(): string
	{
		if (!Loader::includeModule('extranet'))
		{
			return '';
		}

		return CExtranet::GetExtranetSiteID() ?: '';
	}
}
