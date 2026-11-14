<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\Intranet\AnnualSummary;

use Bitrix\Intranet\Internal\Entity\AnnualSummary\SummaryInterface;

final class ClosedDealBestMonthSummary implements SummaryInterface
{
	public function __construct(
		private readonly int $userId,
		private readonly int $bestMonth,
	)
	{
	}

	public function getId(): string
	{
		return 'crm.closed_deals.best_month';
	}

	public function getUserId(): int
	{
		return $this->userId;
	}

	public function getTotal(): int
	{
		return $this->bestMonth;
	}
}
