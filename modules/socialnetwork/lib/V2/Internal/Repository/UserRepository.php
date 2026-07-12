<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Repository;

use Bitrix\Main\Type\Collection;
use Bitrix\Main\UserTable;

class UserRepository implements UserRepositoryInterface
{
	public function getByIds(array $userIds): array
	{
		if (empty($userIds))
		{
			return [];
		}

		Collection::normalizeArrayValuesByInt($userIds, false);

		if (empty($userIds))
		{
			return [];
		}

		return UserTable::query()
			->setSelect([
				'ID',
				'PERSONAL_PHOTO',
				'NAME',
				'LAST_NAME',
				'SECOND_NAME',
				'EXTERNAL_AUTH_ID',
				'UF_DEPARTMENT',
				'PERSONAL_GENDER',
				'EMAIL',
				'LOGIN',
			])
			->whereIn('ID', $userIds)
			->exec()
			->fetchAll();
	}
}
