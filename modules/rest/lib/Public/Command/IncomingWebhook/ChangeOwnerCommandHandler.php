<?php

namespace Bitrix\Rest\Public\Command\IncomingWebhook;

use Bitrix\Main\Application;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Result;
use Bitrix\Rest\Internal\Service\IncomingWebhook\OwnershipService;
use Bitrix\Rest\Internal\Service\SystemUser\SystemUserCreationService;

class ChangeOwnerCommandHandler
{
	private SystemUserCreationService $systemUserCreationService;
	private OwnershipService $ownershipService;

	public function __construct()
	{
		$this->systemUserCreationService = ServiceLocator::getInstance()->get(SystemUserCreationService::class);
		$this->ownershipService = ServiceLocator::getInstance()->get(OwnershipService::class);
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

		return $result;
	}
}