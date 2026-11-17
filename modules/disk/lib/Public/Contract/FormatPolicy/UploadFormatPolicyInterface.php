<?php
declare(strict_types=1);

namespace Bitrix\Disk\Public\Contract\FormatPolicy;

use Bitrix\Main\Error;

/**
 * Verdict of the format policy of Disk on a file uploaded by another module, the same one the upload
 * of Disk itself gets in the UserUpload context.
 *
 * Take the implementation from the service locator by this interface on every call.
 * A refusal is told by its code alone: compare it with ERROR_FORMAT_RESTRICTED.
 */
interface UploadFormatPolicyInterface
{
	/**
	 * Code of the refusal.
	 */
	public const ERROR_FORMAT_RESTRICTED = 'DISK_FILE_22013';

	/**
	 * Length of the beginning of the content the policy looks at, in bytes.
	 */
	public const CONTENT_HEAD_LENGTH = 512;

	/**
	 * Returns the refusal of the format policy for the upload, or null when the upload is allowed.
	 *
	 * @param string $fileName Name the file is uploaded under.
	 * @param int $actorId User performing the upload, whose policy judges it; zero or less gets the global rule.
	 * @param string|null $contentHead Bytes the content begins with; null or an empty string leaves the verdict
	 *        to the name. A longer string is accepted.
	 * @return Error|null
	 */
	public function findUploadRefusal(string $fileName, int $actorId, ?string $contentHead = null): ?Error;
}
