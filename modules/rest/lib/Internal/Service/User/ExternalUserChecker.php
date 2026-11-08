<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\User;

use Bitrix\Main\Loader;

final class ExternalUserChecker
{
	public function isExternal(int $userId): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		if (Loader::includeModule('humanresources'))
		{
			try
			{
				return !\Bitrix\HumanResources\Public\Service\Container::getUserDepartmentService()->isEmployee($userId);
			}
			catch (\Throwable)
			{
			}
		}

		if ($this->isExtranetByDepartment($userId))
		{
			return true;
		}

		if (
			Loader::includeModule('extranet')
			&& class_exists(\Bitrix\Extranet\Service\ServiceContainer::class)
		)
		{
			return \Bitrix\Extranet\Service\ServiceContainer::getInstance()
				->getCollaberService()
				->isCollaberById($userId)
			;
		}

		return false;
	}

	private function isExtranetByDepartment(int $userId): bool
	{
		if (!Loader::includeModule('extranet') || !\CExtranet::getExtranetSiteId())
		{
			return false;
		}

		$user = \CUser::GetList(
			['ID' => 'ASC'],
			'',
			['ID' => $userId],
			[
				'FIELDS' => ['ID'],
				'SELECT' => ['UF_DEPARTMENT'],
			]
		)->fetch();
		if (!is_array($user))
		{
			return true;
		}

		return (int)($user['UF_DEPARTMENT'][0] ?? 0) <= 0;
	}
}
