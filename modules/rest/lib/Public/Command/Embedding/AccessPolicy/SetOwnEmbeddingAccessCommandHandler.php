<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Command\Embedding\AccessPolicy;

use Bitrix\Main\AccessDeniedException;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Rest\Internal\Entity\Access\EntityType;
use Bitrix\Rest\Internal\Entity\Access\PermissionType;
use Bitrix\Rest\Internal\Service\AccessCodesService;

class SetOwnEmbeddingAccessCommandHandler
{
	public function __construct(private AccessCodesService $service = new AccessCodesService())
	{
	}

	public function __invoke(SetOwnEmbeddingAccessCommand $command): void
	{
		if ((int)CurrentUser::get()->getId() <= 0 || !\CRestUtil::isAdmin())
		{
			throw new AccessDeniedException();
		}

		$this->service->setAccessCodes(
			EntityType::Embedding,
			PermissionType::ManageOwnEmbeddings,
			$command->accessCodes,
		);
	}
}
