<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\Intranet\AnnualSummary;

interface SummaryCleanerInterface
{
	/**
	 * @param int[] $userIds
	 * @param string[] $summaryIds
	 */
	public function delete(array $userIds, array $summaryIds): void;
}
