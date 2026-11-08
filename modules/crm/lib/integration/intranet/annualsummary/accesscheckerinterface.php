<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\Intranet\AnnualSummary;

interface AccessCheckerInterface
{
	/**
	 * @param int[] $userIds
	 * @return int[]
	 */
	public function getAllowedUserIds(array $userIds): array;
}
