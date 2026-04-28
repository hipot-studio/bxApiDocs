<?php
declare(strict_types=1);

namespace Bitrix\Disk\Controller\Admin;

use Bitrix\Disk\Internal\Enum\CustomServerTypes;
use Bitrix\Disk\Public\Command\CustomServer\ConnectCustomServerCommand;
use Bitrix\Disk\Public\Command\CustomServer\DisconnectCustomServerCommand;
use Bitrix\Disk\Public\Command\CustomServer\UpdateCustomServerCommand;
use Bitrix\Disk\Public\Provider\CustomServerAvailabilityProvider;
use Bitrix\Main\Command\Exception\CommandException;
use Bitrix\Main\Command\Exception\CommandValidationException;
use Bitrix\Main\DI\Exception\CircularDependencyException;
use Bitrix\Main\DI\Exception\ServiceNotFoundException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\Action;
use Bitrix\Main\Engine\ActionFilter\Authentication;
use Bitrix\Main\Engine\ActionFilter\Csrf;
use Bitrix\Main\Engine\ActionFilter\HttpMethod;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\Request;

class CustomServer extends Controller
{
	protected CustomServerAvailabilityProvider $availabilityProvider;

	public function configureActions(): array
	{
		return [
			'connect' => [
				'prefilters' => [
					new Authentication(),
					new Csrf(),
					new HttpMethod([HttpMethod::METHOD_POST]),
				],
			],
			'update' => [
				'prefilters' => [
					new Authentication(),
					new Csrf(),
					new HttpMethod([HttpMethod::METHOD_POST]),
				],
			],
			'disconnect' => [
				'prefilters' => [
					new Authentication(),
					new Csrf(),
					new HttpMethod([HttpMethod::METHOD_POST]),
				],
			],
		];
	}

	/**
	 * {@inheritDoc}
	 * @param Request|null $request
	 * @throws CircularDependencyException
	 * @throws ServiceNotFoundException
	 * @throws ObjectNotFoundException
	 */
	public function __construct(?Request $request = null)
	{
		parent::__construct($request);

		$this->availabilityProvider = ServiceLocator::getInstance()->get(CustomServerAvailabilityProvider::class);
	}

	/**
	 * @param string $customServerType
	 * @param array $data
	 * @return void
	 * @throws CommandException
	 * @throws CommandValidationException
	 */
	public function connectAction(string $customServerType, array $data): void
	{
		$type = CustomServerTypes::tryFrom($customServerType);

		if (!$type instanceof CustomServerTypes)
		{
			$this->addError(new Error(
				message: Loc::getMessage('DISK_CUSTOM_SERVER_TYPE_NOT_SUPPORTED'),
			));

			return;
		}

		$connectResult = (new ConnectCustomServerCommand($type, $data))->run();

		if (!$connectResult->isSuccess())
		{
			$this->addError($connectResult->getError());
		}
	}

	/**
	 * @param string $customServerId
	 * @param string $customServerType
	 * @param array $data
	 * @return void
	 * @throws CommandException
	 * @throws CommandValidationException
	 */
	public function updateAction(string $customServerId, string $customServerType, array $data): void
	{
		$type = CustomServerTypes::tryFrom($customServerType);

		if (!$type instanceof CustomServerTypes)
		{
			$this->addError(new Error(
				message: Loc::getMessage('DISK_CUSTOM_SERVER_TYPE_NOT_SUPPORTED'),
			));

			return;
		}

		$updateResult = (new UpdateCustomServerCommand($customServerId, $type, $data))->run();

		if (!$updateResult->isSuccess())
		{
			$this->addError($updateResult->getError());
		}
	}

	/**
	 * @param string $customServerId
	 * @return void
	 * @throws CommandException
	 * @throws CommandValidationException
	 */
	public function disconnectAction(string $customServerId): void
	{
		$disconnectResult = (new DisconnectCustomServerCommand($customServerId))->run();

		if (!$disconnectResult->isSuccess())
		{
			$this->addError($disconnectResult->getError());
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function processBeforeAction(Action $action): bool
	{
		if (!$this->getCurrentUser()->isAdmin())
		{
			return false;
		}

		if (!$this->availabilityProvider->isAvailableForEdit())
		{
			return false;
		}

		return parent::processBeforeAction($action);
	}
}
