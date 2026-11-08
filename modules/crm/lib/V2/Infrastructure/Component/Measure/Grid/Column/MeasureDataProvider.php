<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Component\Measure\Grid\Column;

use Bitrix\Main\Grid\Column\DataProvider;
use Bitrix\Main\Grid\Column\Type;
use Bitrix\Main\Localization\Loc;

/**
 * @internal
 */
final class MeasureDataProvider extends DataProvider
{
	private const MESSAGE_KEYS = [
		'ID' => 'CRM_COLUMN_ID',
		'CODE' => 'CRM_COLUMN_CODE',
		'MEASURE_TITLE' => 'CRM_COLUMN_MEASURE_TITLE',
		'SYMBOL_RUS' => 'CRM_COLUMN_SYMBOL_RUS',
		'SYMBOL_INTL' => 'CRM_COLUMN_SYMBOL_INTL',
		'SYMBOL_LETTER_INTL' => 'CRM_COLUMN_SYMBOL_LETTER_INTL',
		'IS_DEFAULT' => 'CRM_COLUMN_IS_DEFAULT',
	];

	public function prepareColumns(): array
	{
		return [
			$this->createColumn('ID')
				->setType(Type::INT)
				->setName($this->getColumnName('ID'))
				->setTitle($this->getColumnName('ID'))
				->setSort('ID')
				->setDefault(true)
				->setNecessary(true)
			,
			$this->createColumn('CODE')
				->setType(Type::INT)
				->setName($this->getColumnName('CODE'))
				->setTitle($this->getColumnName('CODE'))
				->setSort('CODE')
				->setDefault(true)
			,
			$this->createColumn('MEASURE_TITLE')
				->setType(Type::TEXT)
				->setName($this->getColumnName('MEASURE_TITLE'))
				->setTitle($this->getColumnName('MEASURE_TITLE'))
				->setSort('MEASURE_TITLE')
				->setDefault(true)
				->setEditable(true)
			,
			$this->createColumn('SYMBOL_RUS')
				->setType(Type::TEXT)
				->setName($this->getColumnName('SYMBOL_RUS'))
				->setTitle($this->getColumnName('SYMBOL_RUS'))
				->setSort('SYMBOL_RUS')
				->setDefault(true)
				->setEditable(true)
			,
			$this->createColumn('SYMBOL_INTL')
				->setType(Type::TEXT)
				->setName($this->getColumnName('SYMBOL_INTL'))
				->setTitle($this->getColumnName('SYMBOL_INTL'))
				->setSort('SYMBOL_INTL')
				->setDefault(true)
				->setEditable(true)
			,
			$this->createColumn('SYMBOL_LETTER_INTL')
				->setType(Type::TEXT)
				->setName($this->getColumnName('SYMBOL_LETTER_INTL'))
				->setTitle($this->getColumnName('SYMBOL_LETTER_INTL'))
				->setSort('SYMBOL_LETTER_INTL')
				->setDefault(false)
				->setEditable(true)
			,
			$this->createColumn('IS_DEFAULT')
				->setType(Type::TEXT)
				->setName($this->getColumnName('IS_DEFAULT'))
				->setTitle($this->getColumnName('IS_DEFAULT'))
				->setSort('IS_DEFAULT')
				->setDefault(true)
			,
		];
	}

	private function getColumnName(string $columnId): string
	{
		return (string)Loc::getMessage(self::MESSAGE_KEYS[$columnId]);
	}
}
