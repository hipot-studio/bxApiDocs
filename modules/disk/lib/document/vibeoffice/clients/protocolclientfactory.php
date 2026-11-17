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
	 * Optional service: a callable that gets the call context and returns the client to use instead, or
	 * null to keep the configured one.
	 */
	public const CLIENT_OVERRIDE_SERVICE = 'disk.vibeofficeProtocolClientOverride';

	/**
	 * @throws ConfigurationException when server / key_id / api-secret is missing
	 * @throws NotFoundExceptionInterface
	 * @throws ObjectNotFoundException
	 */
	public static function create(?ProtocolCallContext $context = null): ProtocolClientInterface
	{
		$locator = ServiceLocator::getInstance();
		if ($locator->has(self::CLIENT_OVERRIDE_SERVICE))
		{
			$override = $locator->get(self::CLIENT_OVERRIDE_SERVICE);
			$client = is_callable($override) ? $override($context) : null;
			if ($client instanceof ProtocolClientInterface)
			{
				return $client;
			}
		}

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

		return new ProtocolClient($server, new Signer($keyId, $apiSecret), callContext: $context);
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
