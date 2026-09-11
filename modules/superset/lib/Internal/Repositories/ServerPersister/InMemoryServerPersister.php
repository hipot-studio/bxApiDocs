<?php

namespace Bitrix\Superset\Internal\Repositories\ServerPersister;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Entities\Server;

final class InMemoryServerPersister implements ServerPersisterInterface
{
	public function persist(Server $server): Result
	{
		return new Result();
	}
}
