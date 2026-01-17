<?php

namespace Bitrix\DocumentGenerator\Integration\UI\AccessRights\V2;

use Bitrix\DocumentGenerator\Integration\UI\AccessRights\V2\Models\RightId;
use Bitrix\DocumentGenerator\Integration\UI\AccessRights\V2\Models\RightIdConverter;
use Bitrix\DocumentGenerator\Integration\UI\AccessRights\V2\Models\RightModel;
use Bitrix\DocumentGenerator\Integration\UI\AccessRights\V2\Structure\Entity\Document;
use Bitrix\DocumentGenerator\Integration\UI\AccessRights\V2\Structure\Entity\Settings;
use Bitrix\DocumentGenerator\Integration\UI\AccessRights\V2\Structure\Entity\Template;
use Bitrix\DocumentGenerator\Repository\RoleRepository;
use Bitrix\UI\AccessRights\V2\Contract\AccessRightsBuilder\Provider;
use Bitrix\UI\AccessRights\V2\Dto\AccessRightsBuilder\UserGroupModelDto;

final class AccessRightsProvider implements Provider
{
	public function __construct(
		private readonly RoleRepository $repository,
	)
	{
	}

	public function loadEntities(): array
	{
		return [
			new Settings(),
			new Template(),
			new Document(),
		];
	}

	/**
	 * @return UserGroupModelDto[]
	 */
	public function loadUserGroupModels(): array
	{
		$userGroupModels = [];

		foreach ($this->repository->fetchAll() as $role)
		{
			$userGroupModel = new UserGroupModelDto($role->getId(), $role->getName());
			foreach ($role->getPermissions()?->getAll() as $permission)
			{
				$userGroupModel->addAccessRightModel(
					RightModel::createFromPermission($permission),
				);
			}

			foreach ($role->getAccesses()?->getAll() as $access)
			{
				$userGroupModel->addAccessCode($access->getAccessCode());
			}

			$userGroupModels[] = $userGroupModel;
		}

		return $userGroupModels;
	}

	public function getRightIdConverter(): Provider\Models\RightIdConverter
	{
		return new RightIdConverter();
	}

	public function createRightModelByRightId(Provider\Models\RightId|RightId $id, mixed $value): ?RightModel
	{
		return new RightModel($id->entityId, $id->actionId, $value);
	}
}
