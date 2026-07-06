<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Common;

final class Normalizer
{
	/**
	 * @param array<int|string|mixed> $values
	 * @return int[]
	 */
	public static function toUniquePositiveIntegers(array $values): array
	{
		$result = [];
		foreach ($values as $value)
		{
			$value = (int)$value;
			if ($value > 0)
			{
				$result[$value] = $value;
			}
		}

		return array_values($result);
	}
}
