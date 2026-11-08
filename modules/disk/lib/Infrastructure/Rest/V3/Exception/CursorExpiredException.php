<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\V3\Exception;

/**
 * The position the caller resumes from is older than the change journal keeps. The journal cannot
 * tell what happened before it, so the client rebuilds its memory by a reconciliation pass rather
 * than by replaying changes that no longer exist.
 */
final class CursorExpiredException extends SyncRestException
{
	protected function getErrorCode(): string
	{
		return 'CURSOR_EXPIRED';
	}

	protected function getMessagePhraseCode(): string
	{
		return 'DISK_SYNC_ERROR_CURSOR_EXPIRED';
	}
}
