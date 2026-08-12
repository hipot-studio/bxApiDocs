<?php

namespace Bitrix\Superset\Public\Support;

use Bitrix\Main\ArgumentException;
use Bitrix\Superset\Internal\Entities\Server;
use Bitrix\Superset\Internal\Repositories\LocalServerRepository;
use Bitrix\Superset\Public\Dto\ServerReferenceDto;

final class ServerResolver
{
	public function resolve(ServerReferenceDto $serverReference): Server
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
}
