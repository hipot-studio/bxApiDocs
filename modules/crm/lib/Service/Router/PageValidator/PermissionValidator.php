<?php

declare(strict_types=1);

namespace Bitrix\Crm\Service\Router\PageValidator;

use Bitrix\Crm\Service\Router\Contract\PageValidator;

final readonly class PermissionValidator implements PageValidator
{
	public function __construct(
		private \Closure $permissionCheckCallback,
	)
	{
	}

	public function isAvailable(): bool
	{
		return ($this->permissionCheckCallback)();
	}

	public function showError(): void
	{
		ShowError(\Bitrix\Crm\Controller\ErrorCode::getAccessDeniedError()->getMessage());
	}
}
