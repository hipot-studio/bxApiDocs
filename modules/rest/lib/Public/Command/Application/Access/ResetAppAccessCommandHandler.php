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
use Bitrix\Rest\Internal\Service\Security\SecurityAuditLogger;

class ResetAppAccessCommandHandler
{
	private AppRepository $appRepository;
	private SecurityAuditLogger $securityAuditLogger;

	public function __construct(?AppRepository $appRepository = null, ?SecurityAuditLogger $securityAuditLogger = null)
	{
		$this->appRepository = $appRepository
			?? ServiceLocator::getInstance()->get('rest.repository.app');
		$this->securityAuditLogger = $securityAuditLogger ?? new SecurityAuditLogger();
	}

	/**
	 * @throws AccessDeniedException
	 * @throws ApplicationNotFoundException
	 * @throws PersistenceException
	 */
	public function __invoke(ResetAppAccessCommand $command): void
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

		$previousAccessCodes = $appModel->getAccessCodes();

		if ($appModel->isPersonal())
		{
			$app->setAccess(['U' . $appModel->getOwnerUserId()]);
		}
		else
		{
			$app->setAccess(null);
		}
		$this->appRepository->save($app);

		$this->securityAuditLogger->logAppAccessChanged(
			actingUserId: $command->userId,
			appId: (int)$app->getId(),
			clientId: $command->clientId,
			action: 'reset',
			previousAccessCodes: $previousAccessCodes,
			newAccessCodes: $appModel->isPersonal() ? ['U' . $appModel->getOwnerUserId()] : [],
		);

		AccessCacheInvalidator::clear();
	}
}
