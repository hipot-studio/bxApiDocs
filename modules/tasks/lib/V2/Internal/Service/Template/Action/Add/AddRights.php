<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Add;

use Bitrix\Main\Access\Permission\PermissionDictionary;
use Bitrix\Tasks\Access\Permission\TasksTemplatePermissionTable;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Integration\HumanResources\Repository\StructureRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Add\Config\AddConfig;

class AddRights
{
	public function __construct(
		private readonly AddConfig $config
	)
	{

	}

	public function __invoke(array $fields): void
	{
		$currentUserId = $this->config->getUserId();
		$creatorId = (int)$fields['CREATED_BY'];
		$permissions = $fields['PERMISSIONS'] ?? [];

		$permissions = $this->addUserPermission($currentUserId, $permissions);
		if ($creatorId !== $currentUserId)
		{
			$permissions = $this->addUserPermission($creatorId, $permissions);
		}

		$this->addPermissions($fields['ID'], $permissions);
	}

	private function addUserPermission(int $userId, array $permissions): array
	{
		$grantedCode = 'U' . $userId;
		$grantedPermissionId = \Bitrix\Tasks\Access\Permission\PermissionDictionary::TEMPLATE_FULL;

		$hasPermission = false;

		foreach ($permissions as $permission)
		{
			$accessCode = $permission['ACCESS_CODE'] ?? null;
			$permissionId = $permission['PERMISSION_ID'] ?? null;

			if ($accessCode === $grantedCode)
			{
				if ($permissionId !== $grantedPermissionId)
				{
					$permission['PERMISSION_ID'] = $grantedPermissionId;
				}

				$hasPermission = true;
			}
		}

		if (!$hasPermission)
		{
			$permissions[] = [
				'ACCESS_CODE' => $grantedCode,
				'PERMISSION_ID' => $grantedPermissionId,
			];
		}

		return $permissions;
	}

	private function addPermissions(int $templateId, array $permissions): void
	{
		$tariffService = Container::getInstance()->getTariffService();
		if (!$tariffService->canManageTemplatePermissions())
		{
			// add full permission for the current user only
			$permissions = [
				[
					'ACCESS_CODE' => 'U' . $this->config->getUserId(),
					'PERMISSION_ID' => \Bitrix\Tasks\Access\Permission\PermissionDictionary::TEMPLATE_FULL,
				],
			];
		}

		$mainDepartment = Container::getInstance()->get(StructureRepositoryInterface::class)->getMainDepartment();

		foreach ($permissions as $permission)
		{
			$accessCode = $permission['ACCESS_CODE'] ?? null;
			$permissionId = $permission['PERMISSION_ID'] ?? null;
			if ($accessCode === null || $permissionId === null)
			{
				continue;
			}

			if ($accessCode === 'UA') // special case :))))))))
			{
				$accessCode = $mainDepartment?->accessCode;
				$accessCode = str_replace('D', 'DR', $accessCode);
			}

			TasksTemplatePermissionTable::add([
				'TEMPLATE_ID' => $templateId,
				'ACCESS_CODE' => $accessCode,
				'PERMISSION_ID' => $permissionId,
				'VALUE' => $permission['VALUE'] ?? PermissionDictionary::VALUE_YES,
			]);
		}
	}
}
