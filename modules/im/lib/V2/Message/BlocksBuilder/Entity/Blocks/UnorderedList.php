<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks;

use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Color\Color;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\List\ElementCollection;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\List\Fold;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\List\Icon;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Sources\SourceCollection;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\BlockType;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Field;

class UnorderedList extends AbstractBlock
{
	protected ElementCollection $elementCollection;
	protected Icon $icon;
	protected ?Fold $fold;
	protected string $color;
	protected SourceCollection $sourceCollection;

	private function __construct(array $blockData)
	{
		parent::__construct($blockData);
		$this->icon = Icon::create($blockData[Field::Icon->value] ?? []);
		$this->elementCollection = ElementCollection::create($blockData[Field::Elements->value], false);
		$this->fold = isset($blockData[Field::Fold->value]) ? Fold::create($blockData[Field::Fold->value]) : null;
		$this->color = $blockData[Field::Color->value] ?? Color::Base->value;
		$this->sourceCollection = SourceCollection::create($blockData[Field::Sources->value] ?? []);
	}

	public static function create(array $blockData): self
	{
		return new self($blockData);
	}

	public static function getType(): BlockType
	{
		return BlockType::UnorderedList;
	}

	public function jsonSerialize(): array
	{
		return [
			Field::Id->value => $this->id,
			Field::Type->value => self::getType()->value,
			Field::Icon->value => $this->icon->jsonSerialize(),
			Field::Elements->value => $this->elementCollection->jsonSerialize(),
			Field::Fold->value => $this->fold?->jsonSerialize(),
			Field::Color->value => $this->color,
			Field::Sources->value => $this->sourceCollection->jsonSerialize(),
		];
	}

	public function toArray(): array
	{
		return [
			Field::Id->value => $this->id,
			Field::Type->value => self::getType()->value,
			Field::Icon->value => $this->icon->toArray(),
			Field::Elements->value => $this->elementCollection->toArray(),
			Field::Fold->value => $this->fold?->toArray(),
			Field::Color->value => $this->color,
			Field::Sources->value => $this->sourceCollection->toArray(),
		];
	}

	public function getPayloadText(): ?string
	{
		return $this->elementCollection->getPayloadText();
	}

	public static function getRequiredFields(): array
	{
		return [
			Field::Type->value,
			Field::Elements->value,
		];
	}
}
