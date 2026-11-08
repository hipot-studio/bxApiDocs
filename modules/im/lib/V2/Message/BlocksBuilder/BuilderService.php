<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder;

use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Entity\File\FileCollection;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\BlocksBuilder;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\BlockType;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Field;
use Bitrix\Im\V2\Message\BlocksBuilder\Factory\BuilderFactory;
use Bitrix\Im\V2\Message\BlocksBuilder\Validation\ValidationService;
use Bitrix\Main\FileTable;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

class BuilderService
{
	public const MAX_JSON_LENGTH = 60000;

	public function __construct(
		protected readonly BuilderFactory $builderFactory,
		protected readonly ValidationService $validationService,
	)
	{}

	public function create(array $builderData, Chat $chat, ?BlocksBuilder $previousBuilder = null): BuilderResult
	{
		$builderResult = new BuilderResult();

		$builderData = $this->keepServiceFlags($builderData, $previousBuilder);
		$blockTypes = $this->getBlockTypes($builderData);

		if (in_array(BlockType::Form->value, $blockTypes, true) && !Features::isMessageFormBlockAvailable())
		{
			return $builderResult->addError(new BuilderError(BuilderError::FORM_BLOCK_NOT_AVAILABLE));
		}

		if ($this->isFileAudioBlockRestricted($blockTypes, $previousBuilder))
		{
			return $builderResult->addError(new BuilderError(BuilderError::FILE_AUDIO_BLOCK_NOT_AVAILABLE));
		}

		$result = $this->validationService->validateNew($builderData, $chat);
		if (!$result->isSuccess())
		{
			return $builderResult->addError($result->getError());
		}

		$builder = $this->builderFactory->create($result->getResult());
		if (mb_strlen($builder->getJson()) >= self::MAX_JSON_LENGTH)
		{
			return $builderResult->addError(new BuilderError(BuilderError::BLOCK_LENGTH_EXCEEDED));
		}

		$this->markBlockFilesReady($builder);

		return $builderResult->setBlocksBuilder($builder);
	}

	public function get(array $builderData): BuilderResult
	{
		$builderResult = new BuilderResult();

		$result = $this->validationService->validateExisting($builderData);
		if (!$result->isSuccess())
		{
			return $builderResult->addError($result->getError());
		}

		$builder = $this->builderFactory->create($result->getResult());
		if (mb_strlen($builder->getJson()) >= self::MAX_JSON_LENGTH)
		{
			return $builderResult->addError(new BuilderError(BuilderError::BLOCK_LENGTH_EXCEEDED));
		}

		return $builderResult->setBlocksBuilder($builder);
	}

	/**
	 * A result without a builder means every block was dropped as an orphan: there is no payload
	 * left to carry, and it is up to the caller to decide what the message without it looks like.
	 *
	 * @param array<int, int> $copiedFileIds original file id => id of its copy for the target chat
	 * @param int[] $missingFileIds ids of the files the source does not resolve any more
	 */
	public function updateFileIds(
		BlocksBuilder $builder,
		Chat $chat,
		array $copiedFileIds,
		array $missingFileIds
	): BuilderResult
	{
		$builderData = $this->replaceFileIdsWithCopies(
			$builder->toArray(),
			$copiedFileIds,
			$missingFileIds
		);

		if (empty($builderData[Field::Elements->value]))
		{
			return new BuilderResult();
		}

		return $this->create($builderData, $chat, $builder);
	}

	public static function getPlaceholder(): string
	{
		return Loc::getMessage('IM_MESSAGE_BUILDER_PLACEHOLDER') ?? '';
	}

	/**
	 * The chat calls a file ready by raising its content version, the way \CIMDisk::UploadFileFromMain
	 * does: the web upload creates the file first and pours the content in a second step. A producer of
	 * a block puts its file on the disk in a single call and has no reason to know about that protocol,
	 * so its file would stay "uploading" forever: FileItem::toRestFormat() reports status "upload" and
	 * progress -1 while the version is 1, and on that the clients keep the progress indicator up and
	 * refuse to download. A file that already carries its content is taken as ready here; one still
	 * waiting for the content keeps the uploading state, so a real upload in progress is still shown
	 * as such.
	 */
	private function markBlockFilesReady(BlocksBuilder $builder): void
	{
		$fileIds = $builder->getFiles();
		if (empty($fileIds) || !Loader::includeModule('disk'))
		{
			return;
		}

		// The validation right before this has read the very same files and put them into the preload,
		// so taking them by the same path adds no query of its own.
		$pendingFiles = [];
		foreach (FileCollection::initByDiskFilesIds($fileIds)->getDiskFiles() as $diskFile)
		{
			$contentId = (int)$diskFile->getFileId();
			if ($contentId <= 0 || $diskFile->getGlobalContentVersion() > 1)
			{
				continue;
			}

			$realFile = $diskFile->getRealObject();
			$pendingFiles[$contentId][$realFile->getId()] = $realFile;
		}

		if (empty($pendingFiles))
		{
			return;
		}

		$filledContent = FileTable::getList([
			'select' => ['ID'],
			'filter' => ['@ID' => array_keys($pendingFiles), '>FILE_SIZE' => 0],
		]);

		foreach ($filledContent as $content)
		{
			foreach ($pendingFiles[(int)$content['ID']] as $diskFile)
			{
				$diskFile->increaseGlobalContentVersion();
			}
		}
	}

	/**
	 * A payload replaces the blocks it names as a whole, while the service flag is free to be dropped on
	 * the way: clients treat it as an unknown field. Losing it silently would let the text of a service
	 * block back into the message text, the recent preview and the search index, so the stored flag
	 * survives a payload that says nothing about it. A flag named by the payload wins, which is how a
	 * block is turned back into a regular one, and a block the previous builder does not know is service
	 * only if its own payload says so.
	 */
	private function keepServiceFlags(array $builderData, ?BlocksBuilder $previousBuilder): array
	{
		if ($previousBuilder === null || !is_array($builderData[Field::Elements->value] ?? null))
		{
			return $builderData;
		}

		$serviceIds = [];
		foreach ($previousBuilder->blockCollection as $block)
		{
			if ($block->isService())
			{
				$serviceIds[$block->getId()] = true;
			}
		}

		foreach ($builderData[Field::Elements->value] as $key => $block)
		{
			$blockId = is_array($block) ? ($block[Field::Id->value] ?? null) : null;
			if (!is_string($blockId) && !is_int($blockId))
			{
				continue;
			}

			$blockId = (string)$blockId;
			if (!isset($serviceIds[$blockId]))
			{
				continue;
			}

			// a repeated id is kept by the first block alone, so the flag is handed over once as well
			unset($serviceIds[$blockId]);

			if (($block[Field::IsService->value] ?? null) === null)
			{
				$builderData[Field::Elements->value][$key][Field::IsService->value] = true;
			}
		}

		return $builderData;
	}

	/**
	 * Every block keeps the very files it was built with: an id is replaced with the id of its own
	 * copy. An id the source does not resolve any more is dropped instead, and a block left without
	 * files is dropped with them: there is nothing to make a copy from, and the rest of the message
	 * has no reason to stay behind. A file that is still there but did not get its copy keeps the
	 * original id and is rejected on validation, so a copy never lands in a block that did not ask
	 * for it and a failed copying is not passed over in silence.
	 *
	 * @param array<int, int> $copiedFileIds
	 * @param int[] $missingFileIds
	 */
	private function replaceFileIdsWithCopies(
		array $builderData,
		array $copiedFileIds,
		array $missingFileIds
	): array
	{
		$missingFileIds = array_fill_keys(array_map('intval', $missingFileIds), true);

		foreach ($builderData[Field::Elements->value] as $key => $element)
		{
			$type = $element[Field::Type->value] ?? null;

			if ($type === BlockType::Audio->value && isset($element[Field::FileId->value]))
			{
				if ($this->isFileMissing($element[Field::FileId->value], $copiedFileIds, $missingFileIds))
				{
					unset($builderData[Field::Elements->value][$key]);

					continue;
				}

				$builderData[Field::Elements->value][$key][Field::FileId->value] = $this->replaceFileId(
					$element[Field::FileId->value],
					$copiedFileIds
				);

				continue;
			}

			$isFileIdsBlock = $type === BlockType::Gallery->value || $type === BlockType::File->value;
			$fileIds = $element[Field::FileIds->value] ?? null;
			if (!$isFileIdsBlock || !is_array($fileIds) || empty($fileIds))
			{
				continue;
			}

			$copies = [];
			foreach ($fileIds as $fileId)
			{
				if ($this->isFileMissing($fileId, $copiedFileIds, $missingFileIds))
				{
					continue;
				}

				$copies[] = $this->replaceFileId($fileId, $copiedFileIds);
			}

			if (empty($copies))
			{
				unset($builderData[Field::Elements->value][$key]);

				continue;
			}

			$builderData[Field::Elements->value][$key][Field::FileIds->value] = $copies;
		}

		$builderData[Field::Elements->value] = array_values($builderData[Field::Elements->value]);

		return $builderData;
	}

	/**
	 * @param array<int, int> $copiedFileIds
	 * @param array<int, true> $missingFileIds
	 */
	private function isFileMissing(mixed $fileId, array $copiedFileIds, array $missingFileIds): bool
	{
		if (!is_numeric($fileId))
		{
			return false;
		}

		$fileId = (int)$fileId;

		return !isset($copiedFileIds[$fileId]) && isset($missingFileIds[$fileId]);
	}

	/**
	 * @param array<int, int> $copiedFileIds
	 */
	private function replaceFileId(mixed $fileId, array $copiedFileIds): mixed
	{
		if (!is_numeric($fileId))
		{
			return $fileId;
		}

		return $copiedFileIds[(int)$fileId] ?? $fileId;
	}

	/**
	 * @param string[] $blockTypes
	 */
	private function isFileAudioBlockRestricted(array $blockTypes, ?BlocksBuilder $previousBuilder): bool
	{
		$newTypes = array_intersect($blockTypes, $this->getFileAudioBlockTypes());
		if (empty($newTypes))
		{
			return false;
		}

		if (Features::isMessageFileAudioBlockAvailable())
		{
			return false;
		}

		$previousTypes = $previousBuilder === null
			? []
			: array_intersect($this->getBlockTypes($previousBuilder->toArray()), $this->getFileAudioBlockTypes())
		;

		return !empty(array_diff($newTypes, $previousTypes));
	}

	/**
	 * @return string[]
	 */
	private function getFileAudioBlockTypes(): array
	{
		return [BlockType::File->value, BlockType::Audio->value];
	}

	/**
	 * @return string[]
	 */
	private function getBlockTypes(array $builderData): array
	{
		$elements = $builderData[Field::Elements->value] ?? null;
		if (!is_array($elements))
		{
			return [];
		}

		$types = [];
		foreach ($elements as $block)
		{
			$type = is_array($block) ? ($block[Field::Type->value] ?? null) : null;
			if (is_string($type))
			{
				$types[$type] = $type;
			}
		}

		return $types;
	}
}
