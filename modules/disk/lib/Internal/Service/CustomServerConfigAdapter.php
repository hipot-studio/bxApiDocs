<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service;

use Bitrix\Disk\Internal\Entity\CustomServers\OnlyOfficeCustomServer;
use Bitrix\Disk\Internal\Entity\CustomServers\R7CustomServer;
use Bitrix\Disk\Internal\Enum\CustomServerTypes;
use Bitrix\Disk\Internal\Interface\CustomServerInterface;
use Closure;

class CustomServerConfigAdapter
{
	protected static ?array $resolvers = null;

	/**
	 * @param CustomServerInterface $customServer
	 * @param string $key
	 * @return mixed
	 */
	public static function getValue(CustomServerInterface $customServer, string $key): mixed
	{
		$resolver = static::getResolver($customServer, $key);

		if (!is_callable($resolver))
		{
			return null;
		}

		return $resolver($customServer);
	}

	/**
	 * @param CustomServerInterface $customServer
	 * @param string $key
	 * @return Closure(CustomServerInterface $customServer): mixed|null
	 */
	protected static function getResolver(CustomServerInterface $customServer, string $key): ?callable
	{
		$type = $customServer->getType();

		if (!$type instanceof CustomServerTypes)
		{
			return null;
		}

		return static::getResolvers()[$type->value][$key] ?? null;
	}

	/**
	 * @return array
	 */
	protected static function getResolvers(): array
	{
		if (!is_array(static::$resolvers))
		{
			static::$resolvers = [
				CustomServerTypes::R7->value => [
					'disk_onlyoffice_server' => fn(R7CustomServer $customServer) => $customServer->getUrl(),
					'disk_onlyoffice_secret_key' => fn(R7CustomServer $customServer) => $customServer->getSecretKey(),
					'disk_onlyoffice_max_filesize' => fn(R7CustomServer $customServer) => $customServer->getMaxFileSizeForUse(),
				],
				CustomServerTypes::OnlyOffice->value => [
					'disk_onlyoffice_server' => fn(OnlyOfficeCustomServer $customServer) => $customServer->getUrl(),
					'disk_onlyoffice_secret_key' => fn(OnlyOfficeCustomServer $customServer) => $customServer->getSecretKey(),
					'disk_onlyoffice_max_filesize' => fn(OnlyOfficeCustomServer $customServer) => $customServer->getMaxFileSizeForUse(),
				],
			];
		}

		return static::$resolvers;
	}
}
