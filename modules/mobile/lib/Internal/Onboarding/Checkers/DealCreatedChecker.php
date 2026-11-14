<?php

namespace Bitrix\Mobile\Internal\Onboarding\Checkers;

use Bitrix\Crm\DealTable;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\DateTime;

class DealCreatedChecker extends BulkRecordExistsStepChecker
{
	protected function getModuleId(): string
	{
		return 'crm';
	}

	protected function getAuthorField(): string
	{
		return 'CREATED_BY_ID';
	}

	protected function recordExistsForUser(int $userId, DateTime $sinceDate): bool
	{
		// Trashed deals are physically removed from b_crm_deal (recycle bin is a
		// separate store), so presence in DealTable already means "not trashed".
		$row = DealTable::query()
			->setSelect(['ID'])
			->where('CREATED_BY_ID', $userId)
			->where('DATE_CREATE', '>=', $sinceDate)
			->setLimit(1)
			->fetch()
		;

		return !empty($row);
	}

	/**
	 * One query for the whole pass instead of one per employee. No index of `b_crm_deal` starts
	 * with CREATED_BY_ID (`IX_DEAL_20` starts with the date), so the per-user route re-reads the
	 * same date range of the campaign for every candidate of the CRM path.
	 *
	 * @param int[] $userIds
	 */
	protected function createBatchQuery(array $userIds, DateTime $sinceDate): Query
	{
		return DealTable::query()
			->setSelect(['CREATED_BY_ID'])
			->whereIn('CREATED_BY_ID', $userIds)
			->where('DATE_CREATE', '>=', $sinceDate)
			->setGroup(['CREATED_BY_ID'])
		;
	}
}
