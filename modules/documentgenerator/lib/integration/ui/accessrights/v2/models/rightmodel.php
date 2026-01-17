<?php

namespace Bitrix\DocumentGenerator\Integration\UI\AccessRights\V2\Models;

use Bitrix\DocumentGenerator\Model\RolePermission;

final class RightModel implements \Bitrix\UI\AccessRights\V2\Contract\AccessRightsBuilder\Provider\Models\RightModel
{
	public function __construct(
		public readonly string $entityId,
		public readonly string $actionId,
		public readonly string $attribute,
	)
	{
	}

	public function getValue(): string
	{
		return $this->attribute;
	}

	public static function createFromPermission(RolePermission $permission): self
	{
		return new self(
			$permission->getEntity(),
			$permission->getAction(),
			$permission->getPermission(),
		);
	}
}
