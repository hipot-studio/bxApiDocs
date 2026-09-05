<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\LargeAttachment;

use Bitrix\Mail\Helper\Message;

/**
 * Canonical "large attachment set" threshold (contract ALG-01).
 *
 * This is the single server-side source of the formula. The size limit and the base64 overhead
 * coefficient must stay identical to the send-time check in mail.client/ajax.php and to the client
 * mirror, otherwise "large" would be defined in more than one place.
 */
final class AttachmentSizeGuard
{
	/**
	 * @param int $totalRawSize Sum of raw (pre-base64) sizes of the attachment set, in bytes.
	 */
	public static function exceedsMailLimit(int $totalRawSize): bool
	{
		$maxSize = (int)Message::getMaxAttachedFilesSize();

		return $maxSize > 0 && $maxSize <= ceil($totalRawSize / 3) * 4;
	}
}
