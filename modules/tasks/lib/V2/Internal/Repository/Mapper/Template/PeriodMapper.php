<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Mapper\Template;

use Bitrix\Tasks\V2\Internal\Entity\Template\Period;

class PeriodMapper
{
	public function mapToEnum(string $value): ?Period
	{
		return Period::tryFrom(strtolower($value));
	}

	public function mapFromEnum(?Period $period): ?string
	{
		if ($period === null)
		{
			return null;
		}

		return strtolower($period->value);
	}
}
