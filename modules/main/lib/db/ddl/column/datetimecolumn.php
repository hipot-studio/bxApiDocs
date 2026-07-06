<?php

declare(strict_types=1);

namespace Bitrix\Main\DB\Ddl\Column;

class DateTimeColumn extends AbstractColumn
{
	private bool $currentTimestampAsDefault = false;
	private bool $currentTimestampOnUpdate = false;

	public function defaultCurrentTimestamp(): self
	{
		$this->currentTimestampAsDefault = true;
		return $this;
	}

	public function currentTimestampOnUpdate(): self
	{
		$this->currentTimestampOnUpdate = true;
		return $this;
	}

	public function getParams(): ColumnParams
	{
		return new ColumnParams(
			name: $this->name,
			notNull: $this->notNull,
			defaultValue: $this->defaultValue,
			currentTimestampAsDefault: $this->currentTimestampAsDefault,
			currentTimestampOnUpdate: $this->currentTimestampOnUpdate,
		);
	}
}
