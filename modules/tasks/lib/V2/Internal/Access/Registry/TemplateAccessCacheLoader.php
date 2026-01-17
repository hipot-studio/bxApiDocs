<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Access\Registry;

class TemplateAccessCacheLoader
{
	public function preload(array $templateIds): void
	{
		$registry = TemplateRegistry::getInstance();

		$registry->load($templateIds);
	}
}
