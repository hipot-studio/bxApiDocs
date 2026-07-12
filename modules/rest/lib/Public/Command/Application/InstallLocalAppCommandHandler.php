<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Command\Application;

use Bitrix\Main\AccessDeniedException;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Main\SystemException;
use Bitrix\Rest\Internal\Access\AppAccessChecker;
use Bitrix\Rest\Internal\Access\User\Model\RestUserModel;
use Bitrix\Rest\Internal\Entity\Application\App;
use Bitrix\Rest\Internal\Entity\Application\AppAttributeCollection;
use Bitrix\Rest\Internal\Entity\Application\AppExternalAttribute;
use Bitrix\Rest\Internal\Entity\Application\AppFactory;
use Bitrix\Rest\Internal\Service\Application\ApplicationInstaller;

class InstallLocalAppCommandHandler
{
	private ApplicationInstaller $applicationInstaller;

	public function __construct(
		?ApplicationInstaller $applicationInstaller = null,
	)
	{
		$this->applicationInstaller = $applicationInstaller
			?? ServiceLocator::getInstance()->get(ApplicationInstaller::class);
	}

	/**
	 * @throws AccessDeniedException
	 * @throws ArgumentException
	 * @throws SystemException
	 * @throws PersistenceException
	 */
	public function __invoke(InstallLocalAppCommand $command): App
	{
		$user = RestUserModel::createFromId($command->userId);
		if ($user->getData() === null)
		{
			throw new AccessDeniedException('Acting user does not exist');
		}

		$accessChecker = new AppAccessChecker($command->userId);
		if (!$accessChecker->canInstallLocal())
		{
			throw new AccessDeniedException('User does not have rights to install a local application');
		}

		$externalAttributes = new AppAttributeCollection();
		foreach ($command->attributes as $code => $value)
		{
			$externalAttributes->add(new AppExternalAttribute(null, $code, $value));
		}

		$app = AppFactory::createLocal(
			clientId: $command->appClientId,
			handlerUrl: $command->handlerUrl,
			scope: $command->scopes,
			title: $command->title,
			installUrl: $command->installUrl,
			mobile: $command->mobile,
			menuTitles: $command->menuTitles,
			externalAttributes: $externalAttributes,
		);

		return $this->applicationInstaller->installAsLocal(
			userId: $command->userId,
			app: $app,
			onlyApi: $command->onlyApi,
			applicationToken: $command->applicationToken,
		);
	}
}
