<?php

namespace Bitrix\BIConnector\Integration\Catalog;

use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\Error;
use Bitrix\BIConnector\DataSource\Dataset;
use Bitrix\BIConnector\DataSource\Field\IntegerField;
use Bitrix\BIConnector\DataSource\Field\DateTimeField;
use Bitrix\BIConnector\DataSource\Field\DoubleField;

class StoreDocumentItem extends Dataset
{
	protected const FIELD_NAME_PREFIX = 'CATALOG_STORE_DOCUMENT_ITEM_FIELD_';

	protected function getResultTableName(): string
	{
		return 'catalog_store_document_item';
	}

	public function getSqlTableAlias(): string
	{
		return 'CATDOCEL';
	}

	protected function getConnectionTableName(): string
	{
		return 'b_catalog_docs_element';
	}

	protected function getTableDescription(): string
	{
		return $this->getMessage('CATALOG_STORE_DOCUMENT_ITEM_TABLE');
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
		$documentJoin = $this->createJoin(
			"CATSDOC",
			"INNER JOIN b_catalog_store_docs CATSDOC ON CATSDOC.ID = {$this->getAliasFieldName('DOC_ID')}",
			"LEFT JOIN b_catalog_store_docs CATSDOC ON CATSDOC.ID = {$this->getAliasFieldName('DOC_ID')}",
		);

		return [
			(new IntegerField('ID'))
				->setPrimary()
			,
			(new IntegerField('DOCUMENT_ID'))
				->setName($this->getAliasFieldName('DOC_ID'))
			,
			(new DateTimeField('DOCUMENT_DATE_CREATE'))
				->setName($documentJoin->getJoinFieldName('DATE_CREATE'))
				->setJoin($documentJoin)
			,
			(new IntegerField('PRODUCT_ID'))
				->setName($this->getAliasFieldName('ELEMENT_ID'))
			,
			(new IntegerField('STORE_FROM')),
			(new IntegerField('STORE_TO')),
			(new DoubleField('AMOUNT')),
			(new DoubleField('PURCHASING_PRICE')),
			(new DoubleField('PRICE'))
				->setName($this->getAliasFieldName('BASE_PRICE'))
			,
		];
	}
}
