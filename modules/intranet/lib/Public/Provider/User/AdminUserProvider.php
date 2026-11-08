<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Provider\User;

use Bitrix\Intranet\Service\ServiceContainer;

final class AdminUserProvider
{
	/** @return list<int> */
	public function getAdminUserIds(): array
	{
		return array_values(array_unique(array_filter(
			array_map('intval', ServiceContainer::getInstance()->getUserService()->getAdminUserIds()),
			static fn(int $userId): bool => $userId > 0,
		)));
	}
}
