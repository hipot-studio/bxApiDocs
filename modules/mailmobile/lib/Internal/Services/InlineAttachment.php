<?php

declare(strict_types=1);

namespace Bitrix\MailMobile\Internal\Services;

/**
 * Inline references of an outgoing message body. The message carries its own copy of every attachment,
 * while the body written by the client still names the file it saw, so the references have to be pointed
 * at the attachment of this message before it goes out.
 */
final class InlineAttachment
{
	/**
	 * Points every reference the body may carry to one attachment at the content id it is sent under.
	 *
	 * A body restored from a draft references an inline image by the Disk object of the draft copy, a
	 * reply by the file its copy was made from, and a plain compose by the file itself. An id the send
	 * could not resolve is reported as 0 and references nothing.
	 *
	 * @param int[] $referencedIds
	 */
	public static function pointToContentId(string $body, string $contentId, array $referencedIds): string
	{
		foreach (array_unique(array_filter(array_map('intval', $referencedIds))) as $referencedId)
		{
			$body = (string)preg_replace(
				sprintf('/(https?:\/\/)?bxacid:n?%u(?!\d)/i', $referencedId),
				sprintf('cid:%s', $contentId),
				$body,
			);
		}

		return $body;
	}
}
