<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24;

use Bitrix\Bitrix24\Public\Provider\User\AdminUserProvider;
use Bitrix\Main\Loader;

final class PortalAdminUsers
{
	public function getAdminIds(): array
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return [];
		}

		return (new AdminUserProvider())->getAdminUserIds();
	}
}
