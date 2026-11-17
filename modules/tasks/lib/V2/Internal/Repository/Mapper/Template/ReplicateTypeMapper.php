<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Mapper\Template;

use Bitrix\Tasks\Replication\Template\Enum\RegularityType;

class ReplicateTypeMapper
{
	/**
	 * Column value (int, NULL or absent) -> type. NULL / unknown reads as Schedule.
	 */
	public function mapToEnum(int|string|null $type): RegularityType
	{
		return RegularityType::fromValue($type);
	}

	public function mapFromEnum(RegularityType $type): int
	{
		return $type->value;
	}
}
