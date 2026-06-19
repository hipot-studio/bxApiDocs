<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Builder\Entity\Blocks;

use Bitrix\Im\V2\Message\Builder\Entity\BlockType;
use Bitrix\Im\V2\Message\Builder\Entity\Field;

class Map extends AbstractBlock
{
	protected int $id;
	protected string $imageUrl;
	protected ?string $text;
	protected ?string $status;

	private function __construct(array $blockData)
	{
		$this->id = (int)$blockData['id'];
		$this->imageUrl = $blockData['imageUrl'];
		$this->text = $blockData['text'] ?? null;
		$this->status = $blockData['status'] ?? null;
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
			'id' => $this->id,
			'type' => self::getType()->value,
			'imageUrl' => \Bitrix\Im\Text::parse($this->imageUrl),
			'text' => \Bitrix\Im\Text::parse($this->text),
			'status' => \Bitrix\Im\Text::parse($this->status),
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
