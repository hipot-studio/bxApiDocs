<?php

namespace Bitrix\Superset\Internal\Repositories\ServerPersister;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Entities\Server;
use Bitrix\Superset\Internal\Support\ServerTokenWriterInterface;

final class TokenWriterServerPersister implements ServerPersisterInterface
{
	public function __construct(private readonly ServerTokenWriterInterface $tokenWriter)
	{
	}

	public function persist(Server $server): Result
	{
		$this->tokenWriter->write($server->getToken(), $server->getRefreshToken());

		return new Result();
	}
}
