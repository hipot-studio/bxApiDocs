<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Command\Application;

use Bitrix\Main\AccessDeniedException;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Main\SystemException;
use Bitrix\Rest\Internal\Access\User\Model\RestUserModel;
use Bitrix\Rest\Internal\Entity\Application\App;
use Bitrix\Rest\Internal\Entity\Application\AppAttributeCollection;
use Bitrix\Rest\Internal\Entity\Application\AppExternalAttribute;
use Bitrix\Rest\Internal\Entity\Application\AppFactory;
use Bitrix\Rest\Internal\Service\Application\ApplicationInstaller;
use Bitrix\Rest\Internal\Service\User\ActiveAdminFinder;

final class ForceInstallPersonalAppCommandHandler
{
	private ApplicationInstaller $applicationInstaller;
	private ActiveAdminFinder $activeAdminFinder;

	public function __construct(
		?ApplicationInstaller $applicationInstaller = null,
		?ActiveAdminFinder $activeAdminFinder = null,
	)
	{
		$this->applicationInstaller = $applicationInstaller
			?? ServiceLocator::getInstance()->get(ApplicationInstaller::class);
		$this->activeAdminFinder = $activeAdminFinder ?? new ActiveAdminFinder();
	}

	/**
	 * @throws AccessDeniedException
	 * @throws ArgumentException
	 * @throws SystemException
	 * @throws PersistenceException
	 * @throws ObjectNotFoundException
	 */
	public function __invoke(ForceInstallPersonalAppCommand $command): App
	{
		$user = RestUserModel::createFromId($command->userId);
		if ($user->getData() === null)
		{
			throw new AccessDeniedException('Acting user does not exist');
		}

		$externalAttributes = new AppAttributeCollection();
		foreach ($command->attributes as $code => $value)
		{
			$externalAttributes->add(new AppExternalAttribute(null, $code, $value));
		}

		$app = AppFactory::createPersonal(
			clientId: $command->appClientId,
			handlerUrl: $command->handlerUrl,
			scope: $command->scopes,
			title: $command->title,
			installUrl: $command->installUrl,
			mobile: $command->mobile,
			menuTitles: $command->menuTitles,
			externalAttributes: $externalAttributes,
		);

		return $this->applicationInstaller->installAsPersonal(
			userId: $command->userId,
			app: $app,
			onlyApi: $command->onlyApi,
			applicationToken: (string)$command->applicationToken,
			initiatorUserId: $this->getInitiatorUserId(),
		);
	}

	/**
	 * Force install runs through a privileged initiator (the first active portal admin),
	 * so the portal-level personal-app create policy does not block the target user.
	 *
	 * @throws ObjectNotFoundException
	 */
	private function getInitiatorUserId(): int
	{
		$adminUserId = $this->activeAdminFinder->findFirstActiveAdminId();
		if ($adminUserId === null)
		{
			throw new ObjectNotFoundException('No active admin user found on this portal');
		}

		return $adminUserId;
	}
}
