<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\Intranet\AnnualSummary;

use Bitrix\Intranet\Internal\Model\AnnualSummaryTable;
use Bitrix\Main\SystemException;

final class IntranetSummaryCleaner implements SummaryCleanerInterface
{
	public function delete(array $userIds, array $summaryIds): void
	{
		$userIds = $this->normalizeUserIds($userIds);
		$summaryIds = $this->normalizeSummaryIds($summaryIds);
		if ($userIds === [] || $summaryIds === [])
		{
			return;
		}

		$storedIds = AnnualSummaryTable::query()
			->setSelect(['ID'])
			->whereIn('USER_ID', $userIds)
			->whereIn('NAME', $summaryIds)
			->fetchCollection()
			->getIdList()
		;
		foreach ($storedIds as $storedId)
		{
			$result = AnnualSummaryTable::delete($storedId);
			if (!$result->isSuccess())
			{
				throw new SystemException(implode('; ', $result->getErrorMessages()));
			}
		}
	}

	/** @return int[] */
	private function normalizeUserIds(array $userIds): array
	{
		$normalized = [];
		foreach ($userIds as $userId)
		{
			$userId = (int)$userId;
			if ($userId > 0)
			{
				$normalized[$userId] = $userId;
			}
		}

		return array_values($normalized);
	}

	/** @return string[] */
	private function normalizeSummaryIds(array $summaryIds): array
	{
		$normalized = [];
		foreach ($summaryIds as $summaryId)
		{
			$summaryId = trim((string)$summaryId);
			if ($summaryId !== '')
			{
				$normalized[$summaryId] = $summaryId;
			}
		}

		return array_values($normalized);
	}
}
