<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Provider\Embedding;

use Bitrix\Main\AccessDeniedException;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Rest\Internal\Entity\Access\EntityType;
use Bitrix\Rest\Internal\Entity\Access\PermissionType;
use Bitrix\Rest\Internal\Service\AccessCodesService;

class AccessPolicyProvider
{
	public function __construct(private AccessCodesService $service = new AccessCodesService())
	{
	}

	public function getAccessCodesAllowedToManageOwnEmbeddings(): array
	{
		if ((int)CurrentUser::get()->getId() <= 0 || !\CRestUtil::isAdmin())
		{
			throw new AccessDeniedException();
		}

		return $this->service->getAccessCodes(EntityType::Embedding, PermissionType::ManageOwnEmbeddings);
	}
}
