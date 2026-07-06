<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Command\Application;

use Bitrix\Main;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DI\Exception\CircularDependencyException;
use Bitrix\Main\DI\Exception\ServiceNotFoundException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Rest\AppTable;
use Bitrix\Rest\Internal\Service\SystemUser\SystemUserCreationService;

class DeactivateSystemUserCommandHandler
{
	private SystemUserCreationService $systemUserCreationService;

	/**
	 * @throws ServiceNotFoundException
	 * @throws CircularDependencyException
	 * @throws ObjectNotFoundException
	 */
	public function __construct()
	{
		$this->systemUserCreationService = ServiceLocator::getInstance()->get(SystemUserCreationService::class);
	}

	/**
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	public function __invoke(DeactivateSystemUserCommand $command): Main\Result
	{
		$result = new Main\Result();
		$appInfo = AppTable::getByClientId($command->appId);

		if (!$appInfo)
		{
			$result->addError(new Main\Error('Application was not found'));

			return $result;
		}

		$this->systemUserCreationService->deactivateForApplication($command->appId);

		return $result;
	}
}