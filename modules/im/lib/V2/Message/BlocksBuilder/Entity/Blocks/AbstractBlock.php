<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks;

use Bitrix\Im\V2\Message\BlocksBuilder\Entity\BlockType;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Field;

abstract class AbstractBlock implements \JsonSerializable
{
	protected string $id;

	public function __construct(array $blockData)
	{
		$this->id = (string)$blockData[Field::Id->value];
	}

	abstract public static function create(array $blockData): AbstractBlock;

	abstract public function getPayloadText(): ?string;

	abstract public static function getType(): BlockType;

	abstract public static function getRequiredFields(): array;

	abstract public function toArray(): array;

	public function getId(): string
	{
		return $this->id;
	}

	public function getFiles(): array
	{
		return [];
	}
}
