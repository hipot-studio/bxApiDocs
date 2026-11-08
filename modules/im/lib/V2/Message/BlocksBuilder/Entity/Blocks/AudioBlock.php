<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks;

use Bitrix\Im\V2\Message\BlocksBuilder\Entity\BlockType;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Field;

class AudioBlock extends AbstractBlock
{
	protected int $fileId;

	private function __construct(array $blockData)
	{
		parent::__construct($blockData);
		$this->fileId = $blockData[Field::FileId->value];
	}

	public static function create(array $blockData): self
	{
		return new self($blockData);
	}

	public static function getType(): BlockType
	{
		return BlockType::Audio;
	}

	public function jsonSerialize(): array
	{
		return [
			Field::Id->value => $this->id,
			Field::Type->value => self::getType()->value,
			Field::FileId->value => $this->fileId,
		];
	}

	public function toArray(): array
	{
		return [
			Field::Id->value => $this->id,
			Field::Type->value => self::getType()->value,
			Field::FileId->value => $this->fileId,
		];
	}

	public function getPayloadText(): ?string
	{
		return $this->getFileNamesText();
	}

	public static function getRequiredFields(): array
	{
		return [
			Field::Type->value,
			Field::FileId->value,
		];
	}

	public function getFiles(): array
	{
		return [$this->fileId];
	}
}
