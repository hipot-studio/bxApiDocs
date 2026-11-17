<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Grid\Replication\Row\Assembler;

use Bitrix\Main\Grid\Row\FieldAssembler;
use Bitrix\Main\Grid\Row\RowAssembler;
use Bitrix\Tasks\V2\Internal\Service\Grid\Replication\Column\DataProvider\ReplicationProvider;
use Bitrix\Tasks\V2\Internal\Service\Grid\Replication\Row\Assembler\Field\AttributeCellFieldAssembler;
use Bitrix\Tasks\V2\Internal\Service\Grid\Replication\Row\Assembler\Field\PeriodCellFieldAssembler;
use Bitrix\Tasks\V2\Internal\Service\Grid\Replication\Row\Assembler\Field\RowPayloadFieldAssembler;

class ReplicationAssembler extends RowAssembler
{
	/**
	 * @param string[] $visibleColumnIds
	 * @param string[] $periodColumnIds period-column ids of the current slice (`PERIOD_<key>`)
	 */
	public function __construct(
		array $visibleColumnIds,
		private readonly array $periodColumnIds = [],
	)
	{
		parent::__construct($visibleColumnIds);
	}

	protected function prepareFieldAssemblers(): array
	{
		return [
			new RowPayloadFieldAssembler([ReplicationProvider::NAME_COLUMN]),
			new AttributeCellFieldAssembler(),
			new class([
				ReplicationProvider::ORIGINATOR_NAME_COLUMN,
				ReplicationProvider::GROUP_NAME_COLUMN,
				ReplicationProvider::ID_COLUMN,
				ReplicationProvider::AUDITOR_COLUMN,
				ReplicationProvider::ACCOMPLICE_COLUMN,
			]) extends FieldAssembler
			{
			},
			new PeriodCellFieldAssembler($this->periodColumnIds),
		];
	}
}
