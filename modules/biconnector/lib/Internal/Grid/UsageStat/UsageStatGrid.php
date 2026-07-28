<?php

namespace Bitrix\BIConnector\Internal\Grid\UsageStat;

use Bitrix\BIConnector\Internal\Grid\UsageStat\Column\Provider\UsageStatDataProvider;
use Bitrix\BIConnector\Internal\Grid\UsageStat\Filter\UsageStatFilterProvider;
use Bitrix\BIConnector\Internal\Grid\UsageStat\Row\Assembler\UsageStatRowAssembler;
use Bitrix\BIConnector\Internal\Grid\UsageStat\Settings\UsageStatSettings;
use Bitrix\Main\Filter\Filter;
use Bitrix\Main\Grid\Column\Columns;
use Bitrix\Main\Grid\Grid;
use Bitrix\Main\Grid\Row\Rows;

/**
 * @method UsageStatSettings getSettings()
 */
final class UsageStatGrid extends Grid
{
	public function __construct(UsageStatSettings $settings)
	{
		parent::__construct($settings);

		$this->getSettings()->setOrmFilter($this->getOrmFilter());
	}

	protected function createColumns(): Columns
	{
		return new Columns(
			new UsageStatDataProvider($this->getSettings()),
		);
	}

	protected function createRows(): Rows
	{
		$rowAssembler = new UsageStatRowAssembler(
			$this->getVisibleColumnsIds(),
			$this->getSettings(),
		);

		return new Rows($rowAssembler);
	}

	protected function createFilter(): ?Filter
	{
		return new Filter(
			$this->getId(),
			new UsageStatFilterProvider(
				$this->getSettings(),
			),
		);
	}

	protected function getDefaultSorting(): array
	{
		return ['ID' => 'desc'];
	}

	public function getOrmOrder(): array
	{
		return parent::getOrmOrder() ?: $this->getDefaultSorting();
	}
}
