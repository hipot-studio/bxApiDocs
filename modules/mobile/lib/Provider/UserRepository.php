<?php

namespace Bitrix\Mobile\Provider;

use Bitrix\Extranet\Service\ServiceContainer;
use Bitrix\Main\Loader;
use Bitrix\Main\UserTable;

class UserRepository
{
	private static ?array $adminIdMap = null;
	private static array $usersData = [];

	public static function getDefaultFieldsForSelect(): array
	{
		return [
			'ID',
			'NAME',
			'LAST_NAME',
			'SECOND_NAME',
			'LOGIN',
			'PERSONAL_PHOTO',
			'WORK_POSITION',
			'EMAIL',
			'WORK_PHONE',
			'LAST_ACTIVITY_DATE',
			'PERSONAL_GENDER',
		];
	}

	/**
	 * @param int[] $userIds
	 * @return CommonUserDto[]
	 */
	public static function getByIds(array $userIds): array
	{
		$userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));

		if (empty($userIds))
		{
			return [];
		}

		$userIdsToFetch = array_diff($userIds, array_keys(static::$usersData));
		if (!empty($userIdsToFetch))
		{
			$userResult = UserTable::getList([
				'select' => static::getDefaultFieldsForSelect(),
				'filter' => ['ID' => $userIdsToFetch],
			]);
			while ($user = $userResult->fetch())
			{
				static::$usersData[(int)$user['ID']] = $user;
			}
		}

		$users = [];
		foreach ($userIds as $userId)
		{
			if (isset(static::$usersData[$userId]))
			{
				$users[] = static::createUserDto(static::$usersData[$userId]);
			}
		}

		return $users;
	}

	public static function createUserDto(array $user): CommonUserDto
	{
		$userId = (int)$user['ID'];
		$originalAvatar = null;
		$resizedAvatar100 = null;

		if (!empty($user['PERSONAL_PHOTO']))
		{
			[$originalAvatar, $resizedAvatar100] = self::getAvatar($user['PERSONAL_PHOTO']);
		}

		// todo: remove mock
		$user['actions'] = ['delete', 'fire'];
		$mobileContext = new \Bitrix\Mobile\Context();

		if (!empty($user["LAST_ACTIVITY_DATE"]))
		{
			$timeZone = $user["LAST_ACTIVITY_DATE"]->getTimezone();
			$lastActivityDate = $user["LAST_ACTIVITY_DATE"]->toString();
		}

		return new CommonUserDto(
			id: $userId,
			login: $user['LOGIN'] ?? null,
			name: $user['NAME'] ?? null,
			lastName: $user['LAST_NAME'] ?? null,
			secondName: $user['SECOND_NAME'] ?? null,
			fullName: self::getUserFullName($user),
			email: $user['EMAIL'] ?? null,
			workPhone: $user['WORK_PHONE'] ?? null,
			workPosition: $user['WORK_POSITION'] ?? null,
			link: "/company/personal/user/$userId/",
			avatarSizeOriginal: $originalAvatar,
			avatarSize100: $resizedAvatar100,
			isAdmin: self::isAdmin($userId),
			isCollaber: self::isCollaber($userId),
			isExtranet: self::isExtranet($userId),
			personalMobile: $user['PERSONAL_MOBILE'] ?? null,
			personalPhone: $user['PERSONAL_PHONE'] ?? null,
			lastActivityDate: $lastActivityDate ?? null,
			timezone: $timeZone ?? null,
			personalGender: $user['PERSONAL_GENDER'] ?? null,
		);
	}

	private static function isCollaber(int $userId): bool
	{
		if (!Loader::includeModule('extranet') || $userId <= 0)
		{
			return false;
		}

		$container = class_exists(ServiceContainer::class) ? ServiceContainer::getInstance() : null;

		return $container?->getCollaberService()?->isCollaberById($userId) ?? false;
	}

	private static function isExtranet(int $userId): bool
	{
		if (!Loader::includeModule('extranet') || $userId <= 0)
		{
			return false;
		}

		$serviceContainer = class_exists(ServiceContainer::class) ? ServiceContainer::getInstance() : null;

		return $serviceContainer?->getUserService()?->isCurrentExtranetUserById($userId) ?? false;
	}

	private static function isAdmin($userId): bool
	{
		if (static::$adminIdMap === null)
		{
			$adminIdList = [];

			$dbAdminList = \CAllGroup::GetGroupUserEx(1);
			while ($admin = $dbAdminList->fetch())
			{
				$adminIdList[] = (int)$admin['USER_ID'];
			}

			static::$adminIdMap = array_fill_keys($adminIdList, true);
		}

		return isset(static::$adminIdMap[$userId]);
	}

	private static function getUserFullName(array $user): string
	{
		return \CUser::FormatName(
			\CSite::GetNameFormat(),
			[
				'LOGIN' => $user['LOGIN'] ?? '',
				'NAME' => $user['NAME'] ?? '',
				'LAST_NAME' => $user['LAST_NAME'] ?? '',
				'SECOND_NAME' => $user['SECOND_NAME'] ?? '',
			],
			true,
			false,
		);
	}

	private static function getAvatar(int $avatarId): array
	{
		static $cache = [];

		if (!isset($cache[$avatarId]))
		{
			$src = [];

			if ($avatarId > 0)
			{
				$originalFile = \CFile::getFileArray($avatarId);

				if ($originalFile !== false)
				{
					$resizedFile = \CFile::resizeImageGet(
						$originalFile,
						['width' => 100, 'height' => 100],
						BX_RESIZE_IMAGE_EXACT,
						false,
						false,
						true,
					);
					$src = [
						$originalFile['SRC'],
						$resizedFile['src'],
					];
				}

				$cache[$avatarId] = $src;
			}
		}

		return $cache[$avatarId];
	}

	private static function is2faEnabled()
	{
		return Loader::includeModule("security") && \Bitrix\Security\Mfa\Otp::isOtpEnabled();
	}
}
