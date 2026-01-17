<?php
declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Entity\Activity\SetupTemplateActivity;

use Bitrix\Bizproc\Internal\Entity\AbstractCollection;
use Bitrix\Main\Type\Contract\Arrayable;

/**
 * @extends AbstractCollection<Block>
 */
class BlockCollection extends AbstractCollection implements Arrayable
{
	protected function isValidItem(mixed $item): bool
	{
		return $item instanceof Block;
	}

	public function toArray(): array
	{
		return array_map(static fn(Block $item) => $item->toArray(), $this->items);
	}
}