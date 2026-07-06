<?php

declare(strict_types=1);

namespace Bitrix\Main\DB\Ddl\Column;

abstract class AbstractIntColumn extends AbstractColumn
{
	protected ?int $size;
	protected bool $autoincrement = false;
	protected bool $unsigned = false;

	public function __construct(string $name, ?int $size = null)
	{
		parent::__construct($name);
		$this->size = $size;
	}

	public function autoincrement(): self
	{
		$this->autoincrement = true;
		return $this;
	}

	public function unsigned(): self
	{
		$this->unsigned = true;
		return $this;
	}

	public function getParams(): ColumnParams
	{
		return new ColumnParams(
			name: $this->name,
			notNull: $this->notNull,
			defaultValue: $this->defaultValue,
			size: $this->size,
			unsigned: $this->unsigned,
			autoincrement: $this->autoincrement,
		);
	}
}
