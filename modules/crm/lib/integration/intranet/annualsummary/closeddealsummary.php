<?php

namespace Bitrix\Crm\Integration\Intranet\AnnualSummary;

use Bitrix\Intranet\Internal\Entity\AnnualSummary\SummaryInterface;

final class ClosedDealSummary implements SummaryInterface
{
	public function __construct(
		private readonly int $userId,
		private readonly int $total,
	)
	{
	}

	public function getId(): string
	{
		return 'crm.closed_deals';
	}

	public function getUserId(): int
	{
		return $this->userId;
	}

	public function getTotal(): int
	{
		return $this->total;
	}
}
