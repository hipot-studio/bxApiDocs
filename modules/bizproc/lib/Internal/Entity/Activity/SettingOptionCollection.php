<?php

namespace Bitrix\Bizproc\Internal\Entity\Activity;

use Bitrix\Bizproc\Internal\Entity\AbstractCollection;
use Bitrix\Main\Type\Contract\Arrayable;

/**
 * @extends AbstractCollection<SettingOption>
 */
class SettingOptionCollection extends AbstractCollection implements Arrayable
{
	public function toArray(): array
	{
		return array_map(static fn(SettingOption $item) => $item->toArray(), $this->items);
	}

	protected function isValidItem(mixed $item): bool
	{
		return $item instanceof SettingOption;
	}
}