<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks;

use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Rows\RowCollection;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\BlockType;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Field;

class Table extends AbstractBlock
{
	protected RowCollection $rowCollection;

	private function __construct(array $blockData)
	{
		parent::__construct($blockData);
		$this->rowCollection = RowCollection::create($blockData[Field::Rows->value]);
	}

	public static function create(array $blockData): self
	{
		return new self($blockData);
	}

	public static function getType(): BlockType
	{
		return BlockType::Table;
	}

	public function jsonSerialize(): array
	{
		return [
			Field::Id->value => $this->id,
			Field::Type->value => self::getType()->value,
			Field::Rows->value => $this->rowCollection->jsonSerialize(),
		];
	}

	public function toArray(): array
	{
		return [
			Field::Id->value => $this->id,
			Field::Type->value => self::getType()->value,
			Field::Rows->value => $this->rowCollection->toArray(),
		];
	}

	public function getPayloadText(): ?string
	{
		return $this->rowCollection->getPayloadText();
	}

	public static function getRequiredFields(): array
	{
		return [
			Field::Type->value,
			Field::Rows->value,
		];
	}
}
