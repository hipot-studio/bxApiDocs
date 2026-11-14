<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Enum;

/**
 * Actuality of the stored used size counter of a personal quota row.
 * Calculating is also the claim a recalculation run holds on the row.
 */
enum UsageStatus: string
{
	case Actual = 'actual';
	case Stale = 'stale';
	case Calculating = 'calculating';
}
