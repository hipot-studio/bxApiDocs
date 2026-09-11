<?php

namespace Bitrix\Superset\Public\Commands\Support;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Superset\Internal\Connector\SupersetInstance;
use Bitrix\Superset\Internal\Entities\Server;
use Bitrix\Superset\Internal\Repositories\ServerPersister\TokenWriterServerPersister;
use Bitrix\Superset\Public\Dto\ServerConnectionDto;
use Bitrix\Superset\Public\Dto\ServerReferenceDto;
use Bitrix\Superset\Public\Support\ServerResolver;
use Bitrix\Superset\Public\Support\TokenWriterInterface;

abstract class AbstractServerCommand extends AbstractCommand
{
	final protected function resolveServer(ServerReferenceDto|ServerConnectionDto $server): Server
	{
		return (new ServerResolver())->resolve($server);
	}

	final protected function createConnector(Server $server, ?TokenWriterInterface $tokenWriter): ?SupersetInstance
	{
		if ($tokenWriter === null)
		{
			return null;
		}

		return new SupersetInstance($server, [], new TokenWriterServerPersister($tokenWriter));
	}
}
