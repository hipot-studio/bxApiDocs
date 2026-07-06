<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks;

use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Sources\SourceCollection;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\BlockType;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Field;

class Text extends AbstractBlock
{
	protected string $text;
	protected SourceCollection $sourceCollection;

	private function __construct(array $blockData)
	{
		parent::__construct($blockData);
		$this->text = $blockData[Field::Text->value];
		$this->sourceCollection = SourceCollection::create($blockData[Field::Sources->value] ?? []);
	}

	public static function create(array $blockData): self
	{
		return new self($blockData);
	}

	public static function getType(): BlockType
	{
		return BlockType::Text;
	}

	public function jsonSerialize(): array
	{
		return [
			Field::Id->value => $this->id,
			Field::Type->value => self::getType()->value,
			Field::Text->value => \Bitrix\Im\Text::parse($this->text),
			Field::Sources->value => $this->sourceCollection->jsonSerialize(),
		];
	}

	public function toArray(): array
	{
		return [
			Field::Id->value => $this->id,
			Field::Type->value => self::getType()->value,
			Field::Text->value => $this->text,
			Field::Sources->value => $this->sourceCollection->toArray(),
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
