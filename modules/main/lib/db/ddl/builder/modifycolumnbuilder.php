<?php

declare(strict_types=1);

namespace Bitrix\Main\DB\Ddl\Builder;

class ModifyColumnBuilder
{
	use ColumnsTrait;

	public function __construct(
		protected readonly string $tableName,
	)
	{
	}
}
