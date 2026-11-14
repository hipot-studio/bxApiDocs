<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Tracking;

use Bitrix\Crm\Service\Accounting;
use Bitrix\Crm\V2\Internal\Entity\Tracking\ExpensesDay;
use Bitrix\Main\Type\Date;

/**
 * Splits the expenses of a period into its days exactly as the expenses screen of legacy does
 * ({@see \Bitrix\Crm\Tracking\Internals\SourceExpensesTable::addExpenses()}): the rows of both paths are the
 * same, so the reports count them the same way.
 *
 * The days are counted by the timestamps of the dates, as legacy counts them: a period across the switch to
 * the summer time in a timezone that has one is a day shorter.
 *
 * @internal
 */
final class ExpensesSplitter
{
	private const SECONDS_IN_DAY = 86400;

	/**
	 * @return int 0 when the period starts after its end
	 */
	public function countDays(Date $from, Date $to): int
	{
		$fromTimestamp = $from->getTimestamp();
		$toTimestamp = $to->getTimestamp();
		if ($fromTimestamp > $toTimestamp)
		{
			return 0;
		}

		return (int)(($toTimestamp - $fromTimestamp) / self::SECONDS_IN_DAY) + 1;
	}

	/**
	 * Splits the amount and the actions evenly; the remainders of both go to the first day. The length of the
	 * period is not limited here.
	 *
	 * @return list<ExpensesDay> from the first day of the period; empty when the period starts after its end
	 */
	public function split(Date $from, Date $to, float $amount, string $currencyId, int $actions): array
	{
		$dayCount = $this->countDays($from, $to);
		if ($dayCount <= 0)
		{
			return [];
		}

		$dayAmount = Accounting::roundPublic($amount / $dayCount, $currencyId);
		$amountRemainder = Accounting::roundPublic($amount - $dayAmount * $dayCount, $currencyId);

		$dayActions = (int)($actions / $dayCount);
		$actionsRemainder = $actions - $dayActions * $dayCount;

		$days = [];
		$date = clone $from;
		for ($index = 0; $index < $dayCount; $index++)
		{
			$isFirst = $index === 0;
			$days[] = new ExpensesDay(
				date: clone $date,
				amount: $isFirst && $amountRemainder !== 0.0 ? $dayAmount + $amountRemainder : $dayAmount,
				actions: $isFirst && $actionsRemainder !== 0 ? $dayActions + $actionsRemainder : $dayActions,
			);
			$date->add('+1 day');
		}

		return $days;
	}
}
