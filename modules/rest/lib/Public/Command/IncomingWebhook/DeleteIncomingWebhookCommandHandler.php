<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Command\IncomingWebhook;

use Bitrix\Main\AccessDeniedException;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Rest\Internal\Access\WebhookAccessChecker;
use Bitrix\Rest\Internal\Access\User\Model\RestUserModel;
use Bitrix\Rest\Internal\Contract\Repository\IncomingWebhookRepositoryInterface;
use Bitrix\Rest\Internal\Exception\IncomingWebhook\IncomingWebhookNotFoundException;
use Bitrix\Rest\Internal\Repository\IncomingWebhookRepository;
use Bitrix\Rest\Internal\Repository\IntegrationRepository;
use Bitrix\Rest\Internal\Service\Security\SecurityAuditLogger;
use Bitrix\Rest\Preset\Provider;

class DeleteIncomingWebhookCommandHandler
{
	public function __construct(
		private IncomingWebhookRepositoryInterface $repository = new IncomingWebhookRepository(),
		private IntegrationRepository $integrationRepository = new IntegrationRepository(),
		private SecurityAuditLogger $securityAuditLogger = new SecurityAuditLogger(),

	)
	{
	}

	/**
	 * @throws AccessDeniedException
	 * @throws ObjectNotFoundException
	 */
	public function __invoke(DeleteIncomingWebhookCommand $command): void
	{
		$user = RestUserModel::createFromId($command->userId);
		if ($user->getData() === null)
		{
			throw new ObjectNotFoundException(
				'User with ID ' . $command->userId . ' not found'
			);
		}

		$webhook = $this->repository->getByWebhookId($command->webHookPassword);
		if ($webhook === null)
		{
			throw new IncomingWebhookNotFoundException();
		}

		$accessChecker = new WebhookAccessChecker($command->userId);
		if (!$accessChecker->canManageIncomingWebhook($webhook))
		{
			throw new AccessDeniedException(
				'User does not have rights to delete incoming webhook'
			);
		}
		$integration = $this->integrationRepository->getByIncomingWebhookPasswordId($webhook->getId());
		if ($integration === null)
		{
			throw new ObjectNotFoundException('Integration for incoming webhook not found');
		}

		Provider::deleteIntegration($integration->getId(), $command->userId);

		$this->securityAuditLogger->logWebhookDeleted(
			actingUserId: $command->userId,
			webhookId: (int)$webhook->getId(),
			ownerUserId: $webhook->getUserId(),
			scopes: $webhook->getScopes(),
		);
	}
}
