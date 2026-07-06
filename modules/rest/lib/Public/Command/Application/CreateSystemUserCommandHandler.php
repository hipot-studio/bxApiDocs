<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Command\Application;

use Bitrix\Main;
use Bitrix\Main\DI\Exception\CircularDependencyException;
use Bitrix\Main\DI\Exception\ServiceNotFoundException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Rest\AppTable;
use Bitrix\Rest\Application;
use Bitrix\Rest\AuthProviderInterface;
use Bitrix\Rest\Internal\Service\SystemUser\SystemUserCreationService;

class CreateSystemUserCommandHandler
{
	private SystemUserCreationService $systemUserCreationService;
	private AuthProviderInterface $authProvider;

	/**
	 * @throws ServiceNotFoundException
	 * @throws CircularDependencyException
	 * @throws ObjectNotFoundException
	 */
	public function __construct()
	{
		$this->systemUserCreationService = ServiceLocator::getInstance()->get(SystemUserCreationService::class);
		$this->authProvider = Application::getAuthProvider();
	}

	public function __invoke(CreateSystemUserCommand $command): Main\Result
	{
		$result = new Main\Result();
		$appInfo = AppTable::getByClientId($command->appId);

		if (!$appInfo)
		{
			$result->addError(new Main\Error('Application was not found'));

			return $result;
		}

		$systemUser = $this->systemUserCreationService->createForApplication(
			$command->userId,
			$command->appId,
			(string)$appInfo['APP_NAME'],
		);
		$systemUserData = $this->authProvider->get(
			(string)$appInfo['CLIENT_ID'],
			(string)$appInfo['SCOPE'],
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

		$systemUserData['APP_ID'] = (int)$appInfo['ID'];

		$result->setData([
			'systemUserId' => $systemUser->getUserId(),
			'systemUserData' => $systemUserData,
		]);

		return $result;
	}
}
