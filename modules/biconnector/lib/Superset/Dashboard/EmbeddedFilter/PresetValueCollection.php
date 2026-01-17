<?php

namespace Bitrix\BIConnector\Superset\Dashboard\EmbeddedFilter;

use Bitrix\Main\Type\Dictionary;

class PresetValueCollection extends Dictionary
{
	public function set($name, $value = null)
	{
		if ($value instanceof PresetValue)
		{
			parent::set($name, $value);
		}
	}

	public function toArray()
	{
		$values = [];

		/** @var PresetValue $presetValue */
		foreach ($this->values as $presetValue)
		{
			$values[] = $presetValue->toArray();
		}

		return $values;
	}

	public function isValueExists(int $value): bool
	{
		/** @var PresetValue $presetValue */
		foreach ($this->values as $presetValue)
		{
			if ($presetValue->value === $value)
			{
				return true;
			}
		}

		return false;
	}
}
