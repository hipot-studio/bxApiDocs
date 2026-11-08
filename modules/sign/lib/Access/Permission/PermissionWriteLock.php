<?php

namespace Bitrix\Sign\Access\Permission;

use Bitrix\Main\Application;
use Bitrix\Sign\Service\Container;

/**
 * The advisory lock shared by every write path of b_sign_permission, together with the connection routing
 * such a write needs. The table carries a unique index on (ROLE_ID, PERMISSION_ID) and has two writers -
 * the role save {@see \Bitrix\Sign\Access\Service\RolePermissionService::saveRolePermissions()} and the
 * installer of the permissions missing from the default roles
 * {@see \Bitrix\Sign\Access\Install\AccessInstaller::installMissingPermissions()} - so only one lock name
 * for all of them serializes the read-write cycle they share.
 */
final class PermissionWriteLock
{
	public const NAME = 'sign_access_permission_write';

	/**
	 * A writer that finds the lock taken waits rather than gives up: a refusal costs an administrator their save,
	 * and the alternative - a lock narrow enough to never be waited for - would leave the CRM half of a save
	 * stored while the sign half is refused {@see \Bitrix\Sign\Access\Service\RolePermissionService::saveRolePermissions()}.
	 * The wait is bounded well above the measured length of the section (0.154 s for 10 roles, around 0.8 s for a
	 * portal of 50) and well below the point where waiting requests would pile up on the web server.
	 */
	public const TIMEOUT = 3;

	/**
	 * The lock statement of the MySQL driver binds the master inside lock() itself, the PostgreSQL one does
	 * not, so it is bound here for both.
	 */
	public static function acquire(): bool
	{
		return self::onMaster(
			static fn (): bool => Application::getConnection()->lock(self::NAME, self::TIMEOUT),
		);
	}

	/**
	 * Releases the lock if it can. A failure is only logged: raised from the finally of a write already
	 * committed it would replace the result of that write, and the caller would retry a save that had in
	 * fact succeeded. A lock left behind is dropped by the database when the connection closes.
	 */
	public static function release(): void
	{
		try
		{
			// Without the mode the release of the PostgreSQL driver reads as a plain select and the pool may
			// route it to a replica: released there, the lock would stay held on the master.
			self::onMaster(static fn (): bool => Application::getConnection()->unlock(self::NAME));
		}
		catch (\Throwable $exception)
		{
			Container::instance()->getLogger('Access')->error(
				'Unable to release the lock ' . self::NAME . ': ' . $exception->getMessage(),
			);
		}
	}

	/**
	 * Runs a read of the locked section against the master. The write paths compute their delta from the rows
	 * the lock protects, and a replica would report a state older than the lock - one that neither matches
	 * the master nor carries the rows another writer has already stored there.
	 *
	 * Only reads belong in the mode, never the writes: while it is on, ConnectionPool::getSlaveConnection()
	 * returns before it registers the tables a query modifies, so a write made under the mode stays outside
	 * the read-after-write guarantee of the pool. The callers of a permission write read the result right
	 * back - the members assigned to a role saved a moment earlier, the roles the form is refilled with -
	 * and such a read could then be served by a replica that does not carry the write yet.
	 *
	 * @template T
	 * @param callable(): T $read
	 * @return T
	 */
	public static function readOnMaster(callable $read): mixed
	{
		return self::onMaster($read);
	}

	private static function onMaster(callable $query): mixed
	{
		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);
		try
		{
			return $query();
		}
		finally
		{
			$pool->useMasterOnly(false);
		}
	}
}
