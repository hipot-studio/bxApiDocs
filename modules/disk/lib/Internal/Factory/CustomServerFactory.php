<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Factory;

use Bitrix\Disk\Internal\Enum\CustomServerTypes;
use Bitrix\Disk\Internal\Interface\CustomServerInterface;
use Bitrix\Disk\Internal\Interface\CustomServerWithConfigInterface;
use Bitrix\Disk\Internal\Interface\CustomServerWithDataInterface;
use Bitrix\Disk\Internal\Service\CustomServerConfig;
use Bitrix\Main\DI\Exception\CircularDependencyException;
use Bitrix\Main\DI\Exception\ServiceNotFoundException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\ObjectNotFoundException;
use InvalidArgumentException;

class CustomServerFactory
{
	/**
	 * @param CustomServerTypes $type
	 * @param array|null $data
	 * @return CustomServerInterface
	 * @throws CircularDependencyException
	 * @throws ServiceNotFoundException
	 * @throws ObjectNotFoundException
	 */
	public function make(CustomServerTypes $type, ?array $data = null): CustomServerInterface
	{
		$customServerConfig = ServiceLocator::getInstance()->get(CustomServerConfig::class);

		$customServerConfig->setType($type);
		// TODO validate config?

		$className = $customServerConfig->getClassName();

		if (!is_a($className, CustomServerInterface::class, true))
		{
			throw new InvalidArgumentException('Class name for custom server must implement CustomServerInterface');
		}

		/** @var CustomServerInterface $customServer */
		$customServer = ServiceLocator::getInstance()->get($className);

		if ($customServer instanceof CustomServerWithConfigInterface)
		{
			$customServer->setConfig($customServerConfig);
		}

		if ($customServer instanceof CustomServerWithDataInterface)
		{
			$customServer->setData($data);
		}

		return $customServer;
	}
}
