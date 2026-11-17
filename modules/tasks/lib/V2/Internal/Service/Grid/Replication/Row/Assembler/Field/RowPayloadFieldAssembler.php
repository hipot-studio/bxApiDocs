<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Grid\Replication\Row\Assembler\Field;

use Bitrix\Main\Grid\Row\FieldAssembler;
use Bitrix\Tasks\V2\Internal\Service\Grid\Trait\EscapedJsonTrait;

/**
 * "Rule" cell (NAME column) - carries the whole DTO-S1 in `data-replication-row` (TPL-S1).
 *
 * The frontend mounts the row overlay by finding `[data-replication-row]` nodes and takes
 * `templateId` from the nearest `[data-id]` (the grid sets it from the row `ID` field).
 * attribute names are normative (TPL-S1) - do not rename.
 */
class RowPayloadFieldAssembler extends FieldAssembler
{
	use EscapedJsonTrait;

	/**
	 * NAME-column marker of the "Workload" frame row: renders the aggregate label anchor
	 * (title + summary) onto which the frontend mounts the overlay
	 * ({@see \Bitrix\Tasks\V2\Internal\Service\Grid\Replication\Row\FrameRows::workload()}).
	 */
	public const WORKLOAD_LABEL = 'workload-label';

	/**
	 * @param array|string|null $value rule-row DTO-S1, the "Workload" frame-row marker
	 *   ({@see self::WORKLOAD_LABEL}) or `null` for the other frame rows whose
	 *   "Regular task" column is empty.
	 */
	protected function prepareColumn($value): string
	{
		if ($value === self::WORKLOAD_LABEL)
		{
			return '<div data-replication-workload-label></div>';
		}

		if (!is_array($value))
		{
			return '';
		}

		return <<<HTML
<div data-replication-row="{$this->toEscapedJson($value)}"></div>
HTML;
	}
}
