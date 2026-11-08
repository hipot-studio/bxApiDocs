<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\V3\Exception;

/**
 * The request reaches past what the caller may see. The same refusal answers a content request for
 * an object marked as having no readable content.
 */
final class SyncAccessDeniedException extends SyncRestException
{
	protected const STATUS = \CRestServer::STATUS_FORBIDDEN;

	protected function getErrorCode(): string
	{
		return 'ACCESS_DENIED';
	}

	protected function getMessagePhraseCode(): string
	{
		return 'DISK_SYNC_ERROR_ACCESS_DENIED';
	}
}
