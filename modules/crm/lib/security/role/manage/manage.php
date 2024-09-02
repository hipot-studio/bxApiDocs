<?php

namespace Bitrix\Crm\Security\Role\Manage;

use Bitrix\Crm\Security\Role\Manage\DTO\PermissionModel;
use Bitrix\Crm\Security\Role\Manage\DTO\RoleDTO;
use Bitrix\Crm\Security\Role\Manage\Exceptions\RoleNotFoundException;
use Bitrix\Crm\Security\Role\Model\RoleTable;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use CCrmRole;
use CCrmSaleHelper;

class Manage
{
	private PermissionRepository $permissionRepository;

	private EntitiesBuilder $entitiesBuilder;

	public function __construct()
	{
		$this->entitiesBuilder = EntitiesBuilder::getInstance();
		$this->permissionRepository = PermissionRepository::getInstance();
	}

	/**
	 * @param int|null $roleId
	 * @return RoleData
	 * @throws Exceptions\RoleNotFoundException
	 */
	public function permissions(?int $roleId): RoleData
	{
		$permissionEntities = $this->entitiesBuilder->create();

		if ($roleId > 0)
		{
			$roleDto = $this->getRoleOrThrow($roleId);
			$rolAssignedPermissions = $this->permissionRepository->getRoleAssignedPermissions($roleId);
		}
		else
		{
			$roleDto = RoleDTO::createBlank();
			$rolAssignedPermissions = $this->permissionRepository->getDefaultRoleAssignedPermissions($permissionEntities);
		}

		return new RoleData(
			$roleDto,
			$permissionEntities,
			$rolAssignedPermissions,
			$this->permissionRepository->getTariffRestrictions(),
		);
	}

	/**
	 * @param array $data
	 * @return Result
	 * @throws RoleNotFoundException
	 */
	public function save(array $data): Result
	{
		$tariffResult = $this->checkTariffRestriction();
		if (!$tariffResult->isSuccess())
		{
			return $tariffResult;
		}

		$result = new Result();

		$id = (int)($data['id'] ?? 0);
		$name = $data['name'] ?? '';

		if ($id !== 0)
		{
			$this->getRoleOrThrow($id);
		}


		$validationResult = $this->validateRoleName($name, $id);
		if (!$validationResult->isSuccess())
		{
			return $validationResult;
		}

		if ($id > 0)
		{
			RoleTable::update($id, ['NAME' => $name]);
		}
		else
		{
			$addResult = RoleTable::add(['NAME' => $name]);
			if (!$addResult->isSuccess())
			{
				$result->addError(new Error($addResult->getErrorMessages()[0] ?? ''));

				return $result;
			}
			$id = $addResult->getId();
		}

		$result->setData(['id' => $id]);

		$permissions = $data['permissions'] ?? [];
		$toRemove = PermissionModel::creteFromAppFormBatch($permissions['toRemove'] ?? []);
		$toChange = PermissionModel::creteFromAppFormBatch($permissions['toChange'] ?? []);

		$this->saleUpdateShopAccess();
		$this->clearRolesCache();

		$applyResult = $this->permissionRepository->applyRolePermissionData($id, $toRemove, $toChange);
		if (!$applyResult->isSuccess())
		{
			$result->addError(new Error($applyResult->getErrorMessages()[0] ?? ''));
		}

		return $result;
	}

	public function delete(int $roleId): Result
	{
		$tariffResult = $this->checkTariffRestriction();
		if (!$tariffResult->isSuccess())
		{
			return $tariffResult;
		}

		$CCrmRole = new CCrmRole();
		$CCrmRole->Delete($roleId);

		return new Result();
	}

	private function validateRoleName(string $name, int $roleId): Result
	{
		$result = new Result();

		$crmRole = new CCrmRole();
		$arFields = ['NAME' => $name];
		$crmRole->CheckFields($arFields, $roleId);
		$lastError = $crmRole->GetLastError();

		if (!empty($lastError))
		{
			$lastError = strip_tags($lastError);
			$result->addError(new Error($lastError));
		}

		return $result;
	}

	private function clearRolesCache(): void
	{
		$cache = new \CPHPCache;
		$cache->CleanDir("/crm/list_crm_roles/");

		\CCrmRole::ClearCache();
	}

	private function saleUpdateShopAccess(): void
	{
		CCrmSaleHelper::updateShopAccess();
	}

	private function checkTariffRestriction(): Result
	{
		$result = new Result();
		$restriction = $this->permissionRepository->getTariffRestrictions();

		if (!$restriction->hasPermission())
		{
			$result->addError(new Error(Loc::getMessage('CRM_SECURITY_ROLE_PERMISSION_DENIED')));
		}

		return $result;
	}

	/**
	 * @param int $roleId
	 * @return RoleDTO
	 * @throws RoleNotFoundException
	 */
	private function getRoleOrThrow(int $roleId): RoleDTO
	{
		$roleDto = $this->permissionRepository->getRole($roleId);

		if ($roleDto === null)
		{
			throw new RoleNotFoundException(Loc::getMessage('CRM_SECURITY_ROLE_PERMISSION_DENIED'));
		}

		return $roleDto;
	}
}