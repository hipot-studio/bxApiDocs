<?php

namespace Bitrix\Rest\Internal\Service\Application;

use Bitrix\Main\Security\Random;
use Bitrix\Main\UserTable;
use Bitrix\Rest\Internal\Exceptions\ApplicationUserGenerator\UserNotFoundException;
use Bitrix\Rest\Internal\Exceptions\ApplicationUserGenerator\UserNotGeneratedException;
use CUser;
class SystemUserGenerator
{
	public static function createByUserId(int $userId, string $applicationName): int
	{
		$applicationName = htmlspecialcharsbx($applicationName);

		$originalUser = UserTable::query()
			->setSelect(['TIME_ZONE', 'LANGUAGE_ID'])
			->where('ID', $userId)
			->setLimit(1)
			->fetchObject();

		if ($originalUser === null)
		{
			throw new UserNotFoundException();
		}

		$groupIds = self::getUserGroupIds($userId);

		$password = CUser::GeneratePasswordByPolicy($groupIds);
		$login = Random::getString(20) . '.bitrix.application';
		$email = Random::getString(50) . '@bitrix.application';

		$newUserFields = [
			'ACTIVE' => 'Y',
			'LOGIN' => $login,
			'EMAIL' => $email,
			'NAME' => $applicationName,
			'PASSWORD' => $password,
			'TIME_ZONE' => $originalUser->getTimeZone(),
			'LANGUAGE_ID' => $originalUser->getLanguageId(),
			'GROUP_ID' => $groupIds,
			'ADMIN_NOTES' => 'Created as copy of user with ID ' . $userId . ' for "' . $applicationName .'" application',
		];

		$user = new CUser();
		$newUserId = (int)$user->Add($newUserFields);
		if ($newUserId <= 0)
		{
			throw new UserNotGeneratedException($user->LAST_ERROR);
		}

		\CEventLog::Log(
			\CEventLog::SEVERITY_SECURITY,
			'USER_REGISTER',
			'rest',
			$newUserId,
			json_encode([
				'originalUserId' => $userId,
				'applicationName' => $applicationName,
				'newUserId' => $newUserId,
			])
		);

		return $newUserId;
	}

	/**
	 * @param int $userId
	 * @return int[]
	 */
	private static function getUserGroupIds(int $userId): array
	{
		$groupIds = [];
		$groupsResult = CUser::GetUserGroupEx($userId);

		while ($group = $groupsResult->Fetch())
		{
			if (isset($group['GROUP_ID']) && (int)$group['GROUP_ID'] > 0)
			{
				$groupIds[] = (int)($group['GROUP_ID']);
			}
		}

		return array_unique($groupIds);
	}
}