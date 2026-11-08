<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\Ai;

use Bitrix\AI\Facade\User;
use Bitrix\Main\Loader;

/**
 * @internal
 */
class SettingsAccessGateway
{
	public function isCurrentUserAdmin(): bool
	{
		return Loader::includeModule('ai') && User::isAdmin();
	}
}
