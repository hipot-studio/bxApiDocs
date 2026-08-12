<?php

namespace Bitrix\Superset\Public\Commands\Server;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Services\JwtKeySynchronizationService;
use Bitrix\Superset\Public\Commands\Support\AbstractServerCommand;
use Bitrix\Superset\Public\Dto\ServerReferenceDto;

final class EnsureServerJwtKeySynchronizationCommand extends AbstractServerCommand
{
	public function __construct(
		public readonly ServerReferenceDto $server,
	)
	{
	}

	protected function execute(): Result
	{
		return (new JwtKeySynchronizationService($this->resolveServer($this->server)))->ensure();
	}
}
