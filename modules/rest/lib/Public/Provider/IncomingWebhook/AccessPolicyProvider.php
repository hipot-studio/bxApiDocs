<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Provider\IncomingWebhook;

use Bitrix\Rest\Internal\Entity\Access\EntityType;
use Bitrix\Rest\Internal\Entity\Access\PermissionType;
use Bitrix\Rest\Internal\Service\AccessPermissionService;

class AccessPolicyProvider
{
	public function __construct(private AccessPermissionService $service = new AccessPermissionService())
	{
	}

	public function getAccessCodesAllowedToCreateOwn(): array
	{
		return $this->getGroupsByPermission(PermissionType::CreateOwn);
	}

	public function getAccessCodesAllowedToManageOwn(): array
	{
		return $this->getGroupsByPermission(PermissionType::ManageOwn);
	}

	public function getAccessCodesAllowedToCreate(): array
	{
		return $this->getGroupsByPermission(PermissionType::Create);
	}

	public function getAccessCodesAllowedToManage(): array
	{
		return $this->getGroupsByPermission(PermissionType::Manage);
	}

	protected function getGroupsByPermission(PermissionType $permission): array
	{
		return $this->service->getAccessCodes(EntityType::IncomingWebhook, $permission);
	}
}
