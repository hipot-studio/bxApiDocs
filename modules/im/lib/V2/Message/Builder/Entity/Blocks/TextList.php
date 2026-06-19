<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Builder\Entity\Blocks;

use Bitrix\Im\V2\Message\Builder\Entity\Blocks\List\ElementCollection;
use Bitrix\Im\V2\Message\Builder\Entity\Blocks\List\Icon;
use Bitrix\Im\V2\Message\Builder\Entity\BlockType;
use Bitrix\Im\V2\Message\Builder\Entity\Field;

class TextList extends AbstractBlock
{
	protected int $id;
	protected ElementCollection $elements;
	protected string $icon;

	private function __construct(array $blockData)
	{
		$this->id = (int)$blockData['id'];
		$this->icon = $blockData['icon'] ?? Icon::Bullet->value;
		$this->elements = ElementCollection::create($blockData['elements']);
	}

	public static function create(array $blockData): self
	{
		return new self($blockData);
	}

	public static function getType(): BlockType
	{
		return BlockType::List;
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => $this->id,
			'type' => self::getType()->value,
			'icon' => $this->icon,
			'elements' => $this->elements->jsonSerialize(),
		];
	}

	public function getPayloadText(): ?string
	{
		return $this->elements->getPayloadText();
	}

	public static function getRequiredFields(): array
	{
		return [
			Field::Type->value,
			Field::Elements->value,
		];
	}
}
