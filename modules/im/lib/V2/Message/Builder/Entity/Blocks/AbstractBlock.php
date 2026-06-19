<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Builder\Entity\Blocks;

use Bitrix\Im\V2\Message\Builder\Entity\BlockType;

abstract class AbstractBlock implements \JsonSerializable
{
	abstract public static function create(array $blockData): AbstractBlock;

	abstract public function getPayloadText(): ?string;

	abstract public static function getType(): BlockType;

	abstract public static function getRequiredFields(): array;
}
