<?php

declare(strict_types=1);

namespace Bitrix\Sale\Public\Dto;

use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\Type\DateTime;

/**
 * Statistic of a buyer on one site in one currency, together with the buyer record: a row of the
 * buyers list.
 *
 * A buyer who has paid on several sites or in several currencies has a row for each of them; the
 * numbers are those of the row and the amount is in its currency. A buyer of the shop group who has
 * paid for nothing yet is one row with no statistic behind it: id 0, no site and no currency, zeros.
 */
final class BuyerStatistic implements EntityInterface
{
	/**
	 * @param int $id id of the statistic row; 0 for a buyer who has none
	 * @param int $userId buyer the row belongs to
	 * @param string $siteId site of the orders the row counts
	 * @param string $currency currency of the orders the row counts
	 */
	public function __construct(
		public readonly int $id,
		public readonly int $userId,
		public readonly string $siteId,
		public readonly string $currency,
		public readonly float $sumPaid = 0.0,
		public readonly int $countFullPaidOrder = 0,
		public readonly int $countPartPaidOrder = 0,
		public readonly ?DateTime $lastOrderDate = null,
		public readonly string $login = '',
		public readonly string $name = '',
		public readonly string $lastName = '',
		public readonly string $secondName = '',
		public readonly string $email = '',
		public readonly string $personalPhone = '',
		public readonly ?DateTime $lastLogin = null,
		public readonly ?DateTime $dateRegister = null,
		public readonly string $externalAuthId = '',
	)
	{
	}

	public function getId(): int
	{
		return $this->id;
	}
}
