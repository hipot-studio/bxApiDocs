<?php

namespace Bitrix\BIConnector\Integration\Catalog;

use Bitrix\BIConnector\DataSource\Field\DateTimeField;
use Bitrix\BIConnector\DataSource\Field\StringField;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\Error;
use Bitrix\BIConnector\DataSource\Dataset;
use Bitrix\BIConnector\DataSource\Field\IntegerField;
use Bitrix\BIConnector\DataSource\Field\DoubleField;
use Bitrix\Catalog\StoreDocumentTable;

class StoreDocument extends Dataset
{
	protected const FIELD_NAME_PREFIX = 'CATALOG_STORE_DOCUMENT_FIELD_';

	protected function getResultTableName(): string
	{
		return 'catalog_store_document';
	}

	public function getSqlTableAlias(): string
	{
		return 'CATSDOC';
	}

	protected function getConnectionTableName(): string
	{
		return 'b_catalog_store_docs';
	}

	protected function getTableDescription(): string
	{
		return $this->getMessage('CATALOG_STORE_DOCUMENT_TABLE');
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
		$responsibleJoin = $this->createJoin(
			"CATSDOC_RESPONSIBLE",
			"INNER JOIN b_user CATSDOC_RESPONSIBLE ON CATSDOC_RESPONSIBLE.ID = {$this->getAliasFieldName('RESPONSIBLE_ID')}",
			"LEFT JOIN b_user CATSDOC_RESPONSIBLE ON CATSDOC_RESPONSIBLE.ID = {$this->getAliasFieldName('RESPONSIBLE_ID')}",
		);

		return [
			(new IntegerField('ID'))
				->setPrimary()
			,
			(new StringField('TITLE')),
			(new StringField('DOC_TYPE'))
				->setCallback(
					static function($type) {
						if (empty($type))
						{
							return '';
						}

						return StoreDocumentTable::getTypeList(true)[$type] ?? $type;
					},
				)
			,
			(new StringField('DOC_TYPE_CODE'))
				->setName($this->getAliasFieldName('DOC_TYPE'))
			,
			(new DateTimeField('DATE_CREATE')),
			(new DateTimeField('DATE_MODIFY')),
			(new StringField('STATUS'))
				->setCallback(
					static function($status) {
						if (empty($status))
						{
							return '';
						}

						return StoreDocumentTable::getStatusName($status) ?? $status;
					},
				)
			,
			(new StringField('STATUS_CODE'))
				->setName($this->getAliasFieldName('STATUS'))
			,
			(new DoubleField('TOTAL')),
			(new StringField('CURRENCY')),
			(new IntegerField('RESPONSIBLE_ID')),
			(new StringField('RESPONSIBLE_NAME'))
				->setName("
					if(
						{$this->getAliasFieldName('RESPONSIBLE_ID')} > 0,
						concat_ws(
							' ', 
							nullif({$responsibleJoin->getJoinFieldName('NAME')}, ''), 
							nullif({$responsibleJoin->getJoinFieldName('LAST_NAME')}, '')
						),
						NULL
					)",
				)
				->setJoin($responsibleJoin)
			,
			(new StringField('RESPONSIBLE'))
				->setName("
					if(
						{$this->getAliasFieldName('RESPONSIBLE_ID')} > 0,
						concat_ws(
							' ', 
							concat('[', {$this->getAliasFieldName('RESPONSIBLE_ID')}, ']'), 
							nullif({$responsibleJoin->getJoinFieldName('NAME')}, ''), 
							nullif({$responsibleJoin->getJoinFieldName('LAST_NAME')}, '')
						),
						NULL
					)",
				)
				->setJoin($responsibleJoin)
			,
			(new StringField('WAS_CANCELLED')),
		];
	}
}
