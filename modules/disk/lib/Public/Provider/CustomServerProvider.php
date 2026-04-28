<?php
declare(strict_types=1);

namespace Bitrix\Disk\Public\Provider;

use Bitrix\Disk\Configuration;
use Bitrix\Disk\Internal\Entity\CustomServers\CustomServerCollection;
use Bitrix\Disk\Internal\Enum\CustomServerTypes;
use Bitrix\Disk\Internal\Factory\CustomServerFactory;
use Bitrix\Disk\Internal\Interface\CustomServerDataRepositoryInterface;
use Bitrix\Disk\Internal\Interface\CustomServerInterface;
use Bitrix\Disk\Internal\Service\CustomServerConfig;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DI\Exception\CircularDependencyException;
use Bitrix\Main\DI\Exception\ServiceNotFoundException;
use Bitrix\Main\ObjectNotFoundException;
use Exception;

readonly class CustomServerProvider
{
	/**
	 * @param CustomServerDataRepositoryInterface $customServerDataRepository
	 * @param CustomServerFactory $customServerFactory
	 * @param CustomServerAvailabilityProvider $customServerAvailabilityProvider
	 */
	public function __construct(
		protected CustomServerDataRepositoryInterface $customServerDataRepository,
		protected CustomServerFactory $customServerFactory,
		protected CustomServerAvailabilityProvider $customServerAvailabilityProvider,
	)
	{
	}

	/**
	 * @return CustomServerCollection
	 * @throws ArgumentException
	 * @throws CircularDependencyException
	 * @throws ObjectNotFoundException
	 * @throws ServiceNotFoundException
	 */
	public function getListForAdminPage(): CustomServerCollection
	{
		$customServers = Configuration::getCustomServers();
		$customServerCollection = new CustomServerCollection();

		if (!is_array($customServers))
		{
			return $customServerCollection;
		}

		$types = array_map(
			callback: fn(array $customServer) => CustomServerTypes::tryFrom(
				$customServer[CustomServerConfig::TYPE_KEY],
			),
			array: $customServers,
		);

		$dataByTypes = $this->customServerDataRepository->getForTypes($types);

		foreach ($customServers as $typeString => $customServer)
		{
			$data = $dataByTypes[$typeString][0] ?? null;
			$customServer = $this->makeInternal($customServer, $data);

			if (!$this->customServerAvailabilityProvider->isAvailableCustomServerForAdmin($customServer))
			{
				continue;
			}

			$customServerCollection->add($customServer);
		}

		return $customServerCollection;
	}

	/**
	 * @param CustomServerTypes $type
	 * @return CustomServerCollection
	 * @throws CircularDependencyException
	 * @throws ObjectNotFoundException
	 * @throws ServiceNotFoundException
	 * @throws ArgumentException
	 */
	public function getForType(CustomServerTypes $type): CustomServerCollection
	{
		$customServers = Configuration::getCustomServers();
		$customServer = $customServers[$type->value] ?? null;
		$customServerCollection = new CustomServerCollection();

		if (!is_array($customServer))
		{
			return $customServerCollection;
		}

		$dataByType = $this->customServerDataRepository->getForType($type);

		foreach ($dataByType as $data)
		{
			$customServerCollection->add($this->makeInternal($customServer, $data));
		}

		return $customServerCollection;
	}

	/**
	 * @param CustomServerTypes $type
	 * @return CustomServerInterface|null
	 * @throws CircularDependencyException
	 * @throws ObjectNotFoundException
	 * @throws ServiceNotFoundException
	 * @throws ArgumentException
	 * @throws Exception
	 */
	public function getFirstByType(CustomServerTypes $type): ?CustomServerInterface
	{
		return $this->getForType($type)->getIterator()->current();
	}

	/**
	 * @param array $config
	 * @param array|null $data
	 * @return CustomServerInterface
	 * @throws CircularDependencyException
	 * @throws ServiceNotFoundException
	 * @throws ObjectNotFoundException
	 */
	protected function makeInternal(array $config, ?array $data): CustomServerInterface
	{
		$type = CustomServerTypes::tryFrom($config[CustomServerConfig::TYPE_KEY]);

		return $this->customServerFactory->make($type, $data);
	}
}
