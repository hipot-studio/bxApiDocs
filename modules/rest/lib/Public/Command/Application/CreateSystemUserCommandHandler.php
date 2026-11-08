<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Command\Application;

use Bitrix\Main;
use Bitrix\Main\DI\Exception\CircularDependencyException;
use Bitrix\Main\DI\Exception\ServiceNotFoundException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Rest\Application;
use Bitrix\Rest\AuthProviderInterface;
use Bitrix\Rest\Internal\Service\SystemUser\SystemUserCreationService;
use Bitrix\Rest\Public\Provider\ApplicationProvider;

class CreateSystemUserCommandHandler
{
	private SystemUserCreationService $systemUserCreationService;
	private AuthProviderInterface $authProvider;
	private ApplicationProvider $applicationProvider;

	/**
	 * @throws ServiceNotFoundException
	 * @throws CircularDependencyException
	 * @throws ObjectNotFoundException
	 */
	public function __construct()
	{
		$this->systemUserCreationService = ServiceLocator::getInstance()->get(SystemUserCreationService::class);
		$this->authProvider = Application::getAuthProvider();
		$this->applicationProvider = new ApplicationProvider();
	}

	public function __invoke(CreateSystemUserCommand $command): Main\Result
	{
		$result = new Main\Result();
		$appInfo = $this->applicationProvider->findById($command->appId);

		if ($appInfo === null)
		{
			$result->addError(new Main\Error('Application was not found'));

			return $result;
		}

		$systemUser = $this->systemUserCreationService->createForApplication(
			$command->userId,
			$command->appId,
			(string)$appInfo->getName(),
		);
		$systemUserData = $this->authProvider->get(
			(string)$appInfo->getClientId(),
			implode(',', $appInfo->getScope() ?? []),
			[],
			$systemUser->getUserId(),
		);

		if (!is_array($systemUserData) || empty($systemUserData['access_token']))
		{
			$errorMessage = is_array($systemUserData)
				? (string)($systemUserData['error_description'] ?? $systemUserData['error'] ?? 'Could not create access token')
				: 'Could not create access token'
			;
			$result->addError(new Main\Error($errorMessage));

			return $result;
		}

		$systemUserData['APP_ID'] = $appInfo->getId();

		$result->setData([
			'systemUserId' => $systemUser->getUserId(),
			'systemUserData' => $systemUserData,
		]);

		return $result;
	}
}
