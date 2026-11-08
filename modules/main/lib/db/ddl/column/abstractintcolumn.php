<?php

declare(strict_types=1);

namespace Bitrix\Main\DB\Ddl\Column;

abstract class AbstractIntColumn extends AbstractColumn
{
	protected ?int $size;
	protected bool $autoincrement = false;
	protected int|\Closure|null $autoincrementStartWith = null;
	protected bool $unsigned = false;

	public function __construct(string $name, ?int $size = null)
	{
		parent::__construct($name);
		$this->size = $size;
	}

	/**
	 * @param int|\Closure|null $startWith Lower bound for the counter: the next generated id will not
	 *    be below this value (raising only — the counter is never lowered). A closure `fn(): ?int` is
	 *    resolved right before execution against the live database (null result leaves the counter
	 *    as is) and is allowed only in an alter() block with disablePreliminaryExecution() — the
	 *    preliminary pipeline cannot collect or time a computed value. Static integers work anywhere.
	 */
	public function autoincrement(int|\Closure|null $startWith = null): static
	{
		$this->autoincrement = true;
		$this->autoincrementStartWith = $startWith;
		return $this;
	}

	public function unsigned(): static
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
			autoincrementStartWith: $this->autoincrementStartWith,
		);
	}
}
