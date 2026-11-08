<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Flipchart\Comment;

use Bitrix\Main\Application;

/**
 * The named lock of a board, shared by annotation and comment writers and the handler of the final
 * deletion of the file. A writer reads the board again under it, so a deletion that has begun cannot
 * be written past, and the deletion drops the rows under it, so no row is written while they go.
 *
 * It is always taken before a thread or annotation documentId lock, and is reentrant inside one
 * connection, like every named lock of the portal.
 */
final class BoardLock
{
	private const LOCK_PREFIX = 'disk.flipchart.comment.board.';
	private const LOCK_TIMEOUT = 15;

	public static function acquire(int $objectId): bool
	{
		return Application::getConnection()->lock(self::name($objectId), self::LOCK_TIMEOUT);
	}

	public static function release(int $objectId): bool
	{
		return Application::getConnection()->unlock(self::name($objectId));
	}

	private static function name(int $objectId): string
	{
		return hash('sha256', self::LOCK_PREFIX . $objectId);
	}
}
