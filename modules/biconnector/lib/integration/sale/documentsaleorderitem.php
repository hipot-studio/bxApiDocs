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
use Bitrix\BIConnector\DataSource\JoinSelection;

class DocumentSaleOrderItem extends Dataset
{
	protected const FIELD_NAME_PREFIX = 'SALE_DOCUMENT_SALEORDER_ITEM_FIELD_';
	private ?JoinSelection $shipmentJoin = null;
	private ?JoinSelection $shipmentStoreJoin = null;
	private ?JoinSelection $shipmentBatchJoin = null;
	private ?string $shipmentChain = null;

	protected function getResultTableName(): string
	{
		return 'sale_document_saleorder_item';
	}

	public function getSqlTableAlias(): string
	{
		return 'SDSOI';
	}

	protected function getConnectionTableName(): string
	{
		return 'b_sale_basket';
	}

	protected function getTableDescription(): string
	{
		return $this->getMessage('SALE_DOCUMENT_SALEORDER_ITEM_TABLE');
	}

	protected function getTableDescriptionFull(): string
	{
		return $this->getMessage('SALE_DOCUMENT_SALEORDER_ITEM_TABLE_DESCRIPTION_FULL_MSGVER_1', '');
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

		if (!Loader::includeModule('catalog'))
		{
			$result->addError(new Error("Module 'catalog' is not installed"));
		}

		return $result;
	}

	protected function getFilter(): ?DatasetFilter
	{
		$shipmentJoin = $this->getShipmentJoin();
		$shipmentRealizationJoin = $this->createJoin(
			'SHIPMENT_REALIZATION',
			"INNER JOIN b_crm_shipment_realization SHIPMENT_REALIZATION ON SHIPMENT_REALIZATION.SHIPMENT_ID = {$shipmentJoin->getJoinFieldName('ID')}",
			"LEFT JOIN b_crm_shipment_realization SHIPMENT_REALIZATION ON SHIPMENT_REALIZATION.SHIPMENT_ID = {$shipmentJoin->getJoinFieldName('ID')}",
		);

		return new DatasetFilter(
			[
				'=SYSTEM' => 'N',
				'=IS_REALIZATION' => 'Y',
			],
			[
				(new StringField('SYSTEM'))
					->setName($shipmentJoin->getJoinFieldName('SYSTEM'))
					->setJoin($shipmentJoin),
				(new StringField('IS_REALIZATION'))
					->setName($shipmentRealizationJoin->getJoinFieldName('IS_REALIZATION'))
					->setJoin($shipmentRealizationJoin),
			]
		);
	}

	protected function getFields(): array
	{
		$shipmentJoin = $this->getShipmentJoin();
		$shipmentStoreJoin = $this->getShipmentStoreJoin();
		$shipmentBatchJoin = $this->getShipmentBatchJoin();
		$batchNumeratorField = $shipmentBatchJoin->getJoinFieldName('COST_NUMERATOR');
		$batchDenominatorField = $shipmentBatchJoin->getJoinFieldName('COST_DENOMINATOR');

		$ratio = "SUM({$batchNumeratorField}) / NULLIF(SUM({$batchDenominatorField}), 0)";
		$isPgsql = $this->getDataConnection() instanceof \Bitrix\Main\DB\PgsqlConnection;
		if ($isPgsql)
		{
			// PG: round(x::numeric, 4) rounds half-away-from-zero (HALF_UP), matching MySQL DECIMAL(18,4) behaviour.
			$costPriceExpression = "round(({$ratio})::numeric, 4)";
		}
		else
		{
			$costPriceExpression = "CAST({$ratio} AS DECIMAL(18,4))";
		}

		return [
			(new IntegerField('ID'))
				->setPrimary()
			,
			(new IntegerField('DOCUMENT_ID'))
				->setName($shipmentJoin->getJoinFieldName('ID'))
				->setJoin($shipmentJoin),
			(new DateTimeField('DOCUMENT_DATE_CREATE'))
				->setName($shipmentJoin->getJoinFieldName('DATE_INSERT'))
				->setJoin($shipmentJoin),
			(new IntegerField('PRODUCT_ID')),
			(new StringField('NAME')),
			(new DoubleField('PRICE')),
			(new StringField('PRICE_CURRENCY'))
				->setName($this->getAliasFieldName('CURRENCY')),
			(new DateTimeField('DATE_INSERT')),
			(new IntegerField('STORE_ID'))
				->setName($shipmentStoreJoin->getJoinFieldName('STORE_ID'))
				->setJoin($shipmentStoreJoin),
			(new DoubleField('AMOUNT'))
				->setExpression("SUM({$shipmentStoreJoin->getJoinFieldName('QUANTITY')})")
				->setJoin($shipmentStoreJoin),
			(new DoubleField('COST_PRICE'))
				->setExpression($costPriceExpression)
				->setJoin($shipmentBatchJoin),
			(new StringField('COST_CURRENCY'))
				->setExpression("MAX({$shipmentBatchJoin->getJoinFieldName('BATCH_CURRENCY')})")
				->setJoin($shipmentBatchJoin),
		];
	}

	/**
	 * Shipment chain shared by the SHIPMENT and SHIPMENT_STORE aliases.
	 *
	 * Both aliases must resolve through the same shipment item row. Reaching the store rows through a
	 * second, independent join of b_sale_order_dlv_basket pairs a document with the store rows of every
	 * other shipment of the same basket item, so a basket item shipped by several realizations reports
	 * the sum of all of them.
	 *
	 * The inner and the outer variant are deliberately the same text: GetJoins() dedupes joins by the
	 * exact SQL string but picks the variant per alias, and the dataset filter pushes SHIPMENT to the
	 * inner one while SHIPMENT_STORE stays outer. Two different texts would emit the chain twice and
	 * declare every alias in it a second time.
	 */
	private function getShipmentChain(): string
	{
		if ($this->shipmentChain === null)
		{
			$this->shipmentChain =
				"LEFT JOIN b_sale_order_dlv_basket DELIVERY_BASKET ON DELIVERY_BASKET.BASKET_ID = {$this->getAliasFieldName('ID')}
				LEFT JOIN b_sale_order_delivery SHIPMENT ON SHIPMENT.ID = DELIVERY_BASKET.ORDER_DELIVERY_ID
				LEFT JOIN b_sale_store_barcode SHIPMENT_STORE ON SHIPMENT_STORE.ORDER_DELIVERY_BASKET_ID = DELIVERY_BASKET.ID"
			;
		}

		return $this->shipmentChain;
	}

	private function getShipmentJoin(): JoinSelection
	{
		if ($this->shipmentJoin === null)
		{
			$chain = $this->getShipmentChain();
			$this->shipmentJoin = $this->createJoin('SHIPMENT', $chain, $chain);
		}

		return $this->shipmentJoin;
	}

	private function getShipmentStoreJoin(): JoinSelection
	{
		if ($this->shipmentStoreJoin === null)
		{
			$chain = $this->getShipmentChain();
			$this->shipmentStoreJoin = $this->createJoin('SHIPMENT_STORE', $chain, $chain);
		}

		return $this->shipmentStoreJoin;
	}

	private function getShipmentBatchJoin(): JoinSelection
	{
		if ($this->shipmentBatchJoin === null)
		{
			$shipmentStoreField = $this->getShipmentStoreJoin()->getJoinFieldName('ID');

			// Pre-aggregate batch rows to one row per SHIPMENT_ITEM_STORE_ID so the join
			// does not multiply SHIPMENT_STORE rows when an item is consumed from multiple FIFO lots.
			$preAggregated = "(
				SELECT
					SHIPMENT_ITEM_STORE_ID,
					SUM(-AMOUNT * BATCH_PRICE) AS COST_NUMERATOR,
					SUM(-AMOUNT) AS COST_DENOMINATOR,
					MAX(BATCH_CURRENCY) AS BATCH_CURRENCY
				FROM b_catalog_store_batch_docs_element
				GROUP BY SHIPMENT_ITEM_STORE_ID
			)";

			$this->shipmentBatchJoin = $this->createJoin(
				'SHIPMENT_BATCH',
				"LEFT JOIN {$preAggregated} SHIPMENT_BATCH ON SHIPMENT_BATCH.SHIPMENT_ITEM_STORE_ID = {$shipmentStoreField}",
				"LEFT JOIN {$preAggregated} SHIPMENT_BATCH ON SHIPMENT_BATCH.SHIPMENT_ITEM_STORE_ID = {$shipmentStoreField}"
			);
		}

		return $this->shipmentBatchJoin;
	}

	protected function getGroupBy(): array
	{
		$shipmentJoin = $this->getShipmentJoin();
		$shipmentStoreJoin = $this->getShipmentStoreJoin();

		return [
			$this->getAliasFieldName('ID'),
			$shipmentJoin->getJoinFieldName('ID'),
			$this->getAliasFieldName('PRODUCT_ID'),
			$this->getAliasFieldName('NAME'),
			$this->getAliasFieldName('PRICE'),
			$this->getAliasFieldName('CURRENCY'),
			$this->getAliasFieldName('DATE_INSERT'),
			$shipmentStoreJoin->getJoinFieldName('STORE_ID'),
		];
	}
}
