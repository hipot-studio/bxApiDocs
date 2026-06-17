<?php

namespace Bitrix\HumanResources\Access\Model;

use Bitrix\HumanResources\Service\Container;
use Bitrix\Main\Access\AccessibleItem;

final class UserModel extends \Bitrix\Main\Access\User\UserModel implements AccessibleItem
{
	private array $permissions = [];

	/**
	 * AccessibleItem method
	 *
	 * @param int|null $itemId
	 * @return static
	 */
	public static function createFromId(?int $itemId): static
	{
		return parent::createFromId($itemId);
	}

	/**
	 * AccessibleItem method
	 *
	 * @return int
	 */
	public function getId(): int
	{
		return $this->userId ?? 0;
	}

	/**
	 * returns user roles in system
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

			$this->roles = Container::getAccessRoleRelationRepository()->getRolesByRelationCodes($this->getAccessCodes());
		}
		return $this->roles;
	}

	/**
	 * Returns permission if exists
	 * @param string $permissionId string identification
	 * @return int|null
	 */
	public function getPermission(string $permissionId): ?int
	{
		$permissions = $this->getPermissions();
		if (array_key_exists($permissionId, $permissions))
		{
			return $permissions[$permissionId];
		}

		return null;
	}

	/**
	 * Builds access codes using CAccess to get all codes (including structure-based).
	 *
	 * @return array<string>
	 */
	protected function buildAccessCodes(): array
	{
		return array_values(\CAccess::GetUserCodesArray($this->userId) ?? []);
	}

	/**
	 * Returns array of permissions with value
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

			$this->permissions = Container::getAccessPermissionRepository()->getPermissionsByRoleIds($rolesIds);
		}

		return $this->permissions;
	}
}