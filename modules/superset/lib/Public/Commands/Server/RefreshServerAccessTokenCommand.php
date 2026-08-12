<?php

namespace Bitrix\Superset\Public\Commands\Server;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Services\ServerAccessTokenService;
use Bitrix\Superset\Public\Commands\Support\AbstractServerCommand;
use Bitrix\Superset\Public\Commands\Support\ServerResultDecorator;
use Bitrix\Superset\Public\Dto\ServerReferenceDto;

final class RefreshServerAccessTokenCommand extends AbstractServerCommand
{
	public function __construct(public readonly ServerReferenceDto $server)
	{
	}

	protected function execute(): Result
	{
		$resolvedServer = $this->resolveServer($this->server);

		return ServerResultDecorator::decorateServerContextResult(
			(new ServerAccessTokenService($resolvedServer))->refresh(),
			$resolvedServer,
		);
	}
}
