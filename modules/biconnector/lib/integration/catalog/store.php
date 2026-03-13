<?php

namespace Bitrix\BIConnector\Integration\Catalog;

use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\Error;
use Bitrix\BIConnector\DataSource\Dataset;
use Bitrix\BIConnector\DataSource\Field\DateTimeField;
use Bitrix\BIConnector\DataSource\Field\IntegerField;
use Bitrix\BIConnector\DataSource\Field\StringField;

class Store extends Dataset
{
	protected const FIELD_NAME_PREFIX = 'CATALOG_STORE_FIELD_';

	protected function getResultTableName(): string
	{
		return 'catalog_store';
	}

	public function getSqlTableAlias(): string
	{
		return 'CATS';
	}

	protected function getConnectionTableName(): string
	{
		return 'b_catalog_store';
	}

	protected function getTableDescription(): string
	{
		return $this->getMessage('CATALOG_STORE_TABLE');
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
			(new StringField('TITLE')),
			(new StringField('ACTIVE')),
			(new DateTimeField('DATE_CREATE')),
		];
	}
}
