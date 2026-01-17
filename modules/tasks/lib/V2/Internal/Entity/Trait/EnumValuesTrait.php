<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity\Trait;

use BackedEnum;

/**
 * @mixin BackedEnum
 */
trait EnumValuesTrait
{
	/**
	 * @return array<string|int>
	 */
	public static function values(): array
	{
		return array_column(self::cases(), 'value');
	}
}
