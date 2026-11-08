<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\V3\Exception;

/**
 * The caller asked for a schema version the contour does not serve. The cursor survives the refusal:
 * the disagreement is about the shape of the answer, not about the position in the journal.
 */
final class SchemaVersionUnsupportedException extends SyncRestException
{
	protected function getErrorCode(): string
	{
		return 'SCHEMA_VERSION_UNSUPPORTED';
	}

	protected function getMessagePhraseCode(): string
	{
		return 'DISK_SYNC_ERROR_SCHEMA_UNSUPPORTED';
	}
}
