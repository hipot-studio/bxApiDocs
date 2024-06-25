<?php

namespace Bitrix\TasksMobile\Controller;

use Bitrix\Mobile\Provider\UserRepository;
use Bitrix\Tasks\Ui\Avatar;

class User extends Base
{
	protected function getQueryActionNames(): array
	{
		return [
			'getUsersData',
			'getUsersDataLegacy',
		];
	}

	public function getUsersDataAction(array $userIds): array
	{
		return UserRepository::getByIds($userIds);
	}

	public function getUsersDataLegacyAction(array $userIds): array
	{
		if (empty($userIds))
		{
			return [];
		}

		$users = [];
		$userResult = \CUser::GetList(
			'id',
			'asc',
			['ID' => implode('|', $userIds)],
			['FIELDS' => ['ID', 'NAME', 'SECOND_NAME', 'LAST_NAME', 'LOGIN', 'PERSONAL_PHOTO', 'WORK_POSITION']]
		);
		while ($user = $userResult->Fetch())
		{
			$userId = (int)$user['ID'];
			$userName = \CUser::FormatName(
				\CSite::GetNameFormat(),
				[
					'LOGIN' => $user['LOGIN'],
					'NAME' => $user['NAME'],
					'LAST_NAME' => $user['LAST_NAME'],
					'SECOND_NAME' => $user['SECOND_NAME'],
				],
				true,
				false
			);

			$users[$userId] = [
				'id' => $userId,
				'name' => $userName,
				'icon' => Avatar::getPerson($user['PERSONAL_PHOTO']),
				'workPosition' => $user['WORK_POSITION'],
			];
		}

		return $users;
	}
}