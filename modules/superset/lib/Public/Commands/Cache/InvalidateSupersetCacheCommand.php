<?php

namespace Bitrix\Superset\Public\Commands\Cache;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Services\CacheService;
use Bitrix\Superset\Public\Commands\Support\AbstractServerCommand;
use Bitrix\Superset\Public\Dto\ServerReferenceDto;

final class InvalidateSupersetCacheCommand extends AbstractServerCommand
{
	public function __construct(public readonly ServerReferenceDto $server)
	{
	}

	protected function execute(): Result
	{
		return (new CacheService($this->resolveServer($this->server)))->invalidate();
	}
}
