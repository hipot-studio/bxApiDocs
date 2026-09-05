<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\Disk;

use Bitrix\Main\Result;

/**
 * Consumer contract of the mail module towards Disk for large mail attachments.
 *
 * The interface intentionally references only scalar types and mail-owned DTOs so that the mail module
 * compiles and is testable without the disk module loaded. Every operation acts on behalf of the sender,
 * whose id is passed explicitly. A unit of work is a set of attachments of a single upload that yields one link.
 */
interface LargeAttachmentStorageInterface
{
	public const ERROR_DISK_UNAVAILABLE = 'MAIL_LA_DISK_UNAVAILABLE';

	/**
	 * Returns (creating if needed) the sender's system "Mail attachments" folder.
	 *
	 * On success the result data holds the folder id under the "folderId" key (int).
	 */
	public function getMailAttachmentsFolder(int $userId): Result;

	/**
	 * Places the given set of Disk files into the system folder and creates a serviceable public link
	 * (canEditSettings=false, view+download access).
	 *
	 * On success the result data holds a LargeAttachmentResult under the "result" key.
	 *
	 * @param int[] $diskFileIds Disk file object ids of the upload set.
	 */
	public function uploadAndLink(int $userId, array $diskFileIds): Result;

	/**
	 * Extends a previously uploaded set with new source files without copying its existing files again.
	 *
	 * @param int[] $diskFileIds Complete source file set after the extension.
	 */
	public function extendAndLink(int $userId, string $token, array $diskFileIds): Result;

	public function finalizeReplacement(int $userId, string $previousToken, string $currentToken): Result;

	/**
	 * Resolves an opaque conversion token for sending and verifies that it belongs to the sender,
	 * contains exactly the supplied Disk file ids, and points to an active, ready public link.
	 *
	 * On success the result data holds a LargeAttachmentResult under the "result" key.
	 *
	 * @param int[] $diskFileIds Exact Disk file object ids returned by the conversion.
	 */
	public function resolveForSend(int $userId, string $token, array $diskFileIds): Result;

	/**
	 * Deletes a previously uploaded file/set addressed by its opaque token.
	 *
	 * On success the result data holds the outcome under the "deleted" key (bool).
	 */
	public function deleteUploaded(int $userId, string $token): Result;
}
