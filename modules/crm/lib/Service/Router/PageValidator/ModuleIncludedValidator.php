<?php

declare(strict_types=1);

namespace Bitrix\Crm\Service\Router\PageValidator;

use Bitrix\Crm\Service\Router\Contract\PageValidator;
use Bitrix\Main\Loader;

final readonly class ModuleIncludedValidator implements PageValidator
{
	public function __construct(
		private string $moduleId,
	)
	{
	}

	public function isAvailable(): bool
	{
		return Loader::includeModule($this->moduleId);
	}

	public function showError(): void
	{
		ShowError(\Bitrix\Crm\Controller\ErrorCode::getModuleNotInstalledError($this->moduleId)->getMessage());
	}
}
