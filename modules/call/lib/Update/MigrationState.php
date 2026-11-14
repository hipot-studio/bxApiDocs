<?php

namespace Bitrix\Call\Update;

use Bitrix\Main\Config\Option;

/**
 * Tells whether the im -> call data migration of this portal is over.
 *
 * The verdict comes from the stepper cursors and nothing else. Rows left in the legacy b_im_*
 * tables are not a sign of an unfinished migration: the migration is partial by design - only
 * recent and referenced calls are copied - so a non-empty b_im_call is the normal outcome of a
 * completed one.
 *
 * @internal
 */
final class MigrationState
{
	private const MODULE_ID = 'call';

	private const CALL_CURSOR = 'call_db_migrated';
	private const CALL_LAST_STEP = 5;

	private const USERLOG_CURSOR = 'userlogs_call_db_migrated';
	private const USERLOG_LAST_STEP = 1;

	public static function isCallMigrationCompleted(): bool
	{
		return self::getCursor(self::CALL_CURSOR) >= self::CALL_LAST_STEP;
	}

	public static function isUserlogMigrationCompleted(): bool
	{
		return self::getCursor(self::USERLOG_CURSOR) >= self::USERLOG_LAST_STEP;
	}

	public static function isCompleted(): bool
	{
		return self::isCallMigrationCompleted() && self::isUserlogMigrationCompleted();
	}

	/**
	 * Records the migration as over on a portal that has no legacy data to migrate at all.
	 *
	 * The steppers own the cursors while there is something to copy; this is the one state they
	 * cannot reach themselves, because a missing legacy source makes them fail instead of finish.
	 * A cursor that already stands above its last step is left alone.
	 */
	public static function markNothingToMigrate(): void
	{
		if (!self::isCallMigrationCompleted())
		{
			Option::set(self::MODULE_ID, self::CALL_CURSOR, self::CALL_LAST_STEP);
		}
		if (!self::isUserlogMigrationCompleted())
		{
			Option::set(self::MODULE_ID, self::USERLOG_CURSOR, self::USERLOG_LAST_STEP);
		}
	}

	private static function getCursor(string $name): int
	{
		return (int)Option::get(self::MODULE_ID, $name, 0);
	}
}
