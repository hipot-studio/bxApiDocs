<?php

namespace Bitrix\Rest\Internal\Repository\User;

use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\Repository\RepositoryInterface;
use Bitrix\Main\UserTable;
use Bitrix\Rest\Internal\Entity\User;
use Bitrix\Rest\Internal\Exceptions\Service\SystemUser\UserNotGeneratedException;
use Bitrix\Rest\Internal\Repository\Mapper\UserMapper;
use CUser;

final class UserRepository implements RepositoryInterface
{
	public function __construct(private readonly UserMapper $mapper)
	{
	}

	/**
	 * @param User $entity
	 * @return void
	 * @throws UserNotGeneratedException
	 */
	public function save(EntityInterface $entity): void
	{
		if ($entity->getId())
		{
			$user = new CUser();
			$user->Update($entity->getId(), $entity->toArray());
		}
		else
		{
			$user = new CUser();
			$newUserId = (int)$user->Add($entity->toArray());
			if ($newUserId <= 0)
			{
				throw new UserNotGeneratedException($user->LAST_ERROR);
			}
			$entity->setId($newUserId);
		}
	}

	public function delete(mixed $id): void
	{
		CUser::Delete($id);
	}

	public function getById(mixed $id): ?EntityInterface
	{
		$userObject = UserTable::query()
			->setSelect(['ID', 'ACTIVE', 'LOGIN', 'EMAIL', 'NAME', 'LAST_NAME', 'TIME_ZONE', 'LANGUAGE_ID', 'ADMIN_NOTES'])
			->where('ID', $id)
			->setLimit(1)
			->fetchObject();

		if (!$userObject)
		{
			return null;
		}

		$user = $this->mapper->convertFromOrm($userObject);
		$user->setGroupIds($this->getGroupIdsByUserId($user->getId()));

		return $user;
	}

	private function getGroupIdsByUserId($userId): array
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