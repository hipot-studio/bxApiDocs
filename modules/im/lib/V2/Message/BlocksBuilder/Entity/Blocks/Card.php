<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks;

use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Buttons\ButtonCollection;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\BlockType;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Field;

class Card extends AbstractBlock
{
	protected string $title;
	protected ?string $imageUrl;
	protected ?string $text;
	protected ButtonCollection $buttons;

	private function __construct(array $blockData)
	{
		parent::__construct($blockData);
		$this->title = $blockData[Field::Title->value];
		$this->imageUrl = $blockData[Field::ImageUrl->value] ?? null;
		$this->text = $blockData[Field::Text->value] ?? null;
		$this->buttons = ButtonCollection::create($blockData[Field::Buttons->value] ?? []);
	}

	public static function create(array $blockData): self
	{
		return new self($blockData);
	}

	public static function getType(): BlockType
	{
		return BlockType::Card;
	}

	public function jsonSerialize(): array
	{
		return [
			Field::Id->value => $this->id,
			Field::Type->value => self::getType()->value,
			Field::ImageUrl->value => $this->imageUrl,
			Field::Text->value => $this->text ? \Bitrix\Im\Text::parse($this->text) : null,
			Field::Title->value => $this->title ? \Bitrix\Im\Text::parse($this->title) : null,
			Field::Buttons->value => $this->buttons->jsonSerialize(),
		];
	}

	public function toArray(): array
	{
		return [
			Field::Id->value => $this->id,
			Field::Type->value => self::getType()->value,
			Field::ImageUrl->value => $this->imageUrl,
			Field::Text->value => $this->text,
			Field::Title->value => $this->title,
			Field::Buttons->value => $this->buttons->toArray(),
		];
	}

	public function getPayloadText(): ?string
	{
		if ($this->text === null)
		{
			return $this->title;
		}

		return $this->title . PHP_EOL . $this->text;
	}

	public static function getRequiredFields(): array
	{
		return [
			Field::Title->value,
			Field::Type->value,
		];
	}
}
