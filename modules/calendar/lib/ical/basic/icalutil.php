<?php


namespace Bitrix\Calendar\ICal\Basic;

use Bitrix\Calendar\ICal\MailInvitation\Helper;
use Bitrix\Main\UserTable;

class ICalUtil
{
	public static function isMailUser($userId): bool
	{
		$user = \CCalendar::GetUser($userId);

		if (!empty($user))
		{
			return $user['EXTERNAL_AUTH_ID'] === 'email';
		}

		return false;
	}

	public static function getUserIdByEmail(array $userInfo): ?string
	{
		$user = UserTable::getList([
			'filter' => ['EMAIL' => $userInfo['EMAIL']],
			'select' => ['ID'],
			'limit' => 1,
		])->fetch();

		if (!empty($user))
		{
			return $user['ID'];
		}

		return Helper::getExternalUserByEmail($userInfo, $errorCollection);
	}

	public static function prepareAttendeesToCancel($attendees)
	{
		foreach ($attendees as $attendee)
		{
			$usersId[] = $attendee['id'];
		}

		return !empty($usersId) ? self::getIndexUsersById($usersId) : null;
	}

	/**
	 * @param int[] $userIds
	 * @return array
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public static function getIndexUsersById(array $userIds): array
	{
		$users = [];
		$usersDd = \CCalendar::GetUserList($userIds);

		foreach ($usersDd as $user)
		{
			$users[$user['ID']] = $user;
		}

		return $users;
	}

	/**
	 * @param array|null $attendeesCodeList
	 * @return array
	 */
	public static function getUsersByCode(array $attendeesCodeList = null): array
	{
		$userIdsList = [];
		foreach ($attendeesCodeList as $code)
		{
			if(str_starts_with($code, 'U'))
			{
				$userIdsList[] = (int)mb_substr($code, 1);
			}
		}

		return self::getIndexUsersById($userIdsList);
	}
}
