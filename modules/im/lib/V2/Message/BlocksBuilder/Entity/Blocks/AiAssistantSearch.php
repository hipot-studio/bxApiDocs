<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks;

use Bitrix\Im\V2\Message\BlocksBuilder\Entity\BlockType;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Field;

class AiAssistantSearch extends AbstractBlock
{
	protected string $title;
	protected string $text;

	private function __construct(array $blockData)
	{
		parent::__construct($blockData);
		$this->title = $blockData[Field::Title->value];
		$this->text = $blockData[Field::Text->value];
	}

	public static function create(array $blockData): self
	{
		return new self($blockData);
	}

	public static function getType(): BlockType
	{
		return BlockType::AiAssistantSearch;
	}

	public function jsonSerialize(): array
	{
		return [
			Field::Id->value => $this->id,
			Field::Type->value => self::getType()->value,
			Field::Title->value => \Bitrix\Im\Text::parse($this->title),
			Field::Text->value => \Bitrix\Im\Text::parse($this->text),
		];
	}

	public function toArray(): array
	{
		return [
			Field::Id->value => $this->id,
			Field::Type->value => self::getType()->value,
			Field::Title->value => $this->title,
			Field::Text->value => $this->text,
		];
	}

	public function getPayloadText(): ?string
	{
		return $this->title . PHP_EOL . $this->text;
	}

	public static function getRequiredFields(): array
	{
		return [
			Field::Type->value,
			Field::Text->value,
			Field::Title->value,
		];
	}
}
