<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Provider\LocalApp;

use Bitrix\Rest\Internal\Entity\Access\EntityType;
use Bitrix\Rest\Internal\Entity\Access\PermissionType;
use Bitrix\Rest\Internal\Service\AccessCodesService;

class AccessPolicyProvider
{
	public function __construct(private AccessCodesService $service = new AccessCodesService())
	{
	}

	public function getAccessCodesAllowedToCreate(): array
	{
		return $this->service->getAccessCodes(EntityType::LocalApp, PermissionType::Create);
	}

	public function getAccessCodesAllowedToCreateOwn(): array
	{
		return $this->service->getAccessCodes(EntityType::LocalApp, PermissionType::CreateOwn);
	}
}
