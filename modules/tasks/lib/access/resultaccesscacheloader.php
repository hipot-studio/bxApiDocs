<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Access;

use Bitrix\Tasks\V2\Internal\Access\Registry\ResultRegistry;

class ResultAccessCacheLoader
{
	public function preload(array $resultIds): void
	{
		$registry = ResultRegistry::getInstance();

		$registry->load($resultIds);
	}
}
