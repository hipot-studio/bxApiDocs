<?php

namespace Bitrix\Superset\Public\Commands\Server;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Services\ServerRuntimeService;
use Bitrix\Superset\Public\Commands\Support\AbstractServerCommand;
use Bitrix\Superset\Public\Commands\Support\ServerResultDecorator;
use Bitrix\Superset\Public\Dto\ServerReferenceDto;

final class GenerateServerJwtKeysCommand extends AbstractServerCommand
{
	public function __construct(public readonly ServerReferenceDto $server)
	{
	}

	protected function execute(): Result
	{
		$resolvedServer = $this->resolveServer($this->server);

		return ServerResultDecorator::decorateGeneratedKeysResult(
			(new ServerRuntimeService())->generateJwtKeys($resolvedServer),
			$resolvedServer,
		);
	}
}
