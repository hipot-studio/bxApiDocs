<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Builder\Entity\Blocks;

use Bitrix\Im\V2\Message\Builder\Entity\BlockType;
use Bitrix\Im\V2\Message\Builder\Entity\Field;

class Text extends AbstractBlock
{
	protected int $id;
	protected string $text;
	private function __construct(array $blockData)
	{
		$this->id = (int)$blockData['id'];
		$this->text = $blockData['text'];
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
			'id' => $this->id,
			'text' => \Bitrix\Im\Text::parse($this->text),
			'type' => self::getType()->value,
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
