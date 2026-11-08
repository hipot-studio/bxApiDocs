<?php

declare(strict_types=1);

namespace Bitrix\MailMobile\Internal\Integration\Mail;

use Bitrix\Mail\Helper\Message as MailHelperMessage;
use Bitrix\Mail\Public\Service\Message\AttachmentLimitService;

/**
 * The attachment size limit of a message, as the mail module states it. Everything mobile mail needs to know
 * about that limit passes through here: the compose form, the upload endpoint and the send path all ask this
 * adapter, so none of them carries its own copy of the limit or of the transport overhead formula.
 */
final class AttachmentLimit
{
	/**
	 * The size a message may spend on attachments before the transport encodes them.
	 */
	public static function getMaxTotalSize(): int
	{
		return self::hasPublicLimitApi()
			? AttachmentLimitService::getMaxTotalSize()
			: (int)MailHelperMessage::getMaxAttachedFilesSize()
		;
	}

	/**
	 * The same limit as the sum of raw sizes it allows, which is what the sender is shown.
	 */
	public static function getMaxTotalSizeAfterEncoding(): int
	{
		return self::hasPublicLimitApi()
			? AttachmentLimitService::getMaxTotalSizeAfterEncoding()
			: (int)MailHelperMessage::getMaxAttachedFilesSizeAfterEncoding()
		;
	}

	/**
	 * Whether the attachments left in the message no longer fit into it. The answer belongs to the mail
	 * module: a copy of the threshold here would let mobile mail and the mail module disagree about which
	 * set is too large to travel as attachments.
	 *
	 * @param int $totalRawSize Sum of raw (pre-encoding) sizes of the attachments, in bytes.
	 */
	public static function exceedsMailLimit(int $totalRawSize): bool
	{
		if (self::hasPublicLimitApi())
		{
			return AttachmentLimitService::exceedsLimit($totalRawSize);
		}

		// A portal that updated only mobile mail has no public limit service yet. The same size limit is
		// compared the same way, with the encoding overhead of the transport applied to the raw sum, so an
		// ordinary oversize attachment is still refused instead of reaching a fatal call to a class the
		// installed mail module does not carry.
		$maxSize = (int)MailHelperMessage::getMaxAttachedFilesSize();

		return $maxSize > 0 && $maxSize <= intdiv(max(0, $totalRawSize) + 2, 3) * 4;
	}

	/**
	 * The mail module is released on its own, so a build without the public limit service is answered by the
	 * long-standing helper of that very module instead of failing every send. This is the only place in
	 * mobile mail where that older path is left, and it is why the private zone of mail is still reached here.
	 */
	private static function hasPublicLimitApi(): bool
	{
		return class_exists(AttachmentLimitService::class);
	}
}
