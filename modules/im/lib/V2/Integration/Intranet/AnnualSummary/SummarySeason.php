<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Intranet\AnnualSummary;

use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;

/**
 * How long the season of the annual summary still has to run.
 *
 * An entry of the metrics — the bounds of the period, the position of a half-counted user — is
 * worth keeping exactly as long as the season is being counted: the stepper of intranet retires
 * itself at the end of the year the period belongs to. A shorter life makes a step redo the work of
 * the step before it, a longer one leaves an entry behind for a season it was not written for.
 */
final class SummarySeason
{
	/**
	 * Life of an entry of a period whose season is already over: such a run still pays for its
	 * entries once instead of on every step.
	 */
	private const REMAINDER_FLOOR = 3600;

	public static function getRemainingSeconds(Date $periodStart): int
	{
		$seasonEnd = new DateTime('01.01.' . ((int)$periodStart->format('Y') + 1), 'd.m.Y');

		return max(self::REMAINDER_FLOOR, $seasonEnd->getTimestamp() - time());
	}
}
