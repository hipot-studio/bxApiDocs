<?php

namespace Bitrix\Crm\Security\Role\UIAdapters\AccessRights\Commands;


use Bitrix\Crm\Category\PermissionEntityTypeHelper;
use Bitrix\Crm\Security\Notification\PermissionNotificationManager;
use Bitrix\Crm\Security\Role\GroupCodeGenerator;
use Bitrix\Crm\Security\Role\Manage\AttrPreset\UserDepartmentAndOpened;
use Bitrix\Crm\Security\Role\Manage\DTO\PermissionModel;
use Bitrix\Crm\Security\Role\Manage\RoleManagementModelBuilder;
use Bitrix\Crm\Security\Role\Repositories\PermissionRepository;
use Bitrix\Crm\Security\Role\UIAdapters\AccessRights\Commands\DTO\AccessCodeDTO;
use Bitrix\Crm\Security\Role\UIAdapters\AccessRights\Commands\DTO\AccessRightDTO;
use Bitrix\Crm\Security\Role\UIAdapters\AccessRights\Commands\DTO\UserGroupsData;
use Bitrix\Crm\Security\Role\UIAdapters\AccessRights\PermIdentifier;
use Bitrix\Crm\Security\Role\UIAdapters\AccessRights\Utils\PermCodeTransformer;
use Bitrix\Crm\Security\Role\Utils\RoleManagerUtils;
use Bitrix\Crm\Security\Role\Utils\RolePermissionChecker;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\Traits\Singleton;
use Bitrix\Main\Result;

class UpdateRoleCommand
{
	use Singleton;

	private PermissionRepository $permissionRepository;
	private RoleManagerUtils $utils;

	private function __construct()
	{
		$this->permissionRepository = PermissionRepository::getInstance();
		$this->utils = RoleManagerUtils::getInstance();
	}

	/**
	 * @param UserGroupsData[] $userGroups
	 * @return Result
	 */
	public function execute(array $userGroups): Result
	{
		$result = new Result();

		$userGroupWithRoleIds = [];
		foreach ($userGroups as $userGroup)
		{
			$roleId = $this->saveRoleNameAndCode($userGroup);

			$userGroupWithRoleIds[$roleId] = $userGroup;
		}

		$oldRoleRelations = $this->permissionRepository->queryRolesRelations(array_keys($userGroupWithRoleIds));

		foreach ($userGroupWithRoleIds as $roleId => $userGroup)
		{
			$saveRes = $this->saveRolePerms($roleId, $userGroup->accessRights);
			if (!$saveRes->isSuccess())
			{
				$result->addErrors($saveRes->getErrors());
			}

			$accessCodeIds = $this->getAccessCodeIds($userGroup->accessCodes);

			$this->permissionRepository->saveRoleRelations($roleId, $accessCodeIds);
		}

		$this->sendNotifications($userGroups, $oldRoleRelations);

		$this->utils->saleUpdateShopAccess();
		$this->utils->clearRolesCache();

		return $result;
	}

	/**
	 * @param int $roleId
	 * @param AccessRightDTO[] $accessRights
	 * @return Result
	 */
	private function saveRolePerms(int $roleId, array $accessRights): Result
	{
		$permissionModels = $this->uiAccessRightsToPermissionsModel($accessRights);
		[$toChange, $toRemove] = $this->dividePermissionModels($permissionModels);

		return $this->permissionRepository->applyRolePermissionData($roleId, $toRemove, $toChange);
	}

	/**
	 * @param UserGroupsData $userGroup
	 * @return int role id
	 */
	private function saveRoleNameAndCode(UserGroupsData $userGroup): int
	{
		return $this->permissionRepository->updateOrCreateRole($userGroup->id, $userGroup->title, $userGroup->groupCode);
	}

	/**
	 * @param AccessCodeDTO[] $accessCodes
	 * @param $result array<string>
	 */
	private function getAccessCodeIds(array $accessCodes): array
	{
		$result = [];
		foreach ($accessCodes as $code)
		{
			$result[] = $code->id;
		}

		return $result;
	}

	/**
	 * @param AccessRightDTO[] $accessRights
	 * @return array[]
	 */
	private function uiAccessRightsToPermissionsModel(array $accessRights): array
	{
		$permissions = [];

		foreach ($accessRights as $right)
		{
			$uiRightId = $right->id;

			$codeDTO = PermCodeTransformer::getInstance()->decodeAccessRightCode($uiRightId);

			if (!isset($permissions[$uiRightId]))
			{
				$permissions[$uiRightId] = [
					'permissionCode' => $codeDTO->permCode,
					'entityCode' => $codeDTO->entityCode,
					'stageField' => $codeDTO->field,
					'stageCode' => $codeDTO->fieldValue,
					'value' => [],
					'settings' => []
				];
			}
			if (is_array($right->value))
			{
				$permissions[$uiRightId]['value'] = $right->value;
			}
			elseif ($right->value !== null)
			{
				$permissions[$uiRightId]['value'][] = $right->value;
			}
		}

		$roleModelBuilder = RoleManagementModelBuilder::getInstance();
		foreach ($permissions as $uiRightId => $permission)
		{
			$controlType = $roleModelBuilder->getPermissionByCode($permission['entityCode'], $permission['permissionCode'])?->getControlMapper();
			if ($controlType)
			{
				$permissionValue = $permission['value'];
				$permissions[$uiRightId]['value'] = $controlType->getAttrFromUiValue($permissionValue);
				$permissions[$uiRightId]['settings'] = $controlType->getSettingsFromUiValue($permissionValue);
			}
			else
			{
				unset($permissions[$uiRightId]);
			}
		}

		return array_values($permissions);
	}

	/**
	 * @param array[] $permissions
	 * @return array{PermissionModel[], PermissionModel[]}
	 */
	private function dividePermissionModels(array $permissions): array
	{
		$toRemove = [];
		$toChange = [];
		foreach ($permissions as $permission)
		{
			$model = new PermissionModel(
				$permission['entityCode'],
				$permission['permissionCode'],
				$permission['stageField'],
				$permission['stageCode'],
				$permission['value'],
				$permission['settings'],
			);

			if ($this->shouldRemovePermissionModel($model))
			{
				$toRemove[] = $model;
			}
			else
			{
				$toChange[] = $model;
			}
		}

		return [$toChange, $toRemove];
	}

	private function shouldRemovePermissionModel(PermissionModel $model): bool
	{
		// stage permission should be able to override its parent
		if (!empty($model->field()) && $model->attribute() === UserPermissions::PERMISSION_NONE)
		{
			return false;
		}

		return empty($model->attribute()) && empty($model->settings());
	}

	/**
	 * @param UserGroupsData[] $userGroups
	 * @param array $oldRoleRelations
	 * @return void
	 */
	private function sendNotifications(array $userGroups, array $oldRoleRelations): void
	{
		$notificationsByAutomatedSolutions = $this->getNotificationsForAutomatedSolutions($userGroups, $oldRoleRelations);

		$permissionNotificationManager = PermissionNotificationManager::getInstance();
		foreach ($notificationsByAutomatedSolutions as $data)
		{
			if ($data['categoryIdentifier'] === null)
			{
				continue;
			}

			$permissionNotificationManager->notifyOnRoleRelationsChange(
				$data['automatedSolutionId'],
				$data['categoryIdentifier'],
				UserPermissions::OPERATION_READ,
				$data['newAccessCodes'],
				$data['oldAccessCodes'],
			);
		}
	}

	private function getNotificationsForAutomatedSolutions(array $userGroups, array $oldRoleRelations): array
	{
		$notificationsByAutomatedSolutions = [];

		foreach ($userGroups as $userGroup)
		{
			$groupCode = $userGroup->groupCode;

			if ($groupCode === null)
			{
				continue;
			}

			$automatedSolutionId = GroupCodeGenerator::getAutomatedSolutionIdFromGroupCode($groupCode);
			if ($automatedSolutionId === null)
			{
				continue;
			}

			$permissions = $this->getPreparedPermissions($userGroup);

			foreach ($permissions as $permission)
			{
				$permissionModel = PermissionModel::creteFromAppForm($permission);

				if (
					$permissionModel->permissionCode() === UserPermissions::OPERATION_READ
					&& !RolePermissionChecker::isPermissionEmpty($permissionModel))
				{
					$categoryIdentifier = PermissionEntityTypeHelper::extractEntityAndCategoryFromPermissionEntityType($permissionModel->entity());

					$notificationsByAutomatedSolutions[] = [
						'automatedSolutionId' => $automatedSolutionId,
						'categoryIdentifier' => $categoryIdentifier,
						'newAccessCodes' => $this->getAccessCodeIds($userGroup->accessCodes),
						'oldAccessCodes' => array_column(
							array_filter(
								$oldRoleRelations,
								static fn($item) => (int)$item['ROLE_ID'] === $userGroup->id,
							),
							'RELATION',
						),
					];
				}
			}
		}

		return $notificationsByAutomatedSolutions;
	}

	private function getPreparedPermissions(UserGroupsData $userGroup): array
	{
		if (empty($userGroup->accessRights))
		{
			$roles = $this->permissionRepository->getAutomatedSolutionRoles();
			$role = $roles[$userGroup->id] ?? [];
			$permissions = $role['PERMISSIONS']?->collectValues() ?? [];
			$accessRights = [];
			foreach ($permissions as $permission)
			{
				$permCode = PermCodeTransformer::getInstance()->makeAccessRightPermCode(
					new PermIdentifier($permission['ENTITY'], $permission['PERM_TYPE']),
				);
				$permValue = empty($permission['ATTR'])
					? $permission['SETTINGS']
					: (new UserDepartmentAndOpened())->convertSingleToMultiValue($permission['ATTR']);

				$accessRights[] = new AccessRightDTO($permCode, $permValue);
			}
		}
		else
		{
			$accessRights = $userGroup->accessRights;
		}

		return $this->uiAccessRightsToPermissionsModel($accessRights);
	}
}
