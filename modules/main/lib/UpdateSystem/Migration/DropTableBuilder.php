<?php

declare(strict_types=1);

namespace Bitrix\Main\UpdateSystem\Migration;

class DropTableBuilder extends \Bitrix\Main\DB\Ddl\Builder\DropTableBuilder
{
	private bool $preliminaryExecutionDisabled = false;

	public function disablePreliminaryExecution(): self
	{
		$this->preliminaryExecutionDisabled = true;

		return $this;
	}

	public function toData(): DropTableData
	{
		/** @var DropTableData */
		return parent::toData();
	}

	protected function createData(string $tableName): DropTableData
	{
		return new DropTableData(
			tableName: $tableName,
			preliminaryExecutionDisabled: $this->preliminaryExecutionDisabled,
		);
	}
}
