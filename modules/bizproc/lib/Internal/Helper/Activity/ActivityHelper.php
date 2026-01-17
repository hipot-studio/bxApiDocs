<?php

namespace Bitrix\Bizproc\Internal\Helper\Activity;

/**
 * A helper service class with common methods for business process triggers.
 */
class ActivityHelper
{
	/**
	 * Generates a unique name for use in triggers.
	 *
	 * @return string The generated name.
	 */
	public static function generateName(): string
	{
		$part1 = mt_rand(10000, 99999);
		$part2 = mt_rand(10000, 99999);
		$part3 = mt_rand(10000, 99999);
		$part4 = mt_rand(10000, 99999);

		return "A{$part1}_{$part2}_{$part3}_{$part4}";
	}
}