<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks;

use Bitrix\Im\V2\Message\BlocksBuilder\Entity\BlockType;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Field;

class Gallery extends AbstractBlock
{
	protected array $fileIds;

	private function __construct(array $blockData)
	{
		parent::__construct($blockData);
		$this->fileIds = $blockData[Field::FileIds->value];
	}

	public static function create(array $blockData): self
	{
		return new self($blockData);
	}

	public static function getType(): BlockType
	{
		return BlockType::Gallery;
	}

	public function jsonSerialize(): array
	{
		return [
			Field::Id->value => $this->id,
			Field::Type->value => self::getType()->value,
			Field::FileIds->value => $this->fileIds,
		];
	}

	public function toArray(): array
	{
		return [
			Field::Id->value => $this->id,
			Field::Type->value => self::getType()->value,
			Field::FileIds->value => $this->fileIds,
		];
	}

	public function getPayloadText(): ?string
	{
		return null;
	}

	public static function getRequiredFields(): array
	{
		return [
			Field::Type->value,
			Field::FileIds->value,
		];
	}

	public function getFiles(): array
	{
		return $this->fileIds;
	}
}
