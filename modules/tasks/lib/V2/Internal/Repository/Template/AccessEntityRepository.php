<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Template;

use Bitrix\Main\Access\AccessCode;
use Bitrix\Tasks\V2\Internal\Entity\Template\Access\AccessEntityCollection;
use Bitrix\Tasks\V2\Internal\Entity\Template\Access\AccessEntityType;
use Bitrix\Tasks\V2\Internal\Integration\HumanResources\Repository\StructureRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\GroupRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\Template\AccessEntityMapper;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\Template\AccessEntityTypeMapper;
use Bitrix\Tasks\V2\Internal\Repository\UserRepositoryInterface;

class AccessEntityRepository implements AccessEntityRepositoryInterface
{
	public function __construct(
		private readonly UserRepositoryInterface $userRepository,
		private readonly GroupRepositoryInterface $groupRepository,
		private readonly StructureRepositoryInterface $structureRepository,
		private readonly AccessEntityMapper $accessEntityMapper,
		private readonly AccessEntityTypeMapper $accessEntityTypeMapper,
	)
	{

	}

	public function getByAccessCodes(array $accessCodes): AccessEntityCollection
	{
		$users = [];
		$departments = [];
		$groups = [];
		foreach ($accessCodes as $code)
		{
			$code = (string)$code;
			$accessCode = new AccessCode($code);
			$type = $this->accessEntityTypeMapper->mapToEnum($accessCode->getEntityType());

			if (in_array($type, AccessEntityType::getUserTypes(), true))
			{
				$users[] = $accessCode->getEntityId();
			}
			elseif (in_array($accessCode->getEntityPrefix(), ['D', 'DR'], true))
			{
				$code = str_replace('DR', 'D', $code); // for HR API
				$departments[] = $code;
			}
			elseif (in_array($type, AccessEntityType::getGroupTypes(), true))
			{
				$groups[] = $accessCode->getEntityId();
			}
		}

		$groups = $this->groupRepository->getByIds($groups);
		$users = $this->userRepository->getByIds($users);
		$departments = $this->structureRepository->getDepartmentsByAccessCodes($departments);

		return $this->accessEntityMapper->mapToCollection(
			users: $users,
			groups: $groups,
			departments: $departments,
		);
	}
}
