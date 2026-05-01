<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Repository\SystemUser;

use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\Repository\RepositoryInterface;
use Bitrix\Rest\Internal\Entity\SystemUser;
use Bitrix\Rest\Internal\Entity\SystemUser\ResourceType;
use Bitrix\Rest\Internal\Models\SystemUserTable;
use Bitrix\Rest\Internal\Repository\Mapper\SystemUserMapper;

class SystemUserRepository implements RepositoryInterface
{
	public function __construct(private readonly SystemUserMapper $mapper)
	{
	}

	public function getByResourceIdAndResourceType(int $resourceId, ResourceType $resourceType): ?SystemUser
	{
		$systemUserObject = SystemUserTable::query()
			->where('RESOURCE_ID', $resourceId)
			->where('RESOURCE_TYPE', $resourceType->value)
			->setLimit(1)
			->setOrder(['USER_ID' => 'DESC'])
			->fetchObject();

		if ($systemUserObject !== null)
		{
			return $this->mapper->convertFromOrm($systemUserObject);
		}

		return null;
	}

	public function deleteByUserId(int $userId): void
	{
		$systemUser = SystemUserTable::query()->where('USER_ID', $userId)->fetchObject();

		if ($systemUser !== null)
		{
			$systemUser->delete();
		}
	}

	public function getById(mixed $id): ?EntityInterface
	{
		$systemUserObject = SystemUserTable::query()->where('ID', $id)->fetchObject();
		if ($systemUserObject !== null)
		{
			return $this->mapper->convertFromOrm($systemUserObject);
		}

		return null;
	}

	/**
	 * @param SystemUser $entity
	 * @return void
	 * @throws \Exception
	 */
	public function save(EntityInterface $entity): void
	{
		$data = [
			'USER_ID' => $entity->getUserId(),
			'ACCOUNT_TYPE' => $entity->getAccountType()?->value,
			'RESOURCE_TYPE' => $entity->getResourceType()?->value,
			'RESOURCE_ID' => $entity->getResourceId(),
		];

		if ($entity->getId() === null)
		{
			$addResult = SystemUserTable::add($data);

			if ($addResult->isSuccess())
			{
				$entity->setId($addResult->getId());
			}
		}
		else
		{
			SystemUserTable::update($entity->getId(), $data);
		}
	}

	public function delete(mixed $id): void
	{
		SystemUserTable::delete($id);
	}
}
