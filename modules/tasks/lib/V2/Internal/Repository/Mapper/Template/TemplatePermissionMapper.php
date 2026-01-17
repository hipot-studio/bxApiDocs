<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Mapper\Template;

use Bitrix\Tasks\V2\Internal\Entity\Template\Access\AccessEntity;
use Bitrix\Tasks\V2\Internal\Entity\Template\Permission;
use Bitrix\Tasks\V2\Internal\Entity\Template\PermissionCollection;

class TemplatePermissionMapper
{
	public function __construct(
		private readonly PermissionTypeMapper $permissionTypeMapper,
		private readonly AccessEntityMapper $accessEntityMapper,
	)
	{

	}

	public function mapToEntity(array $permission, ?int $templateId = null): Permission
	{
		$permissionId = Permission::mapInteger($permission, 'PERMISSION_ID');

		$accessCode = $permission['ACCESS_CODE'] ?? $permission['GROUP_CODE'] ?? null;
		if ($accessCode === null && ($permission['ACCESS_ENTITY'] ?? null) instanceof AccessEntity)
		{
			$accessCode = $this->accessEntityMapper->mapToAccessCode($permission['ACCESS_ENTITY']);
		}

		$data = [
			'id' => $permission['ID'] ?? null,
			'templateId' => $permission['TEMPLATE_ID'] ?? $templateId ?? null,
			'accessCode' => $accessCode,
			'permissionId' => $permissionId,
			'value' => $permission['VALUE'] ?? null,
			'permissionType' => $this->permissionTypeMapper->mapToEnum($permissionId),
			'accessEntity' => $permission['ACCESS_ENTITY'] ?? null,
		];

		return Permission::mapFromArray($data);
	}

	public function mapToCollection(
		array $permissions,
		?int $templateId = null,
	): PermissionCollection
	{
		$entities = new PermissionCollection();
		foreach ($permissions as $permission)
		{
			if (!is_array($permission))
			{
				continue;
			}

			$entities->add($this->mapToEntity($permission, $templateId));
		}

		return $entities;
	}

	public function mapFromEntity(Permission $permission): array
	{
		$accessCode = $permission->accessCode;
		if ($accessCode === null && $permission->accessEntity !== null)
		{
			$accessCode = $this->accessEntityMapper->mapToAccessCode($permission->accessEntity);
		}

		$permissionId = $permission->permissionId;
		if ($permissionId === null && $permission->permissionType !== null)
		{
			$permissionId = $this->permissionTypeMapper->mapFromEnum($permission->permissionType);
		}

		return [
			'ID' => $permission->getId(),
			'TEMPLATE_ID' => $permission->templateId,
			'ACCESS_CODE' => $accessCode,
			'PERMISSION_ID' => $permissionId,
			'VALUE' => $permission->value,
		];
	}

	public function mapFromCollection(PermissionCollection $collection): array
	{
		$result = [];
		foreach ($collection as $permission)
		{
			$result[] = $this->mapFromEntity($permission);
		}

		return $result;
	}
}
