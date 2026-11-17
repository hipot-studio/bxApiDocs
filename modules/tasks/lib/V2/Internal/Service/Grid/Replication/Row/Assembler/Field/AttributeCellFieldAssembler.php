<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Grid\Replication\Row\Assembler\Field;

use Bitrix\Main\Grid\Row\FieldAssembler;
use Bitrix\Tasks\V2\Internal\Service\Grid\Replication\Column\DataProvider\ReplicationProvider;

/**
 * Attribute cells of a rule row (COL-01): assignee, recurrence condition and deadline.
 * Each is an empty anchor container onto which the frontend mounts a Vue overlay; the overlay
 * takes its content from the row DTO (the `data-replication-row` anchor in the NAME column).
 *
 * data-attribute names are normative (COL-01) - do not rename.
 */
class AttributeCellFieldAssembler extends FieldAssembler
{
	private const ANCHOR_BY_COLUMN = [
		ReplicationProvider::ASSIGNEE_COLUMN => 'data-replication-assignee',
		ReplicationProvider::CONDITION_COLUMN => 'data-replication-condition',
		ReplicationProvider::DEADLINE_COLUMN => 'data-replication-deadline',
	];

	public function __construct()
	{
		parent::__construct(array_keys(self::ANCHOR_BY_COLUMN));
	}

	protected function prepareRow(array $row): array
	{
		$row['columns'] ??= [];

		foreach ($this->getColumnIds() as $columnId)
		{
			$anchor = self::ANCHOR_BY_COLUMN[$columnId] ?? null;
			if ($anchor === null)
			{
				$row['columns'][$columnId] = '';

				continue;
			}

			$row['columns'][$columnId] = "<div {$anchor}></div>";
		}

		return $row;
	}
}
