<?php

namespace Bitrix\Bizproc\Internal\Entity\Activity;

use Bitrix\Bizproc\Internal\Entity\AbstractCollection;
use Bitrix\Main\Type\Contract\Arrayable;

/**
 * @extends AbstractCollection<SettingConstraint>
 */
class SettingConstraintCollection extends AbstractCollection implements Arrayable
{
	public function toArray(): array
	{
		return array_map(static fn(SettingConstraint $item) => $item->toArray(), $this->items);
	}

	/**
	 * @return list<string> every setting name the constraints refer to
	 */
	public function getReferencedSettingNames(): array
	{
		$names = [];
		foreach ($this as $constraint)
		{
			$names = array_merge($names, $constraint->settings);
		}

		return array_values(array_unique($names));
	}

	protected function isValidItem(mixed $item): bool
	{
		return $item instanceof SettingConstraint;
	}
}
