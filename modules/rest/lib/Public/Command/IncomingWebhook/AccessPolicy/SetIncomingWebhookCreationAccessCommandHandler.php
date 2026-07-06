<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Command\IncomingWebhook\AccessPolicy;

use Bitrix\Rest\Internal\Entity\Access\EntityType;
use Bitrix\Rest\Internal\Entity\Access\PermissionType;
use Bitrix\Rest\Internal\Service\AccessCodesService;

class SetIncomingWebhookCreationAccessCommandHandler
{
	public function __construct(private AccessCodesService $service = new AccessCodesService())
	{
	}

	public function __invoke(SetIncomingWebhookCreationAccessCommand $command): void
	{
		$this->service->setAccessCodes(
			EntityType::IncomingWebhook,
			PermissionType::Create,
			$command->accessCodes,
		);
	}
}
