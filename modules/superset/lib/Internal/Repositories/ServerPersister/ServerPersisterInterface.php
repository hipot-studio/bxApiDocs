<?php

namespace Bitrix\Superset\Internal\Repositories\ServerPersister;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Entities\Server;

interface ServerPersisterInterface
{
	public function persist(Server $server): Result;
}
