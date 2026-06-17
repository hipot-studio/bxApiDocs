<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Internals\Repository\Structure;

use Bitrix\HumanResources\Item\Collection\UserCollection;
use Bitrix\HumanResources\Item\User;
use Bitrix\Main\UserTable;

final class UserRepository
{
	public function getById(int $userId): ?User
	{
		$model = UserTable::getById($userId)->fetch();

		if (!$model)
		{
			return null;
		}

		return $this->extractItemFromModel($model);
	}

	/**
	 * @param int[] $userIds
	 */
	public function getByIds(array $userIds): UserCollection
	{
		if (empty($userIds))
		{
			return new UserCollection();
		}

		$rows = UserTable::query()
			->setSelect([
				'ID',
				'LOGIN',
				'NAME',
				'LAST_NAME',
				'SECOND_NAME',
				'PERSONAL_PHOTO',
				'WORK_POSITION',
				'PERSONAL_GENDER',
				'CONFIRM_CODE',
				'ACTIVE',
			])
			->whereIn('ID', $userIds)
			->fetchAll()
		;

		return new UserCollection(
			...array_map([$this, 'extractItemFromModel'], $rows),
		);
	}

	private function extractItemFromModel(array $model): User
	{
		return new User(
			(int)$model['ID'],
			$model['LOGIN'],
			$model['NAME'],
			$model['LAST_NAME'],
			$model['SECOND_NAME'],
			(int)$model['PERSONAL_PHOTO'],
			$model['WORK_POSITION'],
			$model['PERSONAL_GENDER'],
			active: ($model['ACTIVE'] ?? 'Y') === 'Y',
			hasConfirmCode: isset($model['CONFIRM_CODE']) && $model['CONFIRM_CODE'] !== '',
		);
	}
}
