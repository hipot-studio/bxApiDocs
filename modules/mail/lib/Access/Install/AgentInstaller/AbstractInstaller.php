<?php

declare(strict_types=1);

namespace Bitrix\Mail\Access\Install\AgentInstaller;

use Bitrix\Mail\Access\Install\AccessInstaller;
use Bitrix\Mail\Access\Repository\RoleRepository;
use Bitrix\Mail\Access\Role\RoleDictionary;
use Bitrix\Mail\Access\Role\RoleUtil;
use Bitrix\Main\Access\AccessCode;
use Bitrix\Main\Access\Exception\RoleRelationSaveException;
use Bitrix\Main\Access\Exception\RoleSaveException;
use Bitrix\Main\ArgumentOutOfRangeException;

abstract class AbstractInstaller implements AgentInstallerInterface
{
	protected AccessInstaller $accessInstaller;
	protected RoleRepository $roleRepository;

	public function __construct()
	{
		$this->accessInstaller = new AccessInstaller();
		$this->roleRepository = new RoleRepository();
	}

	/**
	 * @throws RoleRelationSaveException
	 */
	abstract protected function run(): void;

	/**
	 * @return string
	 * @throws RoleRelationSaveException|ArgumentOutOfRangeException
	 */
	public function install(): string
	{
		$version = array_flip(InstallerFactory::getVersionMap())[static::class];

		if ($this->accessInstaller->getAccessVersion() > $version)
		{
			return '';
		}

		$this->run();

		$this->accessInstaller->setActualAccessVersion($version);

		return '';
	}

	/**
	 * @param array $roles
	 * @param bool $ignoreExists
	 *
	 * @return void
	 * @throws RoleRelationSaveException
	 */
	protected function fillDefaultSystemPermissions(
		array $roles,
		bool $ignoreExists = false,
	): void
	{
		if (!$ignoreExists && $this->roleRepository->areRolesDefined())
		{
			return;
		}

		foreach ($roles as $roleName => $rolePermissions)
		{
			try
			{
				$roleId = $this->roleRepository->create($roleName);
				$role = new RoleUtil($roleId);
			}
			catch (RoleSaveException $e)
			{
				continue;
			}

			$this->installRelation($roleName, $role);

			if (!empty($rolePermissions))
			{
				try
				{
					$this->roleRepository->updatePermissionsFromMap($role, $rolePermissions);
				}
				catch (\Exception $e)
				{
				}
			}
		}
	}

	/**
	 * @throws RoleRelationSaveException
	 */
	protected function installRelation(
		int|string $roleName,
		RoleUtil $role,
	): void
	{
		$relation = self::getRelation($roleName);
		if ($relation)
		{
			$role->updateRoleRelations(array_flip([$relation]));
		}
	}

	protected static function getRelation(int|string $roleName): ?string
	{
		return match ($roleName)
		{
			RoleDictionary::ROLE_DIRECTOR => AccessCode::ACCESS_DIRECTOR . '0',
			RoleDictionary::ROLE_EMPLOYEE => AccessCode::ACCESS_EMPLOYEE . '0',
			default => null,
		};
	}
}
