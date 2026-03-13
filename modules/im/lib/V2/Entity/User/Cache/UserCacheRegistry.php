<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Entity\User\Cache;

use Bitrix\Im\V2\Cache\CacheConfig;
use Bitrix\Im\V2\Cache\CacheManager;
use Bitrix\Im\V2\Cache\Registry\DomainCacheRegistry;
use Bitrix\Im\V2\Entity\User\User;

class UserCacheRegistry extends DomainCacheRegistry
{
	private const USER_DATA = 'user_data';

	/**
	 * @return CacheManager<User>
	 */
	public function getUserDataManager(): CacheManager
	{
		return $this->getManager(self::USER_DATA);
	}

	protected function load(): void
	{
		$this->register(
			new CacheConfig(
				entityType: self::USER_DATA,
				ttl: $this->getTtl(),
				domain: 'user',
				version: 12,
				partitioningLevels: 2
			),
			new UserMapper()
		);
	}

	private function getTtl(): int
	{
		return defined("BX_COMP_MANAGED_CACHE") ? 18144000 : 1800;
	}
}