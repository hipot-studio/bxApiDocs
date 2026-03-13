<?php

namespace Bitrix\BIConnector\Integration\Catalog;

use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\Error;
use Bitrix\BIConnector\DataSource\Dataset;
use Bitrix\BIConnector\DataSource\Field\IntegerField;
use Bitrix\BIConnector\DataSource\Field\DoubleField;

class StoreProduct extends Dataset
{
	protected const FIELD_NAME_PREFIX = 'CATALOG_STORE_PRODUCT_FIELD_';

	protected function getResultTableName(): string
	{
		return 'catalog_store_product';
	}

	public function getSqlTableAlias(): string
	{
		return 'CATSP';
	}

	protected function getConnectionTableName(): string
	{
		return 'b_catalog_store_product';
	}

	protected function getTableDescription(): string
	{
		return $this->getMessage('CATALOG_STORE_PRODUCT_TABLE');
	}

	/**
	 * @return Result
	 */
	protected function onBeforeEvent(): Result
	{
		$result = parent::onBeforeEvent();

		if (!Loader::includeModule('catalog'))
		{
			$result->addError(new Error('Module is not installed'));
		}

		return $result;
	}

	protected function getFields(): array
	{
		return [
			(new IntegerField('ID'))
				->setPrimary()
			,
			(new IntegerField('PRODUCT_ID')),
			(new IntegerField('STORE_ID')),
			(new DoubleField('AMOUNT')),
			(new DoubleField('QUANTITY_RESERVED')),
		];
	}
}
