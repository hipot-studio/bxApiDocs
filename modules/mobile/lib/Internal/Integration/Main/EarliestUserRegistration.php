<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Integration\Main;

use Bitrix\Main\Application;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserTable;

/**
 * The moment the earliest user of the portal registered: outside the cloud the portal states no creation
 * date of its own, and its first user is the closest record of one.
 *
 * The moment is asked for on every menu, so it is kept out of the hot path by a long cache that the portal
 * user list drops as soon as it changes.
 */
class EarliestUserRegistration
{
	private const CACHE_DIR = '/mobile/earliest_user_registration/';
	private const CACHE_KEY = 'earliest_user_registration';
	private const CACHE_TTL = 2592000;

	/** Cleared by CUser on every add, update and delete of a real portal user. */
	private const USER_LIST_TAG = 'USER_CARD';

	/**
	 * Null while the portal has no users.
	 */
	public function get(): ?DateTime
	{
		$timestamp = $this->readCachedTimestamp();
		if ($timestamp === null)
		{
			$timestamp = $this->fetchTimestamp();
			if ($timestamp === null)
			{
				return null;
			}

			$this->writeCachedTimestamp($timestamp);
		}

		return DateTime::createFromTimestamp($timestamp);
	}

	protected function fetchTimestamp(): ?int
	{
		$row = UserTable::query()
			->registerRuntimeField(new ExpressionField(
				'EARLIEST_DATE_REGISTER',
				'MIN(%s)',
				['DATE_REGISTER'],
				['data_type' => 'datetime'],
			))
			->setSelect(['EARLIEST_DATE_REGISTER'])
			->fetch()
		;

		$registeredAt = $row['EARLIEST_DATE_REGISTER'] ?? null;

		return $registeredAt instanceof DateTime ? $registeredAt->getTimestamp() : null;
	}

	protected function readCachedTimestamp(): ?int
	{
		$cache = Cache::createInstance();

		return $cache->initCache(self::CACHE_TTL, self::CACHE_KEY, self::CACHE_DIR)
			? (int)$cache->getVars()
			: null
		;
	}

	protected function writeCachedTimestamp(int $timestamp): void
	{
		$taggedCache = Application::getInstance()->getTaggedCache();
		$taggedCache->startTagCache(self::CACHE_DIR);
		$taggedCache->registerTag(self::USER_LIST_TAG);
		$taggedCache->endTagCache();

		$cache = Cache::createInstance();
		if ($cache->startDataCache(self::CACHE_TTL, self::CACHE_KEY, self::CACHE_DIR))
		{
			$cache->endDataCache($timestamp);
		}
	}
}
