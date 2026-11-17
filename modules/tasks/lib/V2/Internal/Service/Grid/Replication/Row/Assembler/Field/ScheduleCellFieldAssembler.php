<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Grid\Replication\Row\Assembler\Field;

use Bitrix\Main\Grid\Row\FieldAssembler;

/**
 * "Schedule" cell (SCHEDULE column) - an empty anchor container onto which the frontend
 * mounts a Vue overlay (TPL-S1). The anchor kind is chosen by the column value:
 *
 * - rule rows (value `null`) - `data-replication-cell`: the schedule overlay fills the cell
 *   with a separate request (API-S3) by `templateId` from the nearest `[data-id]`;
 * - the "periods" frame row ({@see self::PERIODS}) - `data-replication-periods`: schedule
 *   period headers (first row of the page, {@see FrameRows});
 * - the "workload" frame row ({@see self::WORKLOAD}) - `data-replication-workload`:
 *   the "Workload" aggregate (last row of the page, {@see FrameRows}).
 *
 * attribute names are normative (TPL-S1) - do not rename.
 *
 * @see \Bitrix\Tasks\V2\Internal\Service\Grid\Replication\Row\FrameRows
 */
class ScheduleCellFieldAssembler extends FieldAssembler
{
	public const PERIODS = 'periods';
	public const WORKLOAD = 'workload';

	protected function prepareColumn($value): string
	{
		return match ($value)
		{
			self::PERIODS => '<div data-replication-periods></div>',
			self::WORKLOAD => '<div data-replication-workload></div>',
			default => '<div data-replication-cell></div>',
		};
	}
}
