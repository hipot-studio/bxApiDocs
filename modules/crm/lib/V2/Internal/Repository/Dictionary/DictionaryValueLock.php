<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\Dictionary;

use Bitrix\Crm\V2\Internal\Entity\Dictionary\DictionaryType;
use Bitrix\Main\Application;

/**
 * The named lock of the portal behind {@see DictionaryValueLockInterface}.
 *
 * The connection is bound to the master for as long as the lock is held. {@see \Bitrix\Main\DB\Connection::lock()}
 * binds it around the lock statement alone, so on a portal with replicas a read under the lock could get
 * a stale answer - and every write of this boundary reads under the lock before it writes, which is the
 * whole reason the lock is there.
 *
 * The name carries the dictionary and the identifier, and the connection hashes it, so an identifier of
 * any length and any alphabet is a name the storage accepts.
 *
 * On a portal whose driver has no named locks the base implementation answers every acquire with `true`,
 * which leaves the writes exactly as unserialized as they were before this class - the same degradation
 * every other named lock of the module has.
 *
 * @internal
 */
final class DictionaryValueLock implements DictionaryValueLockInterface
{
	/** Tells the locks of this domain apart from every other named lock of the portal. */
	private const NAME_PREFIX = 'crm_dictionary_value';

	/**
	 * Seconds a write waits for the value it addresses to be free. Configuring the dictionaries of a
	 * portal is done by hand and one value at a time, so a wait this long is reached only by a caller
	 * hammering the same value - and answering it is better than letting it write past the check.
	 */
	private const TIMEOUT = 10;

	public function acquire(DictionaryType $type, string $id): bool
	{
		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);

		$acquired = false;
		try
		{
			$acquired = Application::getConnection()->lock(self::nameOf($type, $id), self::TIMEOUT);
		}
		finally
		{
			if (!$acquired)
			{
				// The binding lives as long as the lock does, and a lock that was not taken is never released.
				$pool->useMasterOnly(false);
			}
		}

		return $acquired;
	}

	public function release(DictionaryType $type, string $id): void
	{
		try
		{
			Application::getConnection()->unlock(self::nameOf($type, $id));
		}
		finally
		{
			Application::getInstance()->getConnectionPool()->useMasterOnly(false);
		}
	}

	private static function nameOf(DictionaryType $type, string $id): string
	{
		return self::NAME_PREFIX . '_' . $type->value . '_' . $id;
	}
}
