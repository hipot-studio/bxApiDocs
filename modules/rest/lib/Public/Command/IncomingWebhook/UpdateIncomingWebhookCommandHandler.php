<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Command\IncomingWebhook;

use Bitrix\Main\AccessDeniedException;
use Bitrix\Rest\Internal\Exception\ArgumentException;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Rest\APAuth\PermissionTable;
use Bitrix\Rest\Enum\Integration\ElementCodeType;
use Bitrix\Rest\Internal\Access\WebhookAccessChecker;
use Bitrix\Rest\Internal\Access\User\Model\RestUserModel;
use Bitrix\Rest\Internal\Exception\IncomingWebhook\IncomingWebhookNotFoundException;
use Bitrix\Rest\Internal\Contract\Repository\IncomingWebhookRepositoryInterface;
use Bitrix\Rest\Internal\Entity\IncomingWebhook\IncomingWebhook;
use Bitrix\Rest\Internal\Repository\IncomingWebhookRepository;
use Bitrix\Rest\Internal\Repository\IntegrationRepository;
use Bitrix\Rest\Internal\Service\Security\SecurityAuditLogger;
use Bitrix\Rest\Preset\Provider;

class UpdateIncomingWebhookCommandHandler
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
	 * @throws IncomingWebhookNotFoundException
	 * @throws ArgumentException
	 */
	public function __invoke(UpdateIncomingWebhookCommand $command): IncomingWebhook
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

		// Partial update: keep the webhook's current scopes only when the field is
		// omitted (null). An explicit empty list means "no scopes" and is rejected,
		// as is a list where none of the provided scopes is valid.
		if ($command->scopes === null)
		{
			$scopes = $webhook->getScopes();
		}
		else
		{
			$scopes = PermissionTable::cleanPermissionList($command->scopes);
			if (empty($scopes))
			{
				throw new ArgumentException('At least one valid scope is required', 'scopes');
			}
		}

		$accessChecker = new WebhookAccessChecker($command->userId);
		if (!$accessChecker->canManageIncomingWebhook($webhook))
		{
			throw new AccessDeniedException(
				'User does not have rights to edit incoming webhook'
			);
		}

		$integration = $this->integrationRepository->getByIncomingWebhookPasswordId($webhook->getId());
		if ($integration === null)
		{
			throw new ObjectNotFoundException('Integration for incoming webhook not found');
		}

		$previousScopes = $webhook->getScopes();
		$newTitle = $command->title ?? $webhook->getTitle();

		Provider::saveIntegration(
			[
				'PASSWORD_ID' => $webhook->getId(),
				'TITLE' => $newTitle,
				'SCOPE' => $scopes,
				'MODE' => 'INCOMING_WEBHOOK',
			],
			ElementCodeType::IN_WEBHOOK->value,
			$integration->getId(),
			$user->getId(),
		);

		$this->securityAuditLogger->logWebhookUpdated(
			actingUserId: $command->userId,
			webhookId: (int)$webhook->getId(),
			ownerUserId: $webhook->getUserId(),
			previousScopes: $previousScopes,
			newScopes: $scopes,
			title: $newTitle,
		);

		return $this->repository->getByWebhookId($command->webHookPassword);
	}
}
