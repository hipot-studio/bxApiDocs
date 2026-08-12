<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Command\IncomingWebhook;

use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Rest\Internal\Entity\IncomingWebhook\IncomingWebhook;
use Bitrix\Rest\Internal\Service\User\ActiveAdminFinder;

final class ForceCreateIncomingWebhookCommandHandler extends AbstractCreateIncomingWebhookCommandHandler
{
	public function __invoke(ForceCreateIncomingWebhookCommand $command): IncomingWebhook
	{
		$this->requireUser($command->userId);
		$scopes = $this->normalizeScopes($command->scopes);

		return $this->createIntegration(
			initiatorUserId: $this->getAdminUserId(),
			ownerUserId: $command->userId,
			title: $command->title,
			scopes: $scopes,
			attributes: $command->attributes,
			comment: $command->comment,
		);
	}

	private function getAdminUserId(): int
	{
		$adminUserId = (new ActiveAdminFinder())->findFirstActiveAdminId();
		if ($adminUserId === null)
		{
			throw new ObjectNotFoundException('No active admin user found on this portal');
		}

		return $adminUserId;
	}
}
