<?php

namespace Bitrix\Superset\Public\Commands\Support;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Superset\Internal\Entities\Server;
use Bitrix\Superset\Public\Dto\ServerReferenceDto;
use Bitrix\Superset\Public\Support\ServerResolver;

abstract class AbstractServerCommand extends AbstractCommand
{
	final protected function resolveServer(ServerReferenceDto $server): Server
	{
		return (new ServerResolver())->resolve($server);
	}
}
