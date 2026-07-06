<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks;

use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\SpaceDivider\Size;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\BlockType;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Field;

class SpaceDivider extends AbstractBlock
{
	protected string $size;

	private function __construct(array $blockData)
	{
		parent::__construct($blockData);
		$this->size = $blockData[Field::Size->value] ?? Size::Small->value;
	}

	public static function create(array $blockData): self
	{
		return new self($blockData);
	}

	public static function getType(): BlockType
	{
		return BlockType::SpaceDivider;
	}

	public function jsonSerialize(): array
	{
		return [
			Field::Id->value => $this->id,
			Field::Type->value => self::getType()->value,
			Field::Size->value => $this->size,
		];
	}

	public function toArray(): array
	{
		return [
			Field::Id->value => $this->id,
			Field::Type->value => self::getType()->value,
			Field::Size->value => $this->size,
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
		];
	}
}
