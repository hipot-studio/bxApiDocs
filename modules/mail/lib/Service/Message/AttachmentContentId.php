<?php

declare(strict_types=1);

namespace Bitrix\Mail\Service\Message;

/**
 * The Content-ID an outgoing attachment is carried under. Both ways out of the form name a file the same way —
 * the immediate sending of the mail client and the dispatcher of a delayed letter — so an image pasted into the
 * editor points at its own part of the letter whichever way the letter left.
 */
final class AttachmentContentId
{
	/** @param array<string, mixed> $file A file of the storage, as `\CFile::makeFileArray()` describes it. */
	public static function build(array $file, string $hostname): string
	{
		return sprintf(
			'bxacid.%s@%s.mail',
			hash(
				'crc32b',
				(string)($file['external_id'] ?? '')
					. (string)($file['size'] ?? '')
					. (string)($file['name'] ?? ''),
			),
			hash('crc32b', $hostname),
		);
	}
}
