<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Builder\Entity\Blocks;

use Bitrix\Im\V2\Message\Builder\Entity\Blocks\Table\RowCollection;
use Bitrix\Im\V2\Message\Builder\Entity\BlockType;
use Bitrix\Im\V2\Message\Builder\Entity\Field;

class Table extends AbstractBlock
{
	protected int $id;
	protected RowCollection $rows;

	private function __construct(array $blockData)
	{
		$this->id = (int)$blockData['id'];
		$this->rows = RowCollection::create($blockData['rows']);
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
			'id' => $this->id,
			'type' => self::getType()->value,
			'rows' => $this->rows->jsonSerialize(),
		];
	}

	public function getPayloadText(): ?string
	{
		return $this->rows->getPayloadText();
	}

	public static function getRequiredFields(): array
	{
		return [
			Field::Type->value,
			Field::Rows->value,
		];
	}
}
