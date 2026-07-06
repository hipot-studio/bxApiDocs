<?php

declare(strict_types=1);

namespace Bitrix\Main\DB\Ddl\Column;

abstract class AbstractFloatColumn extends AbstractColumn
{
	private ?int $mLength;
	private ?int $eLength;

	public function __construct(string $name, ?int $mLength = null, ?int $eLength = null)
	{
		parent::__construct($name);
		$this->mLength = $mLength;
		$this->eLength = $eLength;
	}

	public function getParams(): ColumnParams
	{
		return new ColumnParams(
			name: $this->name,
			notNull: $this->notNull,
			defaultValue: $this->defaultValue,
			mantissaLength: $this->mLength,
			exponentLength: $this->eLength,
		);
	}
}
