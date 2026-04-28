<?php
declare(strict_types=1);

namespace Bitrix\Disk\Public\Command\CustomServer;

use Bitrix\Disk\Internal\Factory\CustomServerFactory;
use Bitrix\Disk\Internal\Interface\CustomServerDataRepositoryInterface;
use Bitrix\Main\DI\Exception\CircularDependencyException;
use Bitrix\Main\DI\Exception\ServiceNotFoundException;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ObjectNotFoundException;

readonly class UpdateCustomServerCommandHandler
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
	 * @param UpdateCustomServerCommand $command
	 * @return Error|null
	 * @throws CircularDependencyException
	 * @throws ServiceNotFoundException
	 * @throws ObjectNotFoundException
	 */
	public function __invoke(UpdateCustomServerCommand $command): ?Error
	{
		$customServer = $this->customServerFactory->make($command->type, $command->data);
		$prepareDataForConnectError = $customServer->prepareDataForConnect();

		if ($prepareDataForConnectError instanceof Error)
		{
			return $prepareDataForConnectError;
		}

		$data = $this->customServerDataRepository->update(
			id: $command->id,
			data: $customServer->getData(),
		);

		if (!is_array($data))
		{
			return new Error(
				message: Loc::getMessage('DISK_CUSTOM_SERVER_NOT_FOUND'),
			);
		}

		return null;
	}
}
