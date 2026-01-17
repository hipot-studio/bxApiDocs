<?php

namespace Bitrix\Mail\Access\Model;

use Bitrix\Mail\Access\Repository\PermissionRepository;
use Bitrix\Mail\Access\Repository\RoleRelationRepository;
use Bitrix\Main\Access\User\UserModel as BaseUserModel;

final class UserModel extends BaseUserModel
{
	private RoleRelationRepository $roleRelationRepository;
	private PermissionRepository $permissionRepository;
	private array $permissions = [];

	public function __construct()
	{
		$this->roleRelationRepository = new RoleRelationRepository();
		$this->permissionRepository = new PermissionRepository();

		parent::__construct();
	}

	/**
	 * @return array<int>
	 */
	public function getRoles(): array
	{
		if ($this->roles === null)
		{
			$this->roles = [];
			if ($this->userId === 0 || empty($this->getAccessCodes()))
			{
				return $this->roles;
			}

			$this->roles = $this->roleRelationRepository->getRolesByRelationCodes($this->getAccessCodes());
		}

		return $this->roles;
	}

	public function getPermission(string $permissionId): ?int
	{
		$permissions = $this->getPermissions();

		return $permissions[$permissionId] ?? null;
	}

	/**
	 * @return array<array-key, int>
	 */
	private function getPermissions(): array
	{
		if (!$this->permissions)
		{
			$this->permissions = [];
			$rolesIds = $this->getRoles();

			if (empty($rolesIds))
			{
				return $this->permissions;
			}

			$this->permissions = $this->permissionRepository->getEffectivePermissionsByRoleIds($rolesIds);
		}

		return $this->permissions;
	}
}
