<?php

namespace Bitrix\BIConnector\Superset\Grid;


use Bitrix\BIConnector\Superset\Grid\Row\Assembler\ExternalSourceRowAssembler;
use Bitrix\BIConnector\Superset\Grid\Settings\ExternalSourceSettings;
use Bitrix\Main\Filter\Filter;
use Bitrix\Main\Filter\Settings;
use Bitrix\Main\Grid\Column\Columns;
use Bitrix\Main\Grid\Grid;
use Bitrix\Main\Grid\Row\Rows;

/**
 * @method ExternalSourceSettings getSettings()
 */
final class ExternalSourceGrid extends Grid
{
	public function __construct(\Bitrix\Main\Grid\Settings $settings)
	{
		parent::__construct($settings);
		$this->getSettings()->setOrmFilter($this->getOrmFilter());
	}

	protected function createColumns(): Columns
	{
		return new Columns(
			new \Bitrix\BIConnector\Superset\Grid\Column\Provider\ExternalSourceDataProvider()
		);
	}

	protected function createRows(): Rows
	{
		$rowAssembler = new ExternalSourceRowAssembler(
			$this->getVisibleColumnsIds(),
			$this->getSettings()
		);

		return new Rows(
			$rowAssembler,
			new \Bitrix\BIConnector\Superset\Grid\Row\Action\Source\ExternalSourceActionDataProvider($this->getSettings())
		);
	}

	public function getOrmParams(): array
	{
		$ormParams = parent::getOrmParams();

		$requiredFields = ['ID', 'TYPE', 'ACTIVE', 'MODULE'];
		foreach ($requiredFields as $field)
		{
			if (!in_array($field, $ormParams['select'], true))
			{
				$ormParams['select'][] = $field;
			}
		}

		return $ormParams;
	}

	protected function createFilter(): ?Filter
	{
		return new Filter(
			$this->getId(),
			new \Bitrix\BiConnector\Superset\Filter\Provider\ExternalSourceDataProvider(
				new Settings(['ID' => $this->getId()])
			),
		);
	}
}
