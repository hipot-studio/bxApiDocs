<?php

namespace Bitrix\Superset\Public\Commands\Database;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Services\DatabaseService;
use Bitrix\Superset\Public\Commands\Support\AbstractServerCommand;
use Bitrix\Superset\Public\Dto\ServerReferenceDto;

final class ChangeDatabaseTokenCommand extends AbstractServerCommand
{
	public function __construct(
		public readonly ServerReferenceDto $server,
		public readonly string $token,
	)
	{
	}

	protected function execute(): Result
	{
		return (new DatabaseService($this->resolveServer($this->server)))->changeToken($this->token);
	}
}
