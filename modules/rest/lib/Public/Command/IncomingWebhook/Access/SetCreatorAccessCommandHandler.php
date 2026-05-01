<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Command\IncomingWebhook\Access;

use Bitrix\Rest\Internal\Entity\Access\EntityType;
use Bitrix\Rest\Internal\Service\AccessPermissionService;

class SetCreatorAccessCommandHandler
{
	public function __construct(private AccessPermissionService $service = new AccessPermissionService())
	{
	}

	public function __invoke(SetCreatorAccessCommand $command): void
	{
		$this->service->setAccessCodes(
			EntityType::IncomingWebhook,
			$command->permission,
			$command->accessCodes,
		);
	}
}
