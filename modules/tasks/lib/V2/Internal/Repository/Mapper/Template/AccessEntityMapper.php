<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Mapper\Template;

use Bitrix\Main\Access\AccessCode;
use Bitrix\Tasks\V2\Internal\Entity\GroupCollection;
use Bitrix\Tasks\V2\Internal\Entity\Template\Access\AccessEntity;
use Bitrix\Tasks\V2\Internal\Entity\Template\Access\AccessEntityCollection;
use Bitrix\Tasks\V2\Internal\Entity\Template\Access\AccessEntityType;
use Bitrix\Tasks\V2\Internal\Entity\UserCollection;
use Bitrix\Tasks\V2\Internal\Integration\HumanResources\Entity\DepartmentCollection;

class AccessEntityMapper
{
	private const ACCESS_CODE_USER = 'U';
	private const ACCESS_CODE_GROUP = 'SG';
	private const ACCESS_CODE_DEPARTMENT = 'DR';
	private const ACCESS_CODE_ALL_USERS = 'UA';

	public function mapToAccessCode(AccessEntity $accessEntity): ?string
	{
		$accessCodePrefix = match (true)
		{
			in_array($accessEntity->type, AccessEntityType::getUserTypes(), true) => static::ACCESS_CODE_USER,
			in_array($accessEntity->type, AccessEntityType::getGroupTypes(), true) => static::ACCESS_CODE_GROUP,
			$accessEntity->type === AccessEntityType::Department => static::ACCESS_CODE_DEPARTMENT,
			$accessEntity->type === AccessEntityType::AllUsers => static::ACCESS_CODE_ALL_USERS,
			default => null,
		};

		if ($accessCodePrefix === null)
		{
			return null;
		}

		if ($accessCodePrefix === static::ACCESS_CODE_ALL_USERS)
		{
			return $accessCodePrefix;
		}

		return $accessCodePrefix . $accessEntity->id;
	}

	public function mapToCollection(
		UserCollection $users,
		GroupCollection $groups,
		DepartmentCollection $departments,
	): AccessEntityCollection
	{
		$collection = new AccessEntityCollection();
		foreach ($users as $user)
		{
			$userAccessEntity = new AccessEntity(
				id: $user->getId(),
				name: $user->name,
				image: $user->image,
				type: AccessEntityType::User,
			);

			$collection->add($userAccessEntity);
		}

		foreach ($groups as $group)
		{
			$groupAccessEntity = new AccessEntity(
				id: $group->getId(),
				name: $group->name,
				image: $group->image,
				type: AccessEntityType::Group,
			);

			$collection->add($groupAccessEntity);
		}

		foreach ($departments as $department)
		{
			$accessCode = new AccessCode((string)$department->accessCode);
			$id = $accessCode->getEntityId();
			$departmentAccessEntity = new AccessEntity(
				id: $id,
				name: $department->name,
				image: $department->image,
				type: AccessEntityType::Department,
			);

			$collection->add($departmentAccessEntity);
		}

		return $collection;
	}
}
