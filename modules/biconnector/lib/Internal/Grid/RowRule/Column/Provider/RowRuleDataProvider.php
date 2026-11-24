<?php

namespace Bitrix\BIConnector\Internal\Grid\RowRule\Column\Provider;

use Bitrix\BIConnector\Internal\Grid\RowRule\Settings\RowRuleSettings;
use Bitrix\Main\Grid\Column\DataProvider;
use Bitrix\Main\Grid\Column\Type;
use Bitrix\Main\Localization\Loc;

final class RowRuleDataProvider extends DataProvider
{
	public const COLUMN_IDS = [
		'TABLE',
		'FIELD',
		'CONDITION',
		'VALUES',
		'DESCRIPTION',
		'CREATED_BY_ID',
		'DATE_CREATE',
		'UPDATED_BY_ID',
		'DATE_MODIFY',
		'IS_USED',
	];

	/** Sort keys of the columns; the rule provider orders only by them. */
	public const SORT_TABLE = 'TABLE_CODE';
	public const SORT_DATE_CREATE = 'DATE_CREATE';
	public const SORT_DATE_MODIFY = 'DATE_MODIFY';

	public function __construct(?RowRuleSettings $settings = null)
	{
		parent::__construct($settings);
	}

	public function prepareColumns(): array
	{
		return [
			$this->createColumn('TABLE')
				->setType(Type::TEXT)
				->setName(Loc::getMessage('BICONNECTOR_ROW_RULE_GRID_COLUMN_TABLE'))
				->setDefault(true)
				->setSort(self::SORT_TABLE)
				->setWidth(220)
			,
			$this->createColumn('FIELD')
				->setType(Type::TEXT)
				->setName(Loc::getMessage('BICONNECTOR_ROW_RULE_GRID_COLUMN_FIELD'))
				->setDefault(true)
				->setWidth(200)
			,
			$this->createColumn('CONDITION')
				->setType(Type::TEXT)
				->setName(Loc::getMessage('BICONNECTOR_ROW_RULE_GRID_COLUMN_CONDITION'))
				->setDefault(true)
				->setWidth(160)
			,
			$this->createColumn('VALUES')
				->setType(Type::TEXT)
				->setName(Loc::getMessage('BICONNECTOR_ROW_RULE_GRID_COLUMN_VALUES'))
				->setDefault(true)
				->setWidth(260)
			,
			$this->createColumn('DESCRIPTION')
				->setType(Type::TEXT)
				->setName(Loc::getMessage('BICONNECTOR_ROW_RULE_GRID_COLUMN_DESCRIPTION'))
				->setDefault(true)
				->setWidth(260)
			,
			$this->createColumn('CREATED_BY_ID')
				->setType(Type::TEXT)
				->setName(Loc::getMessage('BICONNECTOR_ROW_RULE_GRID_COLUMN_AUTHOR'))
				->setDefault(true)
				->setWidth(200)
			,
			$this->createColumn('DATE_CREATE')
				->setType(Type::DATE)
				->setName(Loc::getMessage('BICONNECTOR_ROW_RULE_GRID_COLUMN_DATE_CREATE'))
				->setDefault(true)
				->setSort(self::SORT_DATE_CREATE)
				->setWidth(160)
			,
			$this->createColumn('UPDATED_BY_ID')
				->setType(Type::TEXT)
				->setName(Loc::getMessage('BICONNECTOR_ROW_RULE_GRID_COLUMN_UPDATED_BY'))
				->setDefault(true)
				->setWidth(200)
			,
			$this->createColumn('DATE_MODIFY')
				->setType(Type::DATE)
				->setName(Loc::getMessage('BICONNECTOR_ROW_RULE_GRID_COLUMN_DATE_MODIFY'))
				->setDefault(true)
				->setSort(self::SORT_DATE_MODIFY)
				->setWidth(160)
			,
			$this->createColumn('IS_USED')
				->setType(Type::TEXT)
				->setName(Loc::getMessage('BICONNECTOR_ROW_RULE_GRID_COLUMN_IS_USED'))
				->setDefault(true)
				->setWidth(140)
			,
		];
	}
}
