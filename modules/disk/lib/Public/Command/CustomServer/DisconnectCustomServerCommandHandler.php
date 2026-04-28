<?php
declare(strict_types=1);

namespace Bitrix\Disk\Public\Command\CustomServer;

use Bitrix\Disk\Internal\Enum\CustomServerTypes;
use Bitrix\Disk\Internal\Factory\CustomServerFactory;
use Bitrix\Disk\Internal\Interface\CustomServerDataRepositoryInterface;
use Bitrix\Disk\Public\Event\DeletingCustomServerEvent;
use Bitrix\Main\DI\Exception\CircularDependencyException;
use Bitrix\Main\DI\Exception\ServiceNotFoundException;
use Bitrix\Main\Error;
use Bitrix\Main\EventManager;
use Bitrix\Main\EventResult;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ObjectNotFoundException;

readonly class DisconnectCustomServerCommandHandler
{
	/**
	 * @param CustomServerDataRepositoryInterface $customServerDataRepository
	 * @param CustomServerFactory $customServerFactory
	 */
	public function __construct(
		protected CustomServerDataRepositoryInterface $customServerDataRepository,
		protected CustomServerFactory $customServerFactory,
	)
	{
	}

	/**
	 * @param DisconnectCustomServerCommand $command
	 * @return Error|null
	 * @throws CircularDependencyException
	 * @throws ServiceNotFoundException
	 * @throws ObjectNotFoundException
	 */
	public function __invoke(DisconnectCustomServerCommand $command): ?Error
	{
		$data = $this->customServerDataRepository->find($command->id);

		if (is_null($data))
		{
			return new Error(
				message: Loc::getMessage('DISK_CUSTOM_SERVER_NOT_FOUND'),
			);
		}

		$typeString = $data['type'] ?? null;

		if (!is_string($typeString))
		{
			return new Error(
				message: Loc::getMessage('DISK_CUSTOM_SERVER_INVALID_TYPE'),
			);
		}

		$type = CustomServerTypes::tryFrom($typeString);

		if (!$type instanceof CustomServerTypes)
		{
			return new Error(
				message: Loc::getMessage('DISK_CUSTOM_SERVER_TYPE_NOT_SUPPORTED'),
			);
		}

		$customServer = $this->customServerFactory->make($type, $data);
		$deletingCustomServerEvent = new DeletingCustomServerEvent($customServer);

		EventManager::getInstance()->send($deletingCustomServerEvent);

		$deletingCustomServerEventResult = $deletingCustomServerEvent->getResults()[0] ?? null;
		$isDeleteCustomServerErrored = $deletingCustomServerEventResult?->getType() === EventResult::ERROR;

		if ($isDeleteCustomServerErrored)
		{
			$isCustomServerUsedByAnotherModule = $deletingCustomServerEventResult->getParameters()['isUsed'] ?? false;

			$message =
				$isCustomServerUsedByAnotherModule
					? Loc::getMessage('DISK_CUSTOM_SERVER_DISCONNECT_USED_ERROR')
					: Loc::getMessage('DISK_CUSTOM_SERVER_DISCONNECT_UNDEFINED_ERROR')
			;

			return new Error($message);
		}

		$this->customServerDataRepository->delete(
			id: $command->id,
		);

		return null;
	}
}
