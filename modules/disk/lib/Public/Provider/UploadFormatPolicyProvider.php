<?php
declare(strict_types=1);

namespace Bitrix\Disk\Public\Provider;

use Bitrix\Disk\File;
use Bitrix\Disk\Public\Contract\FormatPolicy\UploadFormatPolicyInterface;
use Bitrix\Main\Error;

/**
 * Asks the write path of Disk for the verdict on an upload, so the policy is judged in one place.
 */
final class UploadFormatPolicyProvider implements UploadFormatPolicyInterface
{
	/**
	 * @inheritDoc
	 */
	public function findUploadRefusal(string $fileName, int $actorId, ?string $contentHead = null): ?Error
	{
		$fileArray = ($contentHead === null || $contentHead === '') ? [] : ['content' => $contentHead];
		// the mark the write path leaves in the options is of no use outside a write
		$options = [];

		$error = File::findFormatPolicyErrorForUpload($fileArray, $fileName, $actorId, $options);
		if ($error === null)
		{
			return null;
		}

		return new Error((string)$error->getMessage(), self::ERROR_FORMAT_RESTRICTED);
	}
}
