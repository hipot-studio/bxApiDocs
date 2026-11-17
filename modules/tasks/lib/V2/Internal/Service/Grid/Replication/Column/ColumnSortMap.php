<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Grid\Replication\Column;

use Bitrix\Tasks\V2\Internal\Service\Grid\Replication\Column\DataProvider\ReplicationProvider;

/**
 * The one place that pairs a sortable grid column of the "Regular tasks" section with a
 * section-settings sorting.
 *
 * A header click and the gear menu change one and the same state, so both halves of the pair
 * come from here:
 * - {@see ReplicationProvider::prepareColumns()} marks a column sortable with the paired
 *   sorting, which is what the native grid then sends back as `by`;
 * - {@see \Bitrix\Tasks\V2\Infrastructure\Controller\Replication\Grid} accepts `by` only when
 *   it names a sorting of this map (no ORM field name reaches the query from the request) and
 *   hands the current sorting to the header as the `SORT` component param, so the arrow stands
 *   on the column the settings point at.
 *
 * Sortings without a column (`originator`, `priority`, `created`) stay available in the gear
 * menu only - they simply have no header to carry an arrow. Period segments and "Total" are
 * aggregates over the window and are not sortable at all.
 */
final class ColumnSortMap
{
	private const SORT_BY_COLUMN = [
		ReplicationProvider::NAME_COLUMN => 'name',
		ReplicationProvider::ASSIGNEE_COLUMN => 'assignee',
		// the condition column has no own field: it is ordered by the regularity type rank
		ReplicationProvider::CONDITION_COLUMN => 'regularity',
		ReplicationProvider::DEADLINE_COLUMN => 'deadline',
	];

	public static function sortByColumnId(string $columnId): ?string
	{
		return self::SORT_BY_COLUMN[$columnId] ?? null;
	}

	public static function columnIdBySortBy(string $sortBy): ?string
	{
		$columnId = array_search($sortBy, self::SORT_BY_COLUMN, true);

		return $columnId === false ? null : $columnId;
	}
}
