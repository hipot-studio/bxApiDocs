<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\EventLog;

use Bitrix\Im\Model\EventLogTable;
use Bitrix\Im\Model\StatusTable;

class SubscriptionService
{
	public function subscribe(int $userId): void
	{
		StatusTable::merge(
			['USER_ID' => $userId, 'EVENT_LOG' => 'Y'],
			['EVENT_LOG' => 'Y']
		);

		$this->invalidateUserCache($userId);
	}

	public function unsubscribe(int $userId): void
	{
		StatusTable::merge(
			['USER_ID' => $userId, 'EVENT_LOG' => 'N'],
			['EVENT_LOG' => 'N']
		);

		EventLogTable::deleteBatch(['=USER_ID' => $userId]);
		(new PendingCache())->invalidate($userId);

		$this->invalidateUserCache($userId);
	}

	// Static caches must be cleared too — long-running processes would read stale EVENT_LOG otherwise.
	private function invalidateUserCache(int $userId): void
	{
		if (defined('BX_COMP_MANAGED_CACHE'))
		{
			$GLOBALS['CACHE_MANAGER']->ClearByTag('USER_NAME_' . $userId);
		}

		\CIMContactList::clearStaticUserDataCache();
		\Bitrix\Im\User::clearStaticCache();
	}
}
