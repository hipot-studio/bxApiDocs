<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Validation;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Entity\File\FileCollection;
use Bitrix\Im\V2\Entity\File\FileItem;
use Bitrix\Im\V2\Message\BlocksBuilder\BuilderError;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\BlockType;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Field;
use Bitrix\Im\V2\Result;

class FileValidationService
{
	protected const MAX_GALLERY_SIZE = 10;

	public function validate(array $blocks, Chat $chat): Result
	{
		$result = $this->checkGalleryBlocksCount($blocks);
		if (!$result->isSuccess())
		{
			return $result;
		}

		foreach ($blocks as $key => $blockData)
		{
			if (($blockData[Field::Type->value] ?? '') !== BlockType::Gallery->value)
			{
				continue;
			}

			$fileIds = $blockData[Field::FileIds->value] ?? [];
			if (!is_array($fileIds) || empty($fileIds))
			{
				return $result->setResult($blocks);
			}

			$fileIds = $this->validateFileIds($fileIds, $chat);
			if (empty($fileIds))
			{
				return $result->addError(new BuilderError(BuilderError::GALLERY_FILES_NOT_AVAILABLE));
			}

			$blocks[$key][Field::FileIds->value] = $fileIds;
		}

		return $result->setResult($blocks);
	}

	protected function validateFileIds(array $fileIds, Chat $chat): array
	{
		$fileIds = array_slice($fileIds, 0, self::MAX_GALLERY_SIZE);

		foreach ($fileIds as $key => $fileId)
		{
			if (!is_numeric($fileId))
			{
				unset($fileIds[$key]);
			}
		}

		$fileIds = array_map('intval', $fileIds);

		$result = [];
		$files = FileCollection::initByDiskFilesIds($fileIds);
		foreach ($files as $file)
		{
			if ($file->isGalleryType() && $this->checkDiskFileAccess($file, $chat))
			{
				$result[] = $file->getId();
			}
		}

		return $result;
	}

	protected function checkDiskFileAccess(FileItem $file, Chat $chat): bool
	{
		if ($chat->getDiskFolderId() === null)
		{
			return false;
		}

		if ((int)$file->getDiskFile()?->getParentId() === $chat->getDiskFolderId())
		{
			return true;
		}

		return false;
	}

	protected function checkGalleryBlocksCount(array $blocks): Result
	{
		$result = new Result();

		$galleryCount = 0;
		foreach ($blocks as $blockData)
		{
			if (($blockData[Field::Type->value] ?? '') === BlockType::Gallery->value)
			{
				$galleryCount++;
				if ($galleryCount > 1)
				{
					return $result->addError((new BuilderError(BuilderError::GALLERY_ELEMENTS_EXCEEDED)));
				}
			}
		}

		return $result;
	}
}
