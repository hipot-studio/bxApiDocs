<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Update;

use Bitrix\Main\Access\Permission\PermissionDictionary;
use Bitrix\Tasks\Access\Permission\TasksTemplatePermissionTable;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Integration\HumanResources\Repository\StructureRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\Config\UpdateConfig;

class UpdateRights
{
	public function __construct(
		private readonly UpdateConfig $config,
	)
	{

	}

	public function __invoke(array $fields, array $fullTemplateData): void
	{
		if (!isset($fields['PERMISSIONS']) || !is_array($fields['PERMISSIONS']))
		{
			return;
		}

		$tariffService = Container::getInstance()->getTariffService();
		if (!$tariffService->canManageTemplatePermissions())
		{
			return;
		}

		$this->deletePermissions($fields, $fullTemplateData);
		$this->addPermissions($fields);
	}

	private function addPermissions(array $fields): void
	{
		$mainDepartment = Container::getInstance()->get(StructureRepositoryInterface::class)->getMainDepartment();

		foreach ($fields['PERMISSIONS'] as $permission)
		{
			$permissionId = $permission['PERMISSION_ID'] ?? null;
			if ($permissionId === null)
			{
				continue;
			}

			$accessCode = $permission['ACCESS_CODE'];
			if ($accessCode === 'UA') // special case :))))
			{
				$accessCode = $mainDepartment?->accessCode;
				$accessCode = str_replace('D', 'DR', $accessCode);
			}

			if ($accessCode === null)
			{
				continue;
			}

			TasksTemplatePermissionTable::add([
				'TEMPLATE_ID' => $fields['ID'],
				'ACCESS_CODE' => $accessCode,
				'PERMISSION_ID' => $permission['PERMISSION_ID'],
				'VALUE' => $permission['VALUE'] ?? PermissionDictionary::VALUE_YES,
			]);
		}
	}

	private function deletePermissions(array $fields, array $fullTemplateData): void
	{
		$permissions = $fields['PERMISSIONS'] ?? [];

		if (!empty($permissions))
		{
			TasksTemplatePermissionTable::deleteList([
				'=TEMPLATE_ID' => $fullTemplateData['ID'],
			]);
		}
		else
		{
			TasksTemplatePermissionTable::deleteList([
				'=TEMPLATE_ID' => $fullTemplateData['ID'],
				'!=ACCESS_CODE' => 'U'. $this->config->getUserId(),
			]);
		}
	}
}
