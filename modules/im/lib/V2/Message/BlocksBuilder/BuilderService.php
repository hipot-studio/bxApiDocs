<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder;

use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Entity\File\FileCollection;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\AbstractBlock;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Buttons\Type;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Footer\FooterElement;
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

	public function create(
		array $builderData,
		Chat $chat,
		?BlocksBuilder $previousBuilder = null,
		?bool $newFooterSent = null
	): BuilderResult
	{
		$builderResult = new BuilderResult();

		// The footer switch looks at what the caller adds or changes, before a footer of the previous
		// builder is pulled in: an update that says nothing about the footer, and an operation over a
		// message that already carries one, must not be refused because of it. A caller that reshapes
		// the footer on its own way in answers the question itself - what reaches here is no longer
		// what the caller sent.
		$newFooterSent ??= $this->hasNewFooter($builderData, $previousBuilder);

		$builderData = $this->keepSurvivingFields($builderData, $previousBuilder);
		$blockTypes = $this->getBlockTypes($builderData);

		if ($newFooterSent && !Features::isMessageBlockFooterAvailable())
		{
			return $builderResult->addError(new BuilderError(BuilderError::FOOTER_NOT_AVAILABLE));
		}

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
	 * Blocks of a forwarded copy: the footer buttons that only make sense in the source chat are taken
	 * off and the files are pointed at their copies. A footer button that asks the server for something
	 * belongs to the message it was sent with - the action is bound to that very answer, and pressing it
	 * from a copy in another chat means nothing; a link button is an address and travels as it is.
	 *
	 * Both are prepared over the same data and the builder is rebuilt once, because the checks of
	 * create() run against the chat the copy goes to and must see the copy as it will be sent: a
	 * rebuild done before the ids are replaced still holds the files of the source chat and is
	 * rejected by their availability check.
	 *
	 * A builder the forwarding changes nothing in is handed back untouched: rebuilding brings along
	 * the checks of create(), and a copy that changes nothing must not start failing on them. A builder
	 * that carries files is rebuilt in any case - a file that is still there but did not get its copy
	 * keeps the id of the source chat and is rejected, so a failed copying is not passed over in
	 * silence. A result without a builder means every block was dropped as an orphan: there is no
	 * payload left to carry, and it is up to the caller to decide what the message without it looks like.
	 *
	 * @param array<int, int> $copiedFileIds original file id => id of its copy for the target chat
	 * @param int[] $missingFileIds ids of the files the source does not resolve any more
	 */
	public function prepareForwardedCopy(
		BlocksBuilder $builder,
		Chat $chat,
		array $copiedFileIds,
		array $missingFileIds
	): BuilderResult
	{
		$builderData = $builder->toArray();

		// Asked of the data as it came, not of what survives our own trimming: forwarding only takes
		// elements away, so a trimmed footer always differs from the stored one. Judged after the trim,
		// every forward of a mixed footer would read as a footer the caller just sent - and a switched
		// off footer would refuse to forward the whole message rather than the footer alone.
		$newFooterSent = $this->hasNewFooter($builderData, $builder);

		$preparedData = $this->replaceFileIdsWithCopies(
			$this->dropFooterActions($builderData),
			$copiedFileIds,
			$missingFileIds
		);

		if ($preparedData === $builderData && empty($builder->getFiles()))
		{
			return (new BuilderResult())->setBlocksBuilder($builder);
		}

		if (empty($preparedData[Field::Elements->value]))
		{
			return new BuilderResult();
		}

		return $this->create($preparedData, $chat, $builder, $newFooterSent);
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
	 * A payload replaces the blocks it names as a whole, while some fields are free to be dropped on
	 * the way: clients treat them as unknown. Losing the service flag silently would let the text of a
	 * service block back into the message text, the recent preview and the search index; losing the
	 * footer would take the buttons off a block whose author only meant to refresh its text. So both
	 * survive a payload that says nothing about them. A field named by the payload wins, which is how
	 * a block is turned back into a regular one and how a footer is taken off - by an empty list of
	 * rows. The footer is not carried over a change of block type: the same id may come back as a
	 * block of another type, where the very same footer can be forbidden.
	 */
	private function keepSurvivingFields(array $builderData, ?BlocksBuilder $previousBuilder): array
	{
		if ($previousBuilder === null || !is_array($builderData[Field::Elements->value] ?? null))
		{
			return $builderData;
		}

		// a repeated id is kept by the first block alone, so its fields are handed over once as well
		$previousBlocks = $this->getPreviousBlocksById($previousBuilder);

		foreach ($builderData[Field::Elements->value] as $key => $block)
		{
			$blockId = is_array($block) ? ($block[Field::Id->value] ?? null) : null;
			if (!is_string($blockId) && !is_int($blockId))
			{
				continue;
			}

			$previousBlock = $previousBlocks[(string)$blockId] ?? null;
			if ($previousBlock === null)
			{
				continue;
			}

			unset($previousBlocks[(string)$blockId]);

			if ($previousBlock->isService() && ($block[Field::IsService->value] ?? null) === null)
			{
				$builderData[Field::Elements->value][$key][Field::IsService->value] = true;
			}

			if (($block[Field::Footer->value] ?? null) === null && $this->keepsBlockType($block, $previousBlock))
			{
				$footer = $previousBlock->getFooter()->toArray();
				if ($footer !== null)
				{
					$builderData[Field::Elements->value][$key][Field::Footer->value] = $footer;

					// The gap comes along with the footer it spaces, and by the same rule: a step named by
					// the payload wins, so only a payload silent about the step takes the previous one.
					// Overwriting it here would leave the step unchangeable without resending the whole
					// footer, and would hand validation a bad step already swapped for a good one -
					// nothing is validated yet when this runs.
					$spacing = $previousBlock->getFooterSpacing();
					if ($spacing !== null && ($block[Field::FooterSpacing->value] ?? null) === null)
					{
						$builderData[Field::Elements->value][$key][Field::FooterSpacing->value] = $spacing;
					}
				}
			}
		}

		return $builderData;
	}

	/**
	 * An empty list of rows is a removal of the footer, not a footer: it carries nothing to gate. A
	 * footer equal to the stored one is the same footer going through a rebuild - forwarding, a file
	 * id replacement or an operation over a neighbouring block - so only a footer the caller adds or
	 * changes counts as new.
	 */
	private function hasNewFooter(array $builderData, ?BlocksBuilder $previousBuilder): bool
	{
		$elements = $builderData[Field::Elements->value] ?? null;
		if (!is_array($elements))
		{
			return false;
		}

		$previousBlocks = $this->getPreviousBlocksById($previousBuilder);

		foreach ($elements as $block)
		{
			$footer = is_array($block) ? ($block[Field::Footer->value] ?? null) : null;
			if ($footer === null || $footer === [])
			{
				continue;
			}

			$blockId = $block[Field::Id->value] ?? null;
			$previousBlock = (is_string($blockId) || is_int($blockId))
				? ($previousBlocks[(string)$blockId] ?? null)
				: null
			;

			if ($previousBlock === null || !$this->keepsBlockType($block, $previousBlock))
			{
				return true;
			}

			if ($this->sortKeysDeep($footer) !== $this->sortKeysDeep($previousBlock->getFooter()->toArray()))
			{
				return true;
			}
		}

		return false;
	}

	/** The same data in another order of keys is the same footer: both sides are ordered before comparing. */
	private function sortKeysDeep(mixed $value): mixed
	{
		if (!is_array($value))
		{
			return $value;
		}

		$value = array_map(fn (mixed $item): mixed => $this->sortKeysDeep($item), $value);
		ksort($value);

		return $value;
	}

	/**
	 * @return array<string, AbstractBlock> a repeated id is kept by the first block alone
	 */
	private function getPreviousBlocksById(?BlocksBuilder $previousBuilder): array
	{
		$previousBlocks = [];
		foreach ($previousBuilder?->blockCollection ?? [] as $block)
		{
			$previousBlocks[$block->getId()] ??= $block;
		}

		return $previousBlocks;
	}

	private function keepsBlockType(array $block, AbstractBlock $previousBlock): bool
	{
		return ($block[Field::Type->value] ?? null) === $previousBlock::getType()->value;
	}

	/**
	 * A row left without elements goes away with them, while a footer left without rows stays as an
	 * empty list on purpose: a missing footer is taken by keepSurvivingFields() as "keep the previous
	 * one", and the removal would undo itself.
	 *
	 * A link button addressed to certain users goes away together with the action buttons: the copy
	 * lands in a chat picked by whoever forwards the message, and the list it was addressed to says
	 * nothing about who reads it there.
	 */
	private function dropFooterActions(array $builderData): array
	{
		foreach (($builderData[Field::Elements->value] ?? []) as $key => $block)
		{
			$footer = is_array($block) ? ($block[Field::Footer->value] ?? null) : null;
			if (!is_array($footer))
			{
				continue;
			}

			$rows = [];
			foreach ($footer as $row)
			{
				$linkButtons = array_values(array_filter(
					is_array($row) ? $row : [],
					static fn($element) => is_array($element)
						&& ($element['type'] ?? null) === Type::LinkButton->value
						&& !FooterElement::isAddressedToSomeone($element)
				));

				if (!empty($linkButtons))
				{
					$rows[] = $linkButtons;
				}
			}

			$builderData[Field::Elements->value][$key][Field::Footer->value] = $rows;
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
