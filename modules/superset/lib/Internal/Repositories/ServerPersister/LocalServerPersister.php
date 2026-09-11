<?php

namespace Bitrix\Superset\Internal\Repositories\ServerPersister;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Entities\Server;
use Bitrix\Superset\Internal\Repositories\LocalServerRepository;

final class LocalServerPersister implements ServerPersisterInterface
{
	public function persist(Server $server): Result
	{
		return (new LocalServerRepository())->save($server);
	}
}
