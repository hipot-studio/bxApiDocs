<?php

namespace Bitrix\Superset\Internal\Repositories\ServerPersister;

use Bitrix\Superset\Internal\Entities\Server;

final class ServerPersisterFactory
{
	public static function forServer(Server $server): ServerPersisterInterface
	{
		return $server->getId() > 0 ? new LocalServerPersister() : new InMemoryServerPersister();
	}
}
