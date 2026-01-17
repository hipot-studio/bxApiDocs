<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Entity\Collection;

use Bitrix\BizprocDesigner\Internal\Entity\Block;

/**
 * @extends AbstractEntityCollection<Block>
 */
final class BlockCollection extends AbstractEntityCollection
{
	protected function isValidItem(mixed $item): bool
	{
		return $item instanceof Block;
	}

	protected function createEntityFromArray(array $data): Block
	{
		return Block::createFromArray($data);
	}
}
