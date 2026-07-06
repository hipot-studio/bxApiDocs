<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Command\Application;

use Bitrix\Main\AccessDeniedException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Rest\Internal\Access\App\Model\AppModel;
use Bitrix\Rest\Internal\Access\AppAccessChecker;
use Bitrix\Rest\Internal\Access\User\Model\RestUserModel;
use Bitrix\Rest\Internal\Entity\Application\AppOrigin;
use Bitrix\Rest\Internal\Exception\Application\ApplicationNotFoundException;
use Bitrix\Rest\Internal\Repository\Application\AppRepository;
use Bitrix\Rest\Internal\Service\Application\ApplicationUninstaller;

class UninstallLocalAppCommandHandler
{
	private AppRepository $appRepository;
	private ApplicationUninstaller $applicationUninstaller;

	public function __construct(
		?AppRepository $appRepository = null,
		?ApplicationUninstaller $applicationUninstaller = null,
	)
	{
		$this->appRepository = $appRepository
			?? ServiceLocator::getInstance()->get('rest.repository.app');

		$this->applicationUninstaller = $applicationUninstaller
			?? new ApplicationUninstaller($this->appRepository);
	}

	/**
	 * @throws PersistenceException
	 * @throws AccessDeniedException
	 * @throws ApplicationNotFoundException
	 */
	public function __invoke(UninstallLocalAppCommand $command): bool
	{
		$user = RestUserModel::createFromId($command->userId);
		if ($user->getData() === null)
		{
			throw new AccessDeniedException('Acting user does not exist');
		}

		$app = $this->appRepository->getByClientId($command->clientId);
		if ($app === null)
		{
			throw new ApplicationNotFoundException($command->clientId);
		}

		$installationType = $app->getOrigin();
		if ($installationType !== AppOrigin::Local)
		{
			throw new AccessDeniedException('Application is not a local application');
		}

		$appModel = AppModel::createFromApp($app);
		if ($appModel->isPersonal())
		{
			throw new AccessDeniedException('Application is a personal application');
		}

		$accessChecker = new AppAccessChecker($command->userId);
		if (!$accessChecker->canUninstallLocal($app))
		{
			throw new AccessDeniedException('User does not have rights to uninstall this local application');
		}

		$this->applicationUninstaller->uninstallByUser($app, $command->userId);

		return true;
	}
}
