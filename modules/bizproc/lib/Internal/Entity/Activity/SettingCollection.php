<?php

namespace Bitrix\Bizproc\Internal\Entity\Activity;

use Bitrix\Bizproc\Internal\Entity\AbstractCollection;
use Bitrix\Main\Type\Contract\Arrayable;

/**
 * @extends AbstractCollection<Setting>
 */
class SettingCollection extends AbstractCollection implements Arrayable
{
	public function toArray(): array
	{
		return array_map(static fn(Setting $item) => $item->toArray(), $this->items);
	}

	protected function isValidItem(mixed $item): bool
	{
		return $item instanceof Setting;
	}

	public function findFirstByName(string $name): ?Setting
	{
		foreach ($this as $item)
		{
			if ($item->name === $name)
			{
				return $item;
			}
		}

		return null;
	}

	/**
	 * @return array<string, string> [type => description,...]
	 */
	public function getDescribedSettingTypesMap(): array
	{
		return $this->fillSettingTypeCollectionRecursive($this);
	}

	/**
	 * @return array<string, string> [type => description,...]
	 */
	private function fillSettingTypeCollectionRecursive(SettingCollection $settings): array
	{
		$types = [];
		foreach ($settings as $setting)
		{
			if ($setting->type instanceof SettingType && $setting->description !== '')
			{
				$types[$setting->type->name] = $setting->type->description;
			}

			if ($setting->children instanceof SettingCollection)
			{
				$types += $this->fillSettingTypeCollectionRecursive($setting->children);
			}
		}

		return $types;
	}
}