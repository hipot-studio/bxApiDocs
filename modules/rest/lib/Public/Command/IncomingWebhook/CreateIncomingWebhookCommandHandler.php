<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Command\IncomingWebhook;

use Bitrix\Main\AccessDeniedException;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Rest\Internal\Access\WebhookAccessChecker;
use Bitrix\Rest\Internal\Entity\IncomingWebhook\IncomingWebhook;

final class CreateIncomingWebhookCommandHandler extends AbstractCreateIncomingWebhookCommandHandler
{
	/**
	 * @throws AccessDeniedException
	 * @throws ObjectNotFoundException
	 * @throws ArgumentException
	 */
	public function __invoke(CreateIncomingWebhookCommand $command): IncomingWebhook
	{
		$user = $this->requireUser($command->userId);
		$scopes = $this->normalizeScopes($command->scopes);

		$accessChecker = new WebhookAccessChecker($command->userId);

		if ($command->ownerUserId !== null && $user->getId() !== $command->ownerUserId)
		{
			if (!$accessChecker->canCreateIncomingWebhook())
			{
				throw new AccessDeniedException(
					'User does not have rights to create incoming webhook for other user'
				);
			}
			$ownerUserId = $command->ownerUserId;
		}
		else
		{
			if (!$accessChecker->canCreateOwnIncomingWebhook())
			{
				throw new AccessDeniedException(
					'User does not have rights to create incoming webhook'
				);
			}
			$ownerUserId = $command->userId;
		}

		return $this->createIntegration(
			initiatorUserId: $command->userId,
			ownerUserId: $ownerUserId,
			title: $command->title,
			scopes: $scopes,
			attributes: $command->attributes,
			comment: $command->comment,
		);
	}
}
