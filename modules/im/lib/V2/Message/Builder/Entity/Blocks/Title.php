<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Builder\Entity\Blocks;

use Bitrix\Im\V2\Message\Builder\Entity\Blocks\Field\Size;
use Bitrix\Im\V2\Message\Builder\Entity\BlockType;
use Bitrix\Im\V2\Message\Builder\Entity\Field;
use Bitrix\Im\V2\Message\Color\Color;

class Title extends AbstractBlock
{
	protected int $id;
	protected string $text;
	protected int $size;
	protected string $color;

	private function __construct(array $blockData)
	{
		$this->id = (int)$blockData['id'];
		$this->text = $blockData['text'];
		$this->size = (int)($blockData['size'] ?? Size::DEFAULT_TITLE_SIZE);
		$this->color = $blockData['color'] ?? Color::BASE->value;
	}

	public static function create(array $blockData): self
	{
		return new self($blockData);
	}

	public static function getType(): BlockType
	{
		return BlockType::Title;
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => $this->id,
			'type' => self::getType()->value,
			'text' => \Bitrix\Im\Text::parse($this->text),
			'size' => $this->size,
			'color' => $this->color,
		];
	}

	public function getPayloadText(): ?string
	{
		return $this->text;
	}

	public static function getRequiredFields(): array
	{
		return [
			Field::Type->value,
			Field::Text->value,
		];
	}
}
