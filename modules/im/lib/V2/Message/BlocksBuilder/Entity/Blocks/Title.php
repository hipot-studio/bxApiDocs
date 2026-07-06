<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks;

use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Color\Color;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Title\Size;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\BlockType;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Field;

class Title extends AbstractBlock
{
	protected string $text;
	protected int $size;
	protected string $color;

	private function __construct(array $blockData)
	{
		parent::__construct($blockData);
		$this->text = $blockData[Field::Text->value];
		$this->size = (int)($blockData[Field::Size->value] ?? Size::Small->value);
		$this->color = $blockData[Field::Color->value] ?? Color::Base->value;
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
			Field::Id->value => $this->id,
			Field::Type->value => self::getType()->value,
			Field::Text->value => \Bitrix\Im\Text::parse($this->text, ['LINK' => 'N']),
			Field::Size->value => $this->size,
			Field::Color->value => $this->color,
		];
	}

	public function toArray(): array
	{
		return [
			Field::Id->value => $this->id,
			Field::Type->value => self::getType()->value,
			Field::Text->value => $this->text,
			Field::Size->value => $this->size,
			Field::Color->value => $this->color,
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
