<?php

namespace Bitrix\Superset\Public\Commands\Cache;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Services\CacheService;
use Bitrix\Superset\Public\Commands\Support\AbstractServerCommand;
use Bitrix\Superset\Public\Dto\ServerReferenceDto;

/**
 * Boxed half of the permission cache reset.
 *
 * Separate from InvalidateSupersetCacheCommand on purpose: that one wipes the data caches of the instance
 * whole, which is why it stays switched off in cloud, while this narrow reset has to be on in a box.
 */
final class InvalidateSupersetPermissionCacheCommand extends AbstractServerCommand
{
	public function __construct(
		public readonly ServerReferenceDto $server,
		public readonly ?int $socketTimeout = null,
		public readonly ?int $streamTimeout = null,
	)
	{
	}

	protected function execute(): Result
	{
		return (new CacheService($this->resolveServer($this->server)))
			->invalidatePermissions($this->socketTimeout, $this->streamTimeout)
		;
	}
}
