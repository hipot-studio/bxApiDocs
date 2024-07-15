<?php

namespace Bitrix\Crm\Security\EntityPermission;

use Bitrix\Crm\Security\Role\Manage\Permissions\Permission;

final class DefaultPermission
{
	public static function createFromArray(array $data): ?self
	{
		if (is_subclass_of($data['permissionClass'], Permission::class))
		{
			$permission = new $data['permissionClass'];

			return new self($permission, $data['attr']);
		}

		return null;
	}

	public function __construct(
		private readonly Permission $permission,
		private readonly string $attr = ''
	)
	{

	}

	public function getPermissionType(): string
	{
		return $this->permission->code();
	}

	public function getPermissionClass(): string
	{
		return $this->permission::class;
	}

	public function getAttr(): string
	{
		return $this->attr;
	}

	public function toArray(): array
	{
		return [
			'permissionClass' => $this->getPermissionClass(),
			'permissionType' => $this->getPermissionType(),
			'attr' => $this->getAttr(),
		];
	}
}
