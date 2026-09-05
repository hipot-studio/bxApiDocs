<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\LargeAttachment;

use Bitrix\Mail\Internals\MailMessageAttachmentTable;
use Bitrix\Mail\Integration\Disk\LargeAttachmentStorageFactory;
use Bitrix\Mail\Integration\Disk\LargeAttachmentStorageInterface;
use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Business logic for large mail attachments: converting an over-limit attachment set into a Disk
 * link, recognizing already-converted attachments for the send guard, and deleting an uploaded set.
 *
 * This is the single source of truth; the compose action and the send guard delegate here instead of
 * re-implementing the conversion or the over-limit logic. All Disk access goes through
 * {@see LargeAttachmentStorageFactory} / {@see LargeAttachmentStorageInterface}, never the Disk API
 * directly. The tariff and ownership checks are the caller's responsibility (see LicenseManager); this
 * service trusts the already-validated context it receives.
 */
final class LargeAttachmentService
{
	/**
	 * Result data key under which a successful convert() exposes the
	 * {@see \Bitrix\Mail\Integration\Disk\Dto\LargeAttachmentResult}.
	 */
	public const RESULT_KEY = 'result';

	/** Result data key under which a successful deleteUploaded() exposes the boolean outcome. */
	public const DELETED_KEY = 'deleted';

	public const ERROR_INVALID_SEND_RESULT = 'MAIL_LA_INVALID_SEND_RESULT';
	public const ERROR_MARKER_SAVE_FAILED = 'MAIL_LA_MARKER_SAVE_FAILED';
	public const ERROR_TOO_MANY_FILES = 'MAIL_LA_TOO_MANY_FILES';
	public const MAX_FILE_COUNT = 100;

	/**
	 * Attachment field carrying the Disk external link id once the set has been converted. The
	 * persistent column is introduced in phase P3; here the field is read only from the row/DTO passed
	 * in by the caller, so recognition stays pure and does not touch the ORM schema.
	 */
	public const EXTERNAL_LINK_FIELD = 'EXTERNAL_LINK_ID';

	/**
	 * Whether the given attachment set is large enough to be offloaded to Disk, per the canonical
	 * threshold {@see AttachmentSizeGuard}. The over-limit formula lives in the guard alone; entry points
	 * (compose action, send guard) decide through the service rather than reaching into the guard.
	 *
	 * @param int $totalRawSize Sum of raw (pre-base64) sizes of the attachment set, in bytes.
	 */
	public static function isLargeAttachmentSet(int $totalRawSize): bool
	{
		return AttachmentSizeGuard::exceedsMailLimit($totalRawSize);
	}

	/**
	 * Places the given set of Disk files into the sender's system folder and creates a serviceable link.
	 *
	 * On success the result data holds a LargeAttachmentResult under {@see self::RESULT_KEY}. On the stub
	 * storage the disk-unavailable error is returned as a Result error, never a fatal.
	 *
	 * @param int[] $fileIds Disk file object ids of the upload set.
	 */
	public static function convert(int $userId, array $fileIds, ?string $replacementToken = null): Result
	{
		if (count($fileIds) > self::MAX_FILE_COUNT)
		{
			return (new Result())->addError(new Error(
				'Too many files in a large attachment set.',
				self::ERROR_TOO_MANY_FILES,
			));
		}

		$storage = self::getStorage();
		if ($replacementToken !== null && $replacementToken !== '')
		{
			return $storage->extendAndLink($userId, $replacementToken, $fileIds);
		}

		return $storage->uploadAndLink($userId, $fileIds);
	}

	/**
	 * Resolves and verifies a conversion token before the corresponding files are excluded from MIME.
	 *
	 * @param int[] $fileIds Exact Disk file object ids returned by convert().
	 */
	public static function resolveForSend(
		int $userId,
		string $token,
		array $fileIds,
		?LargeAttachmentStorageInterface $storage = null,
	): Result
	{
		return ($storage ?? self::getStorage())->resolveForSend($userId, $token, $fileIds);
	}

	/**
	 * Deletes a previously uploaded set addressed by its opaque token (API-02).
	 *
	 * On success the result data holds the outcome under {@see self::DELETED_KEY}.
	 */
	public static function deleteUploaded(int $userId, string $token): Result
	{
		return self::getStorage()->deleteUploaded($userId, $token);
	}

	public static function finalizeReplacement(int $userId, string $previousToken, string $currentToken): Result
	{
		return self::getStorage()->finalizeReplacement($userId, $previousToken, $currentToken);
	}

	/**
	 * Whether the given attachment row is already converted to a Disk link and therefore must not be
	 * treated as over-limit by the send guard.
	 *
	 * @param array $attachment Attachment row/DTO as an associative array.
	 */
	public static function isConverted(array $attachment): bool
	{
		return (int)($attachment[self::EXTERNAL_LINK_FIELD] ?? 0) > 0;
	}

	/**
	 * Registers service-link attachment markers for an outgoing message saved in the local mailbox.
	 *
	 * @param int[] $externalLinkIds
	 */
	public static function registerMessageMarkers(int $messageId, array $externalLinkIds): Result
	{
		$result = new Result();
		$externalLinkIds = array_values(array_unique(array_map('intval', $externalLinkIds)));

		if ($messageId <= 0 || !$externalLinkIds || min($externalLinkIds) <= 0)
		{
			return $result->addError(new Error(
				'Large attachment marker data is invalid.',
				self::ERROR_MARKER_SAVE_FAILED,
			));
		}

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			foreach ($externalLinkIds as $externalLinkId)
			{
				$addResult = MailMessageAttachmentTable::add([
					'MESSAGE_ID' => $messageId,
					self::EXTERNAL_LINK_FIELD => $externalLinkId,
				]);
				if (!$addResult->isSuccess())
				{
					$connection->rollbackTransaction();

					return $result->addErrors($addResult->getErrors());
				}
			}

			$connection->commitTransaction();
		}
		catch (\Throwable $exception)
		{
			$connection->rollbackTransaction();
			$result->addError(new Error(
				$exception->getMessage(),
				self::ERROR_MARKER_SAVE_FAILED,
			));
		}

		return $result;
	}

	private static function getStorage(): LargeAttachmentStorageInterface
	{
		return LargeAttachmentStorageFactory::getInstance();
	}
}
