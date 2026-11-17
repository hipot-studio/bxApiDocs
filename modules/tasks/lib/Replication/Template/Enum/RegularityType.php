<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Replication\Template\Enum;

/**
 * Discriminator of the regular task strategy stored in b_tasks_template.REPLICATE_TYPE.
 *
 * NULL / unknown values are read as {@see RegularityType::Schedule} (backward compatibility:
 * existing templates predate the column and have no data migration).
 */
enum RegularityType: int
{
	case Schedule = 0;
	case Deadline = 1;
	case AfterCompletion = 2;

	/**
	 * Resolves a stored value (column int, NULL or absent) to a type, defaulting to Schedule.
	 */
	public static function fromValue(int|string|null $value): self
	{
		if ($value === null || $value === '')
		{
			return self::Schedule;
		}

		return self::tryFrom((int)$value) ?? self::Schedule;
	}
}
