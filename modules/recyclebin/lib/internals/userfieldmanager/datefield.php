<?php

namespace Bitrix\Recyclebin\Internals\UserFieldManager;

use Bitrix\Main\Type\Date;

class DateField extends BaseField
{
	public function onMoveToRecycleBin(&$value): void
	{
		$this->normalize($value);
	}

	public function onRestoreFromRecycleBin(&$value): void
	{
		$this->normalize($value);
	}

	private function normalize(&$value): void
	{
		if ($this->userField['MULTIPLE'] === 'Y')
		{
			$items = is_array($value) ? $value : [$value];
			$normalized = [];
			foreach ($items as $item)
			{
				$single = $this->normalizeValue($item);
				if ($single !== null)
				{
					$normalized[] = $single;
				}
			}
			$value = $normalized;
		}
		else
		{
			$value = $this->normalizeValue($value);
		}
	}

	private function normalizeValue(mixed $value): ?string
	{
		if ($value instanceof Date)
		{
			return (string)$value;
		}

		if (is_string($value))
		{
			$value = trim($value);

			return $value === '' ? null : $value;
		}

		return null;
	}
}
