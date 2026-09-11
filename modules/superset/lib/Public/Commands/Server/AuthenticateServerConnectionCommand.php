<?php

namespace Bitrix\Superset\Public\Commands\Server;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Services\ServerAccessTokenService;
use Bitrix\Superset\Public\Commands\Support\AbstractServerCommand;
use Bitrix\Superset\Public\Dto\ServerConnectionDto;

final class AuthenticateServerConnectionCommand extends AbstractServerCommand
{
	public function __construct(public readonly ServerConnectionDto $server)
	{
	}

	protected function execute(): Result
	{
		$resolvedServer = $this->resolveServer($this->server);

		$refreshResult = (new ServerAccessTokenService($resolvedServer))->refresh();
		if (!$refreshResult->isSuccess())
		{
			return $refreshResult;
		}

		$result = new Result();
		$result->setData([
			'token' => (string)$resolvedServer->getToken(),
			'refresh_token' => (string)$resolvedServer->getRefreshToken(),
		]);

		return $result;
	}
}
