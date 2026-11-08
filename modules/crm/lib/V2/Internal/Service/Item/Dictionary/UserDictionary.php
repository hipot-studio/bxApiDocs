<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Item\Dictionary;

use Bitrix\Crm\Item;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Main\Loader;
use Bitrix\Main\UserTable;

/**
 * @internal
 */
class UserDictionary
{
	/**
	 * @param int[] $filterUsersId
	 * @return int[]
	 */
	public function getAvailableAssignedUserIds(EntityType $entityType, int $userId, array $filterUsersId): array
	{
		if ($userId <= 0 || empty($filterUsersId))
		{
			return [];
		}

		if ($this->canAssignOnlyCurrentUser($entityType, $userId))
		{
			return [$userId];
		}

		return $this->getAvailableUsersId($filterUsersId);
	}

	/**
	 * @param int[] $filterUsersId
	 * @return int[]
	 */
	public function getAvailableObserverUserIds(
		int $userId,
		array $filterUsersId,
		bool $checkPermissions = true,
	): array
	{
		if (($checkPermissions && $userId <= 0) || empty($filterUsersId))
		{
			return [];
		}

		return $this->getAvailableUsersId($filterUsersId);
	}

	private function canAssignOnlyCurrentUser(EntityType $entityType, int $userId): bool
	{
		$factory = Container::getInstance()->getFactory($entityType->getId());
		if ($factory === null)
		{
			return false;
		}

		$item = $factory->createItem([
			Item::FIELD_NAME_ASSIGNED => $userId,
		]);

		return Container::getInstance()
			->getUserPermissions($userId)
			->item()
			->canAddOnlySelfAssignedItems($item)
		;
	}

	/**
	 * @param int[] $filterUsersId
	 * @return int[]
	 */
	private function getAvailableUsersId(array $filterUsersId): array
	{
		$userList = UserTable::query()
			->setSelect(['ID'])
			->where('ACTIVE', 'Y')
			->where('REAL_USER', 'expr', true)
			->whereIn('ID', array_values(array_unique($filterUsersId)))
			->exec()
		;

		$result = [];
		$isCheckIntranet = Loader::includeModule('intranet');
		while ($user = $userList->fetch())
		{
			$userId = (int)$user['ID'];
			if (!$isCheckIntranet || \Bitrix\Intranet\Util::isIntranetUser($userId))
			{
				$result[] = $userId;
			}
		}

		return $result;
	}
}
