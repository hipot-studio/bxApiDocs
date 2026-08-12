<?php

namespace Bitrix\Rest\Public\Command\IncomingWebhook;

use Bitrix\Main\Application;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Result;
use Bitrix\Rest\Internal\Service\IncomingWebhook\OwnershipService;
use Bitrix\Rest\Internal\Service\Security\SecurityAuditLogger;
use Bitrix\Rest\Internal\Service\SystemUser\SystemUserCreationService;

class ChangeOwnerCommandHandler
{
	private SystemUserCreationService $systemUserCreationService;
	private OwnershipService $ownershipService;
	private SecurityAuditLogger $securityAuditLogger;

	public function __construct()
	{
		$this->systemUserCreationService = ServiceLocator::getInstance()->get(SystemUserCreationService::class);
		$this->ownershipService = ServiceLocator::getInstance()->get(OwnershipService::class);
		$this->securityAuditLogger = new SecurityAuditLogger();
	}

	public function __invoke(ChangeOwnerCommand $command): Result
	{
		$result = new Result();
		$conn = Application::getConnection();
		$conn->startTransaction();
		if ($command->newUserId === null)
		{
			$systemUser = $this->systemUserCreationService->createForWebhook($command->userId);
			$newUserId = $systemUser->getUserId();
		}
		else
		{
			$newUserId = $command->newUserId;
		}

		$changeOwnerResult = $this->ownershipService->changeOwner($command->userId, $newUserId, $command->webhookIds);

		if (!$changeOwnerResult->isSuccess())
		{
			$conn->rollbackTransaction();
			$result->addErrors($changeOwnerResult->getErrors());
			return $result;
		}

		$conn->commitTransaction();

		$updatedWebhookIds = $changeOwnerResult->getData()['updateWebhookIds'] ?? [];
		if ($updatedWebhookIds !== [])
		{
			$this->securityAuditLogger->logWebhookOwnerChanged(
				actingUserId: $command->userId,
				fromUserId: $command->userId,
				toUserId: $newUserId,
				webhookIds: $updatedWebhookIds,
			);
		}

		return $result;
	}
}