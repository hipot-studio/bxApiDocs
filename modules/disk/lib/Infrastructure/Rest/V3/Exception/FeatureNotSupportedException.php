<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\V3\Exception;

/**
 * "The portal cannot do this", as opposed to "the request is wrong". A contour switched off on the
 * portal answers with it too, so that a client never reads an empty answer as "the user has nothing".
 */
final class FeatureNotSupportedException extends SyncRestException
{
	protected function getErrorCode(): string
	{
		return 'FEATURE_NOT_SUPPORTED';
	}

	protected function getMessagePhraseCode(): string
	{
		return 'DISK_SYNC_ERROR_FEATURE_NOT_SUPPORTED';
	}
}
