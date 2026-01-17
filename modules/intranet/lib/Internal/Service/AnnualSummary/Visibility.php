<?php

namespace Bitrix\Intranet\Internal\Service\AnnualSummary;

use Bitrix\Intranet\Internal\Repository\AnnualSummaryRepository;
use Bitrix\Main\Config\Option;

class Visibility
{
	public function __construct(
		private readonly int $userId,
	) {
	}

	public function canShow(): bool
	{
		return (int)Option::get('intranet', 'annual_summary_25_start_show', strtotime('2025-12-15')) <= time()
			&& (int)Option::get('intranet', 'annual_summary_25_end_show', strtotime('2025-12-30')) > time()
			&& (new AnnualSummaryRepository($this->userId))->has();
	}

	public function canForceShow(): bool
	{
		return $this->canShow()
			&& (int)\CUserOptions::GetOption('intranet', 'annual_summary_25_last_show', 0, $this->userId) <= 0;
	}
}
