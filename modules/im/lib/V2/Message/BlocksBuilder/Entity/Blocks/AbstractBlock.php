<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks;

use Bitrix\Im\V2\Entity\File\FileCollection;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\BlockType;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Field;

abstract class AbstractBlock implements \JsonSerializable
{
	protected string $id;
	protected bool $isService;

	private ?array $fileNames = null;

	public function __construct(array $blockData)
	{
		$this->id = (string)$blockData[Field::Id->value];
		$this->isService = (bool)($blockData[Field::IsService->value] ?? false);
	}

	abstract public static function create(array $blockData): AbstractBlock;

	abstract public function getPayloadText(): ?string;

	abstract public static function getType(): BlockType;

	abstract public static function getRequiredFields(): array;

	abstract public function toArray(): array;

	public function getId(): string
	{
		return $this->id;
	}

	public function getFiles(): array
	{
		return [];
	}

	/**
	 * A service block is rendered by clients, but its text is kept out of the message payload text.
	 */
	public function isService(): bool
	{
		return $this->isService;
	}

	protected function getFileNamesText(): ?string
	{
		$this->fileNames ??= $this->loadFileNames();

		if (empty($this->fileNames))
		{
			return null;
		}

		return implode(PHP_EOL, $this->fileNames);
	}

	/**
	 * Takes names resolved for many blocks at once. A block whose files got no name is filled too:
	 * the empty list is what keeps the lazy read below from happening.
	 *
	 * @param array<int, string> $namesById
	 */
	public function fillFileNames(array $namesById): void
	{
		$this->fileNames = $this->orderFileNames($namesById);
	}

	/**
	 * @param int[] $fileIds
	 * @return array<int, string> disk names already neutralized, so no caller can hand out a raw one
	 */
	public static function readFileNames(array $fileIds): array
	{
		$namesById = [];

		foreach (FileCollection::initByDiskFilesIds($fileIds) as $file)
		{
			$name = $file->getDiskFile()?->getName();
			if (!empty($name))
			{
				$namesById[$file->getId()] = self::neutralizeBbCode($name);
			}
		}

		return $namesById;
	}

	/**
	 * Names are resolved once and kept: payload text is rebuilt on every block update.
	 *
	 * @return string[]
	 */
	private function loadFileNames(): array
	{
		$fileIds = $this->getFiles();
		if (empty($fileIds))
		{
			return [];
		}

		return $this->orderFileNames(self::readFileNames($fileIds));
	}

	/**
	 * Every path to the kept names goes through here, so whoever supplies them cannot bypass the
	 * neutralization below.
	 *
	 * @param array<int, string> $namesById
	 * @return string[]
	 */
	private function orderFileNames(array $namesById): array
	{
		$names = [];

		foreach ($this->getFiles() as $fileId)
		{
			if (isset($namesById[$fileId]))
			{
				$names[] = self::neutralizeBbCode($namesById[$fileId]);
			}
		}

		return $names;
	}

	/**
	 * The message text is a BB-code channel, while a file name is chosen by whoever uploaded the file
	 * and may contain brackets. They are replaced instead of entity-encoded, because Text::parse()
	 * decodes &#91; back to '[' before the text reaches clients. Replacing again changes nothing, so the
	 * read and the ordering both neutralize without having to agree on which of them does it.
	 */
	private static function neutralizeBbCode(string $name): string
	{
		return str_replace(['[', ']'], ['(', ')'], $name);
	}
}
