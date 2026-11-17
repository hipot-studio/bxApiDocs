<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Grid\Replication\Row\Assembler\Field;

use Bitrix\Main\Grid\Row\FieldAssembler;
use Bitrix\Tasks\V2\Internal\Service\Grid\Replication\Column\DataProvider\ReplicationProvider;
use Bitrix\Tasks\V2\Internal\Service\Grid\Trait\EscapedJsonTrait;

/**
 * Period-column cells of a rule row (COL-01): an anchor container
 * `data-replication-cell` with the bucket key `data-bucket-key="<key>"` and the embedded
 * projection bucket `data-bucket` (DTO-S2). The key matches the projection bucket key (single
 * source - PER-01), so the cell frontend overlay finds its data (number + stacked bar +
 * red dot) by that key. The bucket arrives with the grid (the projection is embedded into the
 * row via `SCHEDULE_CELL`, {@see \Bitrix\Tasks\V2\Infrastructure\Controller\Replication\Grid}),
 * so no separate API-S3 request is needed - the overlay hydrates the bucket from the DOM.
 *
 * Period columns are created dynamically for the current period; their id is `PERIOD_<key>`;
 * the key is extracted back from the id.
 *
 * data-attribute names are normative (COL-01) - do not rename. The e2e anchor `data-testid`
 * is additive and does not affect overlay hydration (mounted by `data-replication-cell`).
 */
class PeriodCellFieldAssembler extends FieldAssembler
{
	use EscapedJsonTrait;

	/**
	 * @param string[] $columnIds period-column ids of the current slice (`PERIOD_<key>`)
	 */
	public function __construct(array $columnIds)
	{
		parent::__construct($columnIds);
	}

	protected function prepareRow(array $row): array
	{
		if ($this->getColumnIds() === [])
		{
			return $row;
		}

		$row['columns'] ??= [];

		$bucketsByKey = $this->indexBuckets($row['data']['SCHEDULE_CELL'] ?? null);

		foreach ($this->getColumnIds() as $columnId)
		{
			$key = $this->bucketKey($columnId);
			$bucket = $bucketsByKey[$key] ?? null;
			$bucketAttr = $bucket === null ? '' : ' data-bucket="' . $this->toEscapedJson($bucket) . '"';

			$row['columns'][$columnId] =
				'<div data-testid="tasks-replication-schedule-cell" data-replication-cell data-bucket-key="'
				. htmlspecialcharsbx($key) . '"' . $bucketAttr . '></div>'
			;
		}

		return $row;
	}

	/**
	 * Row projection buckets indexed by key (DTO-S2 -> cell `data-bucket`).
	 *
	 * @param mixed $cell projection embedded into the row ({@see ScheduleCellDto::toArray()}) or null
	 * @return array<string, array>
	 */
	private function indexBuckets(mixed $cell): array
	{
		if (!is_array($cell) || !is_array($cell['buckets'] ?? null))
		{
			return [];
		}

		$indexed = [];
		foreach ($cell['buckets'] as $bucket)
		{
			if (is_array($bucket) && isset($bucket['key']))
			{
				$indexed[(string)$bucket['key']] = $bucket;
			}
		}

		return $indexed;
	}

	private function bucketKey(string $columnId): string
	{
		$prefix = ReplicationProvider::PERIOD_COLUMN_PREFIX;

		return str_starts_with($columnId, $prefix) ? substr($columnId, strlen($prefix)) : $columnId;
	}
}
