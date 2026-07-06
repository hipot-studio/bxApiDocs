<?php

declare(strict_types=1);

namespace Bitrix\Main\DB\Ddl\Column;

class VarcharColumn extends AbstractColumn
{
	private int $length;

	public function __construct(string $name, int $length)
	{
		parent::__construct($name);
		$this->length = $length;
	}

	public function getParams(): ColumnParams
	{
		return new ColumnParams(
			name: $this->name,
			notNull: $this->notNull,
			defaultValue: $this->defaultValue,
			length: $this->length,
		);
	}
}
