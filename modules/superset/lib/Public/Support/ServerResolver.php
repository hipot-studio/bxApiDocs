<?php

namespace Bitrix\Superset\Public\Support;

use Bitrix\Main\ArgumentException;
use Bitrix\Superset\Internal\Entities\Server;
use Bitrix\Superset\Internal\Repositories\LocalServerRepository;
use Bitrix\Superset\Public\Dto\ServerConnectionDto;
use Bitrix\Superset\Public\Dto\ServerReferenceDto;

final class ServerResolver
{
	public function resolve(ServerReferenceDto|ServerConnectionDto $server): Server
	{
		if ($server instanceof ServerConnectionDto)
		{
			return $this->createUnsavedServer($server);
		}

		return $this->findServerByReference($server);
	}

	private function findServerByReference(ServerReferenceDto $serverReference): Server
	{
		$serverId = $serverReference->getServerId();
		if ($serverId <= 0)
		{
			throw new ArgumentException('serverId is required', 'serverId');
		}

		$server = (new LocalServerRepository())->findById($serverId);
		if (!$server instanceof Server)
		{
			throw new ArgumentException("Superset server {$serverId} not found", 'serverId');
		}

		return $server;
	}

	private function createUnsavedServer(ServerConnectionDto $connection): Server
	{
		$host = $connection->getHost();
		if ($host === '')
		{
			throw new ArgumentException('host is required', 'host');
		}

		return (new Server(sslVerificationEnabled: $connection->isSslVerificationEnabled()))
			->setHost($host)
			->setAccessPassword($connection->getAccessPassword())
			->setToken($connection->getToken())
			->setRefreshToken($connection->getRefreshToken())
		;
	}
}
