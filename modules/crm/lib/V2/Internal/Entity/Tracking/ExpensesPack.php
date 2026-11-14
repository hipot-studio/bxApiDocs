<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Tracking;

use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;

/**
 * Expenses of a source for a period, as they were entered: the pack keeps the totals and the comment,
 * the days of the period keep the totals split by day.
 *
 * @internal
 */
final readonly class ExpensesPack
{
	public function __construct(
		public ?int $id,
		public int $sourceId,
		public Date $dateFrom,
		public Date $dateTo,
		public float $amount,
		public string $currencyId,
		public int $actions,
		public ?string $comment,
		public ?DateTime $createdTime,
	)
	{
	}
}
