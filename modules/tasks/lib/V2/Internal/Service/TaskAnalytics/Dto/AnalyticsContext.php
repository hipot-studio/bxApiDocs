<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\TaskAnalytics\Dto;

use Bitrix\Main\Type\DateTime;

final class AnalyticsContext
{
	/**
	 * @param array<int, bool> $resultsExistenceMap
	 * @param array<int, bool> $checklistCompletedItemsExistenceMap
	 * @param array<int, ?DateTime> $lastSignificantHistoryDatesMap
	 * @param array<int, ?DateTime> $chatLastMessageDatesMap
	 */
	public function __construct(
		public readonly array $resultsExistenceMap = [],
		public readonly array $checklistCompletedItemsExistenceMap = [],
		public readonly array $lastSignificantHistoryDatesMap = [],
		public readonly array $chatLastMessageDatesMap = [],
	)
	{
	}
}
