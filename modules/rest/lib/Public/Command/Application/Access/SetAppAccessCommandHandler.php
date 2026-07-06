<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Command\Application\Access;

use Bitrix\Main\AccessDeniedException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Rest\Internal\Access\App\AppAction;
use Bitrix\Rest\Internal\Access\App\AppAccessController;
use Bitrix\Rest\Internal\Access\App\Model\AppModel;
use Bitrix\Rest\Internal\Access\User\Model\RestUserModel;
use Bitrix\Rest\Internal\Exception\Application\ApplicationNotFoundException;
use Bitrix\Rest\Internal\Repository\Application\AppRepository;
use Bitrix\Rest\Internal\Service\Application\AccessCacheInvalidator;

class SetAppAccessCommandHandler
{
	private AppRepository $appRepository;

	public function __construct(?AppRepository $appRepository = null)
	{
		$this->appRepository = $appRepository
			?? ServiceLocator::getInstance()->get('rest.repository.app');
	}

	/**
	 * @throws AccessDeniedException
	 * @throws ApplicationNotFoundException
	 * @throws PersistenceException
	 */
	public function __invoke(SetAppAccessCommand $command): void
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

		$appModel = AppModel::createFromApp($app);
		$controller = AppAccessController::getInstance($command->userId);

		if (!$controller->check(AppAction::ManageAppAccess, $appModel))
		{
			throw new AccessDeniedException('User does not have rights to manage access codes of this application');
		}

		$accessCodes = $command->accessCodes;
		if ($appModel->isPersonal())
		{
			$ownerCode = 'U' . $appModel->getOwnerUserId();
			if (!in_array($ownerCode, $accessCodes, true))
			{
				array_unshift($accessCodes, $ownerCode);
			}
		}

		$app->setAccess($accessCodes);
		$this->appRepository->save($app);

		AccessCacheInvalidator::clear();
	}
}
