<?php

declare(strict_types=1);

namespace Bitrix\Main\DB\Ddl\Column;

abstract class AbstractColumn implements ColumnInterface
{
	protected string $name;
	protected bool $notNull = false;
	protected ?string $defaultValue = null;

	public function __construct(string $name)
	{
		$this->name = $name;
	}

	public function getParams(): ColumnParams
	{
		return new ColumnParams(
			name: $this->name,
			notNull: $this->notNull,
			defaultValue: $this->defaultValue,
		);
	}

	public function notNull(): self
	{
		$this->notNull = true;
		return $this;
	}

	public function default(?string $defaultValue): self
	{
		$this->defaultValue = $defaultValue;
		return $this;
	}
}
