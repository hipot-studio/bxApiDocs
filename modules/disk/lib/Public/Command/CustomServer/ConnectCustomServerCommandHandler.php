<?php
declare(strict_types=1);

namespace Bitrix\Disk\Public\Command\CustomServer;

use Bitrix\Disk\Internal\Factory\CustomServerFactory;
use Bitrix\Disk\Internal\Interface\CustomServerDataRepositoryInterface;
use Bitrix\Main\DI\Exception\CircularDependencyException;
use Bitrix\Main\DI\Exception\ServiceNotFoundException;
use Bitrix\Main\Error;
use Bitrix\Main\ObjectNotFoundException;

readonly class ConnectCustomServerCommandHandler
{
	/**
	 * @param CustomServerFactory $customServerFactory
	 * @param CustomServerDataRepositoryInterface $customServerDataRepository
	 */
	public function __construct(
		protected CustomServerFactory $customServerFactory,
		protected CustomServerDataRepositoryInterface $customServerDataRepository,
	)
	{
	}

	/**
	 * @param ConnectCustomServerCommand $command
	 * @return Error|null
	 * @throws CircularDependencyException
	 * @throws ServiceNotFoundException
	 * @throws ObjectNotFoundException
	 */
	public function __invoke(ConnectCustomServerCommand $command): ?Error
	{
		$customServer = $this->customServerFactory->make($command->type, $command->data);
		$prepareDataForConnectError = $customServer->prepareDataForConnect();

		if ($prepareDataForConnectError instanceof Error)
		{
			return $prepareDataForConnectError;
		}

		$this->customServerDataRepository->create(
			type: $command->type,
			data: $customServer->getData(),
		);

		return null;
	}
}
