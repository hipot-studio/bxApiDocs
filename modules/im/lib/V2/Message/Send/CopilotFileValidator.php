<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Send;

use Bitrix\Im\V2\Chat\Copilot\FileUploadPolicy;
use Bitrix\Im\V2\Entity\File\FileItem;
use Bitrix\Im\V2\Integration\AI\CopilotError;
use Bitrix\Im\V2\Result;
use Bitrix\Main\Loader;

final class CopilotFileValidator
{
	private array $policy;

	public function __construct(?array $policy = null)
	{
		$this->policy = $policy ?? FileUploadPolicy::toPublicData();
	}

	/**
	 * @param int[] $diskFileIds
	 */
	public function validate(array $diskFileIds): Result
	{
		$countCheck = $this->validateCount(count($diskFileIds));
		if (!$countCheck->isSuccess())
		{
			return $countCheck;
		}

		if (empty($diskFileIds))
		{
			return new Result();
		}

		if (!Loader::includeModule('disk'))
		{
			return (new Result())->addError(new CopilotError(CopilotError::FILE_METADATA_UNAVAILABLE));
		}

		foreach ($diskFileIds as $diskFileId)
		{
			$fileCheckResult = $this->validateSingleFile((int)$diskFileId);
			if (!$fileCheckResult->isSuccess())
			{
				return $fileCheckResult;
			}
		}

		return new Result();
	}

	public function validateCount(int $filesCount): Result
	{
		$result = new Result();

		$limit = (int)$this->policy['maxFilesPerMessage'];
		if ($filesCount > $limit)
		{
			return $result->addError(new CopilotError(
				CopilotError::TOO_MANY_FILES,
				['COUNT' => $limit],
			));
		}

		return $result;
	}

	public function validateFileMetadata(string $fileName, int $size): Result
	{
		$result = new Result();

		$extension = self::extractExtension($fileName);
		if ($extension === '' || !in_array($extension, $this->policy['allowedExtensions'], true))
		{
			return $result->addError(new CopilotError(CopilotError::INVALID_FILE_FORMAT));
		}

		$maxSize = (int)$this->policy['maxFileSize'];
		if ($size > $maxSize)
		{
			$sizeMb = max(1, (int)round($maxSize / (1024 * 1024)));

			return $result->addError(new CopilotError(
				CopilotError::FILE_TOO_LARGE,
				['SIZE_MB' => $sizeMb],
			));
		}

		return $result;
	}

	private function validateSingleFile(int $diskFileId): Result
	{
		$result = new Result();

		$fileItem = FileItem::initByDiskFileId($diskFileId);
		if ($fileItem === null)
		{
			return $result->addError(new CopilotError(CopilotError::FILE_METADATA_UNAVAILABLE));
		}

		$diskFile = $fileItem->getDiskFile();
		if ($diskFile === null)
		{
			return $result->addError(new CopilotError(CopilotError::FILE_METADATA_UNAVAILABLE));
		}

		return $this->validateFileMetadata((string)$diskFile->getName(), (int)$diskFile->getSize());
	}

	private static function extractExtension(string $fileName): string
	{
		$dotPosition = mb_strrpos($fileName, '.');
		if ($dotPosition === false || $dotPosition === mb_strlen($fileName) - 1)
		{
			return '';
		}

		return mb_strtolower(mb_substr($fileName, $dotPosition + 1));
	}
}
