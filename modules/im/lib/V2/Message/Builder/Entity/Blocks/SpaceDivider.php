<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Builder\Entity\Blocks;

use Bitrix\Im\V2\Message\Builder\Entity\Blocks\Field\Size;
use Bitrix\Im\V2\Message\Builder\Entity\BlockType;
use Bitrix\Im\V2\Message\Builder\Entity\Field;

class SpaceDivider extends AbstractBlock
{
	protected int $id;
	protected string $size;

	private function __construct(array $blockData)
	{
		$this->id = (int)$blockData['id'];
		$this->size = $blockData['size'] ?? Size::DEFAULT_SPACE_DIVIDER_SIZE;
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
			'id' => $this->id,
			'type' => self::getType()->value,
			'size' => $this->size,
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
