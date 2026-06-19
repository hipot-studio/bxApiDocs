<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Builder\Entity\Blocks;

use Bitrix\Im\V2\Message\Builder\Entity\BlockType;
use Bitrix\Im\V2\Message\Builder\Entity\Field;

class LineDivider extends AbstractBlock
{
	protected int $id;

	private function __construct(array $blockData)
	{
		$this->id = (int)$blockData['id'];
	}

	public static function create(array $blockData): self
	{
		return new self($blockData);
	}

	public static function getType(): BlockType
	{
		return BlockType::LineDivider;
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => $this->id,
			'type' => self::getType()->value,
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
