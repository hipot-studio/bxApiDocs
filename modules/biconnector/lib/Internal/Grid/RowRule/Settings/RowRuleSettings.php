<?php

namespace Bitrix\BIConnector\Internal\Grid\RowRule\Settings;

final class RowRuleSettings extends \Bitrix\Main\Grid\Settings
{
	/** @var array<string, string> */
	private array $tables = [];

	private ?array $ormFilter = null;

	/**
	 * @param array<string, string> $tables Table code => name, for the filter by table.
	 */
	public function setTables(array $tables): self
	{
		$this->tables = $tables;

		return $this;
	}

	/**
	 * @return array<string, string>
	 */
	public function getTables(): array
	{
		return $this->tables;
	}

	public function setOrmFilter(?array $filter): void
	{
		$this->ormFilter = $filter;
	}

	/**
	 * The filter the author cells are marked by: a click on a marked author takes them out of it.
	 */
	public function getOrmFilter(): ?array
	{
		return $this->ormFilter;
	}
}
