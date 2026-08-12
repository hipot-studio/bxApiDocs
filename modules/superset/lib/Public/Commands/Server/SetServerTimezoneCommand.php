<?php

namespace Bitrix\Superset\Public\Commands\Server;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Services\TimezoneService;
use Bitrix\Superset\Public\Commands\Support\AbstractServerCommand;
use Bitrix\Superset\Public\Dto\ServerReferenceDto;

final class SetServerTimezoneCommand extends AbstractServerCommand
{
	public function __construct(
		public readonly ServerReferenceDto $server,
		public readonly string $timezone,
	)
	{
	}

	protected function execute(): Result
	{
		return (new TimezoneService($this->resolveServer($this->server)))->setTimezone($this->timezone);
	}
}
