<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\NewApps;

use Bitrix\Main\Application;
use Bitrix\Main\Data\ManagedCache;

class NewAppsCountCache
{
	private const TTL = 300;
	private const TABLE_ID = 'vibecodeconnector_new_apps_count';
	private const CACHE_ID_PREFIX = 'vibecodeconnector_new_apps_count_';

	private readonly ManagedCache $managedCache;

	public function __construct(?ManagedCache $managedCache = null)
	{
		$this->managedCache = $managedCache ?? Application::getInstance()->getManagedCache();
	}

	/**
	 * @param callable():int $compute
	 */
	public function remember(int $userId, callable $compute): int
	{
		return $this->rememberConditional($userId, fn (): array => ['count' => $compute(), 'cacheable' => true]);
	}

	public function rememberConditional(int $userId, callable $compute): int
	{
		$cacheId = $this->cacheId($userId);
		if ($this->managedCache->read(self::TTL, $cacheId, self::TABLE_ID))
		{
			$cached = $this->managedCache->get($cacheId);
			if (is_int($cached))
			{
				return $cached;
			}
		}

		$value = $compute();
		if ($value['cacheable'])
		{
			$this->managedCache->set($cacheId, $value['count']);
		}

		return $value['count'];
	}

	public function forget(int $userId): void
	{
		$this->managedCache->clean($this->cacheId($userId), self::TABLE_ID);
	}

	public function forgetMany(array $userIds): void
	{
		foreach (array_unique(array_map('intval', $userIds)) as $userId)
		{
			if ($userId > 0)
			{
				$this->forget($userId);
			}
		}
	}

	public function forgetAll(): void
	{
		$this->managedCache->cleanDir(self::TABLE_ID);
	}

	private function cacheId(int $userId): string
	{
		return self::CACHE_ID_PREFIX . $userId;
	}
}
