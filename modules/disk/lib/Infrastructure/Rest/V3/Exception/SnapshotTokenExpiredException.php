<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\V3\Exception;

/**
 * The token of the initial snapshot has run out. A snapshot is only consistent for as long as its
 * token lives, so the caller starts a new session instead of stitching the leftover pages onto it.
 */
final class SnapshotTokenExpiredException extends SyncRestException
{
	protected function getErrorCode(): string
	{
		return 'SYNC_EXPIRED';
	}

	protected function getMessagePhraseCode(): string
	{
		return 'DISK_SYNC_ERROR_SNAPSHOT_EXPIRED';
	}
}
