<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Entity\FullReport;

/**
 * Internal mark for full reports.
 *
 * Values follow legacy full report MARK semantics.
 */
enum FullReportMark: string
{
	case POSITIVE = 'G';
	case NEGATIVE = 'B';
	case NEUTRAL = 'N';
	case UNCONFIRMED = 'X';
}
