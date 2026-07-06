<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Command\Application\AccessPolicy;

use Bitrix\Rest\Internal\Entity\Access\EntityType;
use Bitrix\Rest\Internal\Entity\Access\PermissionType;
use Bitrix\Rest\Internal\Service\AccessCodesService;

class SetLocalApplicationCreationAccessCommandHandler
{
	public function __construct(private AccessCodesService $service = new AccessCodesService())
	{
	}

	public function __invoke(SetLocalApplicationCreationAccessCommand $command): void
	{
		$this->service->setAccessCodes(
			EntityType::LocalApp,
			PermissionType::Create,
			$command->accessCodes,
		);
	}
}
