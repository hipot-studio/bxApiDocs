<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\V3\Exception;

/**
 * The object is addressed directly and is not there. Never used by reconciliation answers: telling
 * a hidden object apart from an absent one would let a caller probe for foreign objects.
 */
final class SyncObjectNotFoundException extends SyncRestException
{
	protected const STATUS = \CRestServer::STATUS_NOT_FOUND;

	protected function getErrorCode(): string
	{
		return 'OBJECT_NOT_FOUND';
	}

	protected function getMessagePhraseCode(): string
	{
		return 'DISK_SYNC_ERROR_OBJECT_NOT_FOUND';
	}
}
