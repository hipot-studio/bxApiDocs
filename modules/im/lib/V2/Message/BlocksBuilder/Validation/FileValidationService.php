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
	protected const MAX_FILE_BLOCK_SIZE = 10;

	public function validate(array $blocks, Chat $chat): Result
	{
		$result = $this->checkBlocksCount($blocks);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$files = $this->loadBlockFiles($blocks);

		foreach ($blocks as $key => $blockData)
		{
			if (!is_array($blockData))
			{
				continue;
			}

			$blockResult = $this->validateBlockFiles($blockData, $chat, $files);
			if (!$blockResult->isSuccess())
			{
				return $blockResult;
			}

			$blocks[$key] = $blockResult->getResult();
		}

		return $result->setResult($blocks);
	}

	/**
	 * Every file block asks the same disk table, so the files of all blocks are read once and the
	 * per-block checks below pick from the result. The models are handed to the file layer as well:
	 * the payload text built right after the validation resolves the very same files.
	 */
	protected function loadBlockFiles(array $blocks): FileCollection
	{
		$fileIds = [];

		foreach ($blocks as $blockData)
		{
			if (!is_array($blockData))
			{
				continue;
			}

			foreach ($this->getBlockFileIds($blockData) as $fileId)
			{
				$fileIds[$fileId] = $fileId;
			}
		}

		$files = FileCollection::initByDiskFilesIds($fileIds);
		FileCollection::addDiskFilesToPreload($files->getDiskFiles());

		return $files;
	}

	/**
	 * The limit of the block is applied here, not after the read: an oversized list of ids must not
	 * reach the query.
	 *
	 * @return int[]
	 */
	protected function getBlockFileIds(array $blockData): array
	{
		$type = $blockData[Field::Type->value] ?? '';

		if ($type === BlockType::Audio->value)
		{
			$fileId = $blockData[Field::FileId->value] ?? null;

			return is_numeric($fileId) ? [(int)$fileId] : [];
		}

		$blockType = is_string($type) ? BlockType::tryFrom($type) : null;
		$maxCount = $this->getBlockFileLimit($blockType);

		$fileIds = $blockData[Field::FileIds->value] ?? [];
		if ($maxCount === null || !is_array($fileIds))
		{
			return [];
		}

		return array_map('intval', array_filter(array_slice($fileIds, 0, $maxCount), 'is_numeric'));
	}

	/**
	 * Both the read of the files of a block and their validation cut the list here: a file beyond the
	 * read is dropped by the validation without an error.
	 */
	protected function getBlockFileLimit(?BlockType $blockType): ?int
	{
		return match ($blockType)
		{
			BlockType::Gallery => self::MAX_GALLERY_SIZE,
			BlockType::File => self::MAX_FILE_BLOCK_SIZE,
			default => null,
		};
	}

	protected function validateBlockFiles(array $blockData, Chat $chat, FileCollection $files): Result
	{
		return match ($blockData[Field::Type->value] ?? '')
		{
			BlockType::Gallery->value => $this->validateFileIdsBlock(
				$blockData,
				$chat,
				BlockType::Gallery,
				BuilderError::GALLERY_FILES_NOT_AVAILABLE,
				$files
			),
			BlockType::File->value => $this->validateFileIdsBlock(
				$blockData,
				$chat,
				BlockType::File,
				BuilderError::FILE_FILES_NOT_AVAILABLE,
				$files
			),
			BlockType::Audio->value => $this->validateAudioBlock($blockData, $chat, $files),
			default => (new Result())->setResult($blockData),
		};
	}

	/**
	 * The error code comes from the caller, so the type of a block and the error it answers with are
	 * named in the same place: a new block type added to the routing above cannot forget its code.
	 */
	protected function validateFileIdsBlock(
		array $blockData,
		Chat $chat,
		BlockType $blockType,
		string $errorCode,
		FileCollection $files
	): Result
	{
		$result = new Result();

		$fileIds = $blockData[Field::FileIds->value] ?? [];
		if (!is_array($fileIds) || empty($fileIds))
		{
			return $result->setResult($blockData);
		}

		$maxCount = $this->getBlockFileLimit($blockType);

		$availableFileIds = $this->getAvailableFileIds(
			array_slice($fileIds, 0, $maxCount),
			$chat,
			$blockType,
			$files
		);
		if (empty($availableFileIds))
		{
			return $result->addError(new BuilderError($errorCode));
		}

		$blockData[Field::FileIds->value] = $availableFileIds;

		return $result->setResult($blockData);
	}

	protected function validateAudioBlock(array $blockData, Chat $chat, FileCollection $files): Result
	{
		$result = new Result();

		// malformed id is rejected later by the field validator
		$fileId = $blockData[Field::FileId->value] ?? null;
		if (!is_numeric($fileId))
		{
			return $result->setResult($blockData);
		}

		$availableFileIds = $this->getAvailableFileIds([$fileId], $chat, BlockType::Audio, $files);
		if (empty($availableFileIds))
		{
			return $result->addError(new BuilderError(BuilderError::AUDIO_FILE_NOT_AVAILABLE));
		}

		$blockData[Field::FileId->value] = $availableFileIds[0];

		return $result->setResult($blockData);
	}

	/**
	 * The read files of the whole message are given, so only the ones of this block are taken: type
	 * and access of a file are checked against the block that asked for it. The walk goes over the
	 * asked ids and not over the collection, because the order of the ids of a block is the order it
	 * is rendered in and the order its file names go into the message text, while the order of the
	 * collection follows the read and the models already warmed up in the preload.
	 *
	 * @return int[]
	 */
	protected function getAvailableFileIds(
		array $fileIds,
		Chat $chat,
		BlockType $blockType,
		FileCollection $files
	): array
	{
		$availableFileIds = [];

		foreach (array_map('intval', array_filter($fileIds, 'is_numeric')) as $fileId)
		{
			$file = $files->getById($fileId);
			if (!$file instanceof FileItem)
			{
				continue;
			}

			if ($this->isAllowedFileType($file, $blockType) && $this->checkDiskFileAccess($file, $chat))
			{
				$availableFileIds[$fileId] = $fileId;
			}
		}

		return array_values($availableFileIds);
	}

	protected function isAllowedFileType(FileItem $file, BlockType $blockType): bool
	{
		return match ($blockType)
		{
			BlockType::Gallery => $file->isGalleryType(),
			BlockType::Audio => $file->isAudioType(),
			default => true, // the file block accepts any content type
		};
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

	protected function checkBlocksCount(array $blocks): Result
	{
		$result = new Result();

		$counters = [];
		foreach ($blocks as $blockData)
		{
			$type = is_array($blockData) ? ($blockData[Field::Type->value] ?? null) : null;
			if (is_string($type))
			{
				$counters[$type] = ($counters[$type] ?? 0) + 1;
			}
		}

		foreach ($this->getSingleBlockTypeErrors() as $type => $errorCode)
		{
			if (($counters[$type] ?? 0) > 1)
			{
				return $result->addError(new BuilderError($errorCode));
			}
		}

		return $result;
	}

	/**
	 * Block types allowed only once per message.
	 *
	 * @return array<string, string>
	 */
	protected function getSingleBlockTypeErrors(): array
	{
		return [
			BlockType::Gallery->value => BuilderError::GALLERY_ELEMENTS_EXCEEDED,
			BlockType::File->value => BuilderError::FILE_ELEMENTS_EXCEEDED,
			BlockType::Audio->value => BuilderError::AUDIO_ELEMENTS_EXCEEDED,
		];
	}
}
