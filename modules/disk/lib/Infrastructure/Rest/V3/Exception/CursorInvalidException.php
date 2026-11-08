<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\V3\Exception;

/**
 * The cursor belongs to another user, or to another application of the same user. Both cases answer
 * alike: a cursor is only meaningful inside the session that minted it.
 */
final class CursorInvalidException extends SyncRestException
{
	protected function getErrorCode(): string
	{
		return 'CURSOR_INVALID';
	}

	protected function getMessagePhraseCode(): string
	{
		return 'DISK_SYNC_ERROR_CURSOR_INVALID';
	}
}
