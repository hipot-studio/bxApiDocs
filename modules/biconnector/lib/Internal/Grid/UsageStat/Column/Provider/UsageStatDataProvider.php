<?php

namespace Bitrix\BIConnector\Internal\Grid\UsageStat\Column\Provider;

use Bitrix\BIConnector\Internal\Grid\UsageStat\Settings\UsageStatSettings;
use Bitrix\Main\Grid\Column\DataProvider;
use Bitrix\Main\Grid\Column\Type;
use Bitrix\Main\Localization\Loc;

final class UsageStatDataProvider extends DataProvider
{
	public function __construct(?UsageStatSettings $settings = null)
	{
		parent::__construct($settings);
	}

	public function prepareColumns(): array
	{
		$result = [];

		$result[] =
			$this->createColumn('ID')
				->setType(Type::TEXT)
				->setName(Loc::getMessage('BIC_USAGE_STAT_GRID_COLUMN_TITLE_ID'))
				->setDefault(true)
				->setSort('ID')
		;

		$result[] =
			$this->createColumn('TIMESTAMP_X')
				->setType(Type::DATE)
				->setName(Loc::getMessage('BIC_USAGE_STAT_GRID_COLUMN_TITLE_TIMESTAMP_X'))
				->setDefault(true)
				->setSort('TIMESTAMP_X')
		;

		/** @var UsageStatSettings|null $settings */
		$settings = $this->getSettings();
		$isBiBuilder = $settings?->isBiBuilderService() ?? false;

		if ($isBiBuilder)
		{
			$result[] =
				$this->createColumn('EXTERNAL_DASHBOARD')
					->setType(Type::TEXT)
					->setName(Loc::getMessage('BIC_USAGE_STAT_GRID_COLUMN_TITLE_DASHBOARD'))
					->setDefault(true)
			;

			$result[] =
				$this->createColumn('EXTERNAL_CHART')
					->setType(Type::TEXT)
					->setName(Loc::getMessage('BIC_USAGE_STAT_GRID_COLUMN_TITLE_CHART'))
					->setDefault(true)
			;

			$result[] =
				$this->createColumn('EXTERNAL_DATASET')
					->setType(Type::TEXT)
					->setName(Loc::getMessage('BIC_USAGE_STAT_GRID_COLUMN_TITLE_DATASET'))
					->setDefault(true)
			;
		}
		else
		{
			$result[] =
				$this->createColumn('KEY_ID')
					->setType(Type::TEXT)
					->setName(Loc::getMessage('BIC_USAGE_STAT_GRID_COLUMN_TITLE_ACCESS_KEY'))
					->setDefault(true)
			;

			$result[] =
				$this->createColumn('SERVICE_ID')
					->setType(Type::TEXT)
					->setName(Loc::getMessage('BIC_USAGE_STAT_GRID_COLUMN_TITLE_SERVICE_ID'))
					->setDefault(true)
			;
		}

		$result[] =
			$this->createColumn('SOURCE_ID')
				->setType(Type::TEXT)
				->setName(Loc::getMessage('BIC_USAGE_STAT_GRID_COLUMN_TITLE_SOURCE_ID'))
				->setDefault(true)
		;

		$result[] =
			$this->createColumn('ROW_NUM')
				->setType(Type::TEXT)
				->setName(Loc::getMessage('BIC_USAGE_STAT_GRID_COLUMN_TITLE_ROW_NUM'))
				->setDefault(true)
				->setAlign('right')
				->setSort('ROW_NUM')
		;

		$result[] =
			$this->createColumn('DATA_SIZE')
				->setType(Type::TEXT)
				->setName(Loc::getMessage('BIC_USAGE_STAT_GRID_COLUMN_TITLE_DATA_SIZE'))
				->setDefault(true)
				->setSort('DATA_SIZE')
				->setAlign('right')
		;

		$result[] =
			$this->createColumn('REAL_TIME')
				->setType(Type::TEXT)
				->setName(Loc::getMessage('BIC_USAGE_STAT_GRID_COLUMN_TITLE_REAL_TIME'))
				->setDefault(true)
				->setSort('REAL_TIME')
				->setAlign('right')
		;

		$result[] =
			$this->createColumn('LOAD_LEVEL')
				->setType(Type::TEXT)
				->setName(Loc::getMessage('BIC_USAGE_STAT_GRID_COLUMN_TITLE_LOAD_LEVEL'))
				->setDefault(true)
				->setSort('LOAD_LEVEL')
		;

		$result[] =
			$this->createColumn('FILTERS')
				->setType(Type::TEXT)
				->setName(Loc::getMessage('BIC_USAGE_STAT_GRID_COLUMN_TITLE_FILTERS'))
				->setDefault(false)
		;

		$result[] =
			$this->createColumn('FIELDS')
				->setType(Type::TEXT)
				->setName(Loc::getMessage('BIC_USAGE_STAT_GRID_COLUMN_TITLE_FIELDS'))
				->setDefault(false)
		;

		$result[] =
			$this->createColumn('DATASET_TYPE')
				->setType(Type::TEXT)
				->setName(Loc::getMessage('BIC_USAGE_STAT_GRID_COLUMN_TITLE_DATASET_TYPE'))
				->setDefault(true)
		;

		return $result;
	}
}
