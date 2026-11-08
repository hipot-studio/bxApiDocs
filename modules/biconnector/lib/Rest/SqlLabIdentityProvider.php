<?php

namespace Bitrix\BIConnector\Rest;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Integration\Superset\Integrator\Dto\User;
use Bitrix\BIConnector\Integration\Superset\Repository\SupersetUserRepository;
use Bitrix\Main\Engine\CurrentUser;

/**
 * Everything the REST boundary of SQL Lab needs to know about the caller: who the auth data
 * names, who the request is authenticated as and whether they may reach the BI builder.
 */
class SqlLabIdentityProvider
{
	public function getUserById(int $userId): ?User
	{
		return (new SupersetUserRepository())->getById($userId);
	}

	public function getCurrentUserId(): int
	{
		return (int)CurrentUser::get()->getId();
	}

	public function hasBiAccess(int $userId): bool
	{
		return (new AccessController($userId))->check(ActionDictionary::ACTION_BIC_ACCESS);
	}
}
