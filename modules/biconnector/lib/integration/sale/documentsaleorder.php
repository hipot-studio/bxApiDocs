<?php

namespace Bitrix\BIConnector\Integration\Sale;

use Bitrix\BIConnector\DataSource\DatasetFilter;
use Bitrix\BIConnector\DataSource\Field\DateTimeField;
use Bitrix\BIConnector\DataSource\Field\DoubleField;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\Error;
use Bitrix\BIConnector\DataSource\Dataset;
use Bitrix\BIConnector\DataSource\Field\IntegerField;
use Bitrix\BIConnector\DataSource\Field\StringField;

class DocumentSaleOrder extends Dataset
{
	protected const FIELD_NAME_PREFIX = 'SALE_DOCUMENT_SALEORDER_FIELD_';

	protected function getResultTableName(): string
	{
		return 'sale_document_saleorder';
	}

	public function getSqlTableAlias(): string
	{
		return 'SDSO';
	}

	protected function getConnectionTableName(): string
	{
		return 'b_sale_order_delivery';
	}

	protected function getTableDescription(): string
	{
		return $this->getMessage('SALE_DOCUMENT_SALEORDER_TABLE');
	}

	/**
	 * @return Result
	 */
	protected function onBeforeEvent(): Result
	{
		$result = parent::onBeforeEvent();

		if (!Loader::includeModule('sale'))
		{
			$result->addError(new Error("Module 'sale' is not installed"));
		}

		if (!Loader::includeModule('crm'))
		{
			$result->addError(new Error("Module 'crm' is not installed"));
		}

		return $result;
	}

	protected function getFilter(): ?DatasetFilter
	{
		$shipmentRealizationJoin = $this->createJoin(
			'SHIPMENT_REALIZATION',
			"INNER JOIN b_crm_shipment_realization SHIPMENT_REALIZATION ON SHIPMENT_REALIZATION.SHIPMENT_ID = {$this->getAliasFieldName('ID')}",
			"LEFT JOIN b_crm_shipment_realization SHIPMENT_REALIZATION ON SHIPMENT_REALIZATION.SHIPMENT_ID = {$this->getAliasFieldName('ID')}",
		);

		return new DatasetFilter(
			[
				'=SYSTEM' => 'N',
				'=IS_REALIZATION' => 'Y',
			],
			[
				(new StringField('IS_REALIZATION'))
					->setName($shipmentRealizationJoin->getJoinFieldName('IS_REALIZATION'))
					->setJoin($shipmentRealizationJoin),
				(new StringField('SYSTEM')),
			]
		);
	}

	protected function getFields(): array
	{
		$userJoin = $this->createJoin(
			'USER',
			"INNER JOIN b_user USER ON USER.ID = {$this->getAliasFieldName('RESPONSIBLE_ID')}",
			"LEFT JOIN b_user USER ON USER.ID = {$this->getAliasFieldName('RESPONSIBLE_ID')}"
		);

		return [
			(new IntegerField('ID'))
				->setPrimary()
			,
			(new DateTimeField('DATE_CREATE'))
				->setName('DATE_INSERT'),
			(new DateTimeField('DATE_UPDATE')),
			(new DoubleField('PRICE_DELIVERY')),
			(new StringField('DEDUCTED')),
			(new DateTimeField('DATE_DEDUCTED')),
			(new StringField('DELIVERY_NAME')),
			(new StringField('WAS_CANCELLED'))
				->setName('CANCELED'),
			(new StringField('CURRENCY')),
			(new IntegerField('RESPONSIBLE_ID')),
			(new StringField('RESPONSIBLE_NAME'))
				->setName("
					if(
						{$this->getAliasFieldName('RESPONSIBLE_ID')} > 0,
						concat_ws(
							' ', 
							nullif({$userJoin->getJoinFieldName('NAME')}, ''), 
							nullif({$userJoin->getJoinFieldName('LAST_NAME')}, '')
						),
						NULL
					)"
				)
				->setJoin($userJoin),
			(new StringField('RESPONSIBLE'))
				->setName("
					if(
						{$this->getAliasFieldName('RESPONSIBLE_ID')} > 0,
						concat_ws(
							' ',
							concat('[', {$this->getAliasFieldName('RESPONSIBLE_ID')}, ']'), 
							nullif({$userJoin->getJoinFieldName('NAME')}, ''), 
							nullif({$userJoin->getJoinFieldName('LAST_NAME')}, '')
						),
						NULL
					)"
				)
				->setJoin($userJoin),
		];
	}
}
