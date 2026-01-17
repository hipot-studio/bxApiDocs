<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Access\Registry\Preload;

use Bitrix\Tasks\V2\Internal\Access\Registry\ElapsedTimeRegistry;

class ElapsedTimeAccessCachePreloader
{
	public function preload(array $elapsedTimeIds): void
	{
		$registry = ElapsedTimeRegistry::getInstance();

		$registry->load($elapsedTimeIds);
	}
}
