<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Intranet;

use Bitrix\Intranet\Public\Provider\User\AdminUserProvider;
use Bitrix\Main\Loader;

final class PortalAdminUsers
{
	public function getAdminIds(): array
	{
		if (!Loader::includeModule('intranet'))
		{
			return [];
		}

		return (new AdminUserProvider())->getAdminUserIds();
	}
}
