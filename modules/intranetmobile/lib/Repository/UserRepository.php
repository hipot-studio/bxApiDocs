<?php

namespace Bitrix\IntranetMobile\Repository;

use Bitrix\IntranetMobile\Dto\UserDto;
use Bitrix\Main\Type\DateTime;

class UserRepository
{
	public static function createUserDto(array $user): UserDto
	{
		$installedApps = \Bitrix\Intranet\Util::getAppsInstallationConfig((int)$user['ID']);;

		$installedAppsDto = new \Bitrix\IntranetMobile\Dto\InstalledAppsDto(
			windows: $installedApps['APP_WINDOWS_INSTALLED'],
			linux: $installedApps['APP_LINUX_INSTALLED'],
			mac: $installedApps['APP_MAC_INSTALLED'],
			ios: $installedApps['APP_IOS_INSTALLED'],
			android: $installedApps['APP_ANDROID_INSTALLED'],
		);

		if ($user['ACTIVE'] === 'Y')
		{
			if ($user['CONFIRM_CODE'] === null || $user['CONFIRM_CODE'] === '')
			{
				$employeeStatus = UserDto::ACTIVE;
			}
			else
			{
				$employeeStatus = UserDto::INVITED;
			}
		}
		else
		{
			if ($user['CONFIRM_CODE'] === null || $user['CONFIRM_CODE'] === '')
			{
				$employeeStatus = UserDto::FIRED;
			}
			else
			{
				$employeeStatus = UserDto::INVITE_AWAITING_APPROVE;
			}
		}

		try
		{
			$timestamp = (new DateTime($user['DATE_REGISTER']))->getTimestamp();
		}
		catch (\Exception)
		{
			$timestamp = null;
		}

		$userId = (int)$user['ID'];

		return new UserDto(
			id: $userId,
			department: $user['UF_DEPARTMENT'],
			isExtranetUser: \Bitrix\Intranet\Util::isExtranetUser($userId),
			installedApps: $installedAppsDto,
			employeeStatus: $employeeStatus,
			dateRegister: $timestamp,
			actions: $user['ACTIONS'],
		);
	}
}