<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks;

use Bitrix\Im\V2\Message\BlocksBuilder\Entity\BlockType;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Field;

class Map extends AbstractBlock
{
	protected string $imageUrl;
	protected ?string $text;
	protected ?string $status;

	private function __construct(array $blockData)
	{
		parent::__construct($blockData);
		$this->imageUrl = $blockData[Field::ImageUrl->value];
		$this->text = $blockData[Field::Text->value] ?? null;
		$this->status = $blockData[Field::Status->value] ?? null;
	}

	public static function create(array $blockData): self
	{
		return new self($blockData);
	}

	public static function getType(): BlockType
	{
		return BlockType::Map;
	}

	public function jsonSerialize(): array
	{
		return [
			Field::Id->value => $this->id,
			Field::Type->value => self::getType()->value,
			Field::ImageUrl->value => $this->imageUrl,
			Field::Text->value => isset($this->text) ? \Bitrix\Im\Text::parse($this->text) : null,
			Field::Status->value => isset($this->status) ? \Bitrix\Im\Text::parse($this->status) : null,
		];
	}

	public function toArray(): array
	{
		return [
			Field::Id->value => $this->id,
			Field::Type->value => self::getType()->value,
			Field::ImageUrl->value => $this->imageUrl,
			Field::Text->value => $this->text,
			Field::Status->value => $this->status,
		];
	}

	public function getPayloadText(): ?string
	{
		return $this->text;
	}

	public static function getRequiredFields(): array
	{
		return [
			Field::ImageUrl->value,
			Field::Type->value,
		];
	}
}
