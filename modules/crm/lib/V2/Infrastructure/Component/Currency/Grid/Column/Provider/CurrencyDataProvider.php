<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Component\Currency\Grid\Column\Provider;

use Bitrix\Main\Grid\Column\DataProvider;
use Bitrix\Main\Grid\Column\Type;

final class CurrencyDataProvider extends DataProvider
{
	/**
	 * @param array<string, string> $columnNames
	 */
	public function __construct(
		private readonly array $columnNames = [],
	)
	{
		parent::__construct();
	}

	public function prepareColumns(): array
	{
		return [
			$this->createColumn('ID')
				->setType(Type::TEXT)
				->setName($this->getColumnName('ID'))
				->setTitle($this->getColumnName('ID'))
				->setSort('CURRENCY')
				->setSelect(['CURRENCY'])
				->setDefault(true)
				->setNecessary(true)
			,
			$this->createColumn('NAME')
				->setType(Type::HTML)
				->setName($this->getColumnName('NAME'))
				->setTitle($this->getColumnName('NAME'))
				->setSort('CURRENT_LANG_FORMAT.FULL_NAME')
				->setSelect(['FULL_NAME'])
				->setDefault(true)
			,
			$this->createColumn('SORT')
				->setType(Type::INT)
				->setName($this->getColumnName('SORT'))
				->setTitle($this->getColumnName('SORT'))
				->setSort('SORT')
				->setDefault(true)
				->setEditable(true)
			,
			$this->createColumn('EXCH_RATE')
				->setType(Type::FLOAT)
				->setName($this->getColumnName('EXCH_RATE'))
				->setTitle($this->getColumnName('EXCH_RATE'))
				->setSelect(['AMOUNT'])
				->setDefault(true)
				->setEditable(true)
			,
			$this->createColumn('AMOUNT_CNT')
				->setType(Type::INT)
				->setName($this->getColumnName('AMOUNT_CNT'))
				->setTitle($this->getColumnName('AMOUNT_CNT'))
				->setDefault(true)
				->setEditable(true)
			,
			$this->createColumn('BASE')
				->setType(Type::CHECKBOX)
				->setName($this->getColumnName('BASE'))
				->setTitle($this->getColumnName('BASE'))
				->setDefault(true)
			,
			$this->createColumn('ACCOUNTING')
				->setType(Type::CHECKBOX)
				->setName($this->getColumnName('ACCOUNTING'))
				->setTitle($this->getColumnName('ACCOUNTING'))
				->setSelect(['CURRENCY'])
				->setDefault(true)
				->setEditable(true)
			,
		];
	}

	private function getColumnName(string $columnId): string
	{
		return $this->columnNames[$columnId] ?? $columnId;
	}
}
