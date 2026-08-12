<?php

namespace Bitrix\Superset\Public\Commands\Database;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Services\DatabaseService;
use Bitrix\Superset\Public\Commands\Support\AbstractServerCommand;
use Bitrix\Superset\Public\Dto\ServerReferenceDto;

final class RefreshDatabaseDomainCommand extends AbstractServerCommand
{
	public function __construct(
		public readonly ServerReferenceDto $server,
		public readonly string $portalUrl,
	)
	{
	}

	protected function execute(): Result
	{
		return (new DatabaseService($this->resolveServer($this->server)))->refreshDomain($this->portalUrl);
	}
}
