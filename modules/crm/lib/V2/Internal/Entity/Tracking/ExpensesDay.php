<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Tracking;

use Bitrix\Main\Type\Date;

/**
 * The share of a day in the expenses of a pack.
 *
 * @internal
 */
final readonly class ExpensesDay
{
	public function __construct(
		public Date $date,
		public float $amount,
		public int $actions,
	)
	{
	}
}
