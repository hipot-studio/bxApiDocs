<?php

declare(strict_types=1);

namespace Bitrix\Main\UpdateSystem\Migration;

class DropTableData extends \Bitrix\Main\DB\Ddl\Builder\DropTableData
{
	public function __construct(
		string $tableName,
		private readonly bool $preliminaryExecutionDisabled = false,
	)
	{
		parent::__construct(tableName: $tableName);
	}

	public function isPreliminaryExecutionDisabled(): bool
	{
		return $this->preliminaryExecutionDisabled;
	}
}
