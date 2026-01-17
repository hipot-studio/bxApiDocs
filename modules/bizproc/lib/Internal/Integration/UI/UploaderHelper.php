<?php

namespace Bitrix\Bizproc\Internal\Integration\UI;

use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Main\Result;
use Bitrix\UI\FileUploader\PendingFileCollection;

class UploaderHelper
{
	/**
	 * @param array $fileIds
	 *
	 * @return array{0: array<string>, 1: array<int>} [tempFileIds, persistentFileIds]
	 */
	public static function splitFiles(array $fileIds): array
	{
		$tempFileIds = [];
		$persistentFileIds = [];
		foreach ($fileIds as $fileId)
		{
			if (is_numeric($fileId) && $fileId > 0)
			{
				$persistentFileIds[] = (int)$fileId;
			}
			elseif (is_string($fileId) && $fileId !== '')
			{
				$tempFileIds[] = $fileId;
			}
		}

		return [$tempFileIds, $persistentFileIds];
	}

	public static function validatePendingFiles(PendingFileCollection $pendingFiles, array $tempFileIds): Result
	{
		$result = new Result();
		foreach ($tempFileIds as $tempFileId)
		{
			$pendingFile = $pendingFiles->get((string)$tempFileId);
			if ($pendingFile === null)
			{
				$result->addError(ErrorMessage::INVALID_FILE->getError());
			}
			elseif (!$pendingFile->isValid())
			{
				$result->addErrors($pendingFile->getErrors());
			}
			elseif ($pendingFile->getFileId() === null)
			{
				$result->addError(ErrorMessage::INVALID_FILE->getError());
			}
		}

		return $result;
	}
}