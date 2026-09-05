<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice\Clients;

use Bitrix\Disk\Document\Vibeoffice\Configuration;
use Bitrix\Main\Config\ConfigurationException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\ObjectNotFoundException;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Assembles a ProtocolClient from the vibeoffice configuration
 * (base URL + key_id + api-secret), by analogy with CommandServiceClientFactory.
 */
final class ProtocolClientFactory
{
	/**
	 * @throws ConfigurationException when server / key_id / api-secret is missing
	 * @throws NotFoundExceptionInterface
	 * @throws ObjectNotFoundException
	 */
	public static function create(): ProtocolClientInterface
	{
		$configuration = self::getConfiguration();

		$server = $configuration->getServer();
		if (empty($server))
		{
			throw new ConfigurationException('Vibeoffice server configuration is not configured');
		}

		$keyId = $configuration->getKeyId();
		if (empty($keyId))
		{
			throw new ConfigurationException('Vibeoffice key id configuration is not configured');
		}

		$apiSecret = $configuration->getApiSecret();
		if (empty($apiSecret))
		{
			throw new ConfigurationException('Vibeoffice api secret configuration is not configured');
		}

		return new ProtocolClient($server, new Signer($keyId, $apiSecret));
	}

	/**
	 * @throws NotFoundExceptionInterface
	 * @throws ObjectNotFoundException
	 */
	private static function getConfiguration(): Configuration
	{
		return ServiceLocator::getInstance()->get('disk.vibeofficeConfiguration');
	}
}
