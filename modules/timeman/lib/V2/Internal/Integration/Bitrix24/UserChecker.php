<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Integration\Bitrix24;

use Bitrix\Main\Loader;

class UserChecker
{
	public function isPortalAdmin(int $userId): bool
	{
		if (!$this->isModuleInstalled() || $userId <= 0)
		{
			return false;
		}

		return \CBitrix24::isPortalAdmin($userId);
	}

	private function isModuleInstalled(): bool
	{
		return Loader::includeModule('bitrix24');
	}
}
