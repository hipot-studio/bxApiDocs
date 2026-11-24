<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Marketplace\Banner\Event\OnRestAppInstall;

use Bitrix\Intranet\Internal\Marketplace\Banner\ProjectionCache;

class ClearProjectionCache
{
	public function __construct(
		private readonly ProjectionCache $projectionCache = new ProjectionCache(),
	)
	{
	}

	public static function handle(): void
	{
		(new static())->clear();
	}

	public function clear(): void
	{
		$this->projectionCache->clean();
	}
}
