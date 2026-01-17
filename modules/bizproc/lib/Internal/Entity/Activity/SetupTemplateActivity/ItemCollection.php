<?php
declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Entity\Activity\SetupTemplateActivity;

use Bitrix\Bizproc\Internal\Entity\AbstractCollection;
use Bitrix\Main\Type\Contract\Arrayable;

class ItemCollection extends AbstractCollection implements Arrayable
{
	protected function isValidItem(mixed $item): bool
	{
		return $item instanceof Item;
	}

	public function toArray(): array
	{
		return array_map(static fn(Item $item) => $item->toArray(), $this->items);
	}
}