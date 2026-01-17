<?php

namespace Bitrix\DocumentGenerator\Integration\UI\AccessRights\V2\Models;

use Bitrix\UI\AccessRights\V2\Contract\AccessRightsBuilder\Provider\Structure\Entity;
use Bitrix\UI\AccessRights\V2\Contract\AccessRightsBuilder\Provider\Structure\Permission;

final class RightId implements \Bitrix\UI\AccessRights\V2\Contract\AccessRightsBuilder\Provider\Models\RightId
{
	public function __construct(
		public readonly string $entityId,
		public readonly string $actionId,
	)
	{
	}

	public function isEntityEquals(Entity $entity): bool
	{
		return $entity->getId() ===  $this->entityId;
	}

	public function isPermissionEquals(Permission $permission): bool
	{
		return $permission->getAction()->getId() === $this->actionId;
	}
}
