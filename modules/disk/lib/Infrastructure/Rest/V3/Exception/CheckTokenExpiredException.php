<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\V3\Exception;

/**
 * The token of a scoped reconciliation pass has run out. It is told apart from the snapshot token
 * on purpose: sharing one code would send the caller to recreate a session that reconciliation
 * never needed.
 */
final class CheckTokenExpiredException extends SyncRestException
{
	protected function getErrorCode(): string
	{
		return 'CHECK_TOKEN_EXPIRED';
	}

	protected function getMessagePhraseCode(): string
	{
		return 'DISK_SYNC_ERROR_CHECK_TOKEN_EXPIRED';
	}
}
