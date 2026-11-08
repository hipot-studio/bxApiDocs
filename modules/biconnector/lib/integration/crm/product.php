<?php
namespace Bitrix\BIConnector\Integration\Crm;

use Bitrix\Main\Localization\Loc;

class Product
{
	private const ELEMENT_INNER_JOIN = 'INNER JOIN b_iblock_element IE ON IE.ID = P.ID';
	private const ELEMENT_LEFT_JOIN = 'LEFT JOIN b_iblock_element IE ON IE.ID = P.ID';
	private const SECTION_ELEMENT_JOIN = 'LEFT JOIN b_iblock_element IESEC ON IESEC.ID = P.ID';

	/**
	 *
	 * @param \Bitrix\Main\Event $event Event data.
	 *
	 * @return void
	 */
	public static function onBIConnectorDataSources(\Bitrix\Main\Event $event)
	{
		if (
			!\Bitrix\Main\Loader::includeModule('crm')
			|| !\Bitrix\Main\Loader::includeModule('catalog')
		)
		{
			return;
		}

		$crmCatalogIblockId = \Bitrix\Crm\Product\Catalog::getDefaultId();
		$crmCatalogIblockOfferId = \Bitrix\Crm\Product\Catalog::getDefaultOfferId();
		if (!$crmCatalogIblockId)
		{
			return;
		}
		$basePriceId = \Bitrix\Catalog\GroupTable::getBasePriceTypeId();

		$params = $event->getParameters();
		$manager = $params[0];
		$result = &$params[1];
		$languageId = $params[2];

		$eventTableName = $params[3];
		if (!empty($eventTableName) && $eventTableName !== 'crm_product')
		{
			return;
		}

		$connection = $manager->getDatabaseConnection();
		/** @var \Bitrix\Main\DB\SqlHelper&\Bitrix\BIConnector\DB\BiSqlHelperInterface $helper */
		$helper = $connection->getSqlHelper();
		$skuBinding = self::getSkuBinding($crmCatalogIblockOfferId);
		$sectionSource = self::getSectionSource($helper, $skuBinding, $crmCatalogIblockId);

		$result['crm_product'] = [
			'TABLE_NAME' => 'b_catalog_product',
			'TABLE_ALIAS' => 'P',
			'FILTER' => [
				'=IBLOCK_ID' => [$crmCatalogIblockId, $crmCatalogIblockOfferId],
			],
			'FILTER_FIELDS' => [
				'IBLOCK_ID' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'IE.IBLOCK_ID',
					'FIELD_TYPE' => 'int',
					'TABLE_ALIAS' => 'IE',
					'JOIN' => self::ELEMENT_INNER_JOIN,
					'LEFT_JOIN' => self::ELEMENT_LEFT_JOIN,
				],
			],
			'FIELDS' => [
				'ID' => [
					'IS_PRIMARY' => 'Y',
					'FIELD_NAME' => 'P.ID',
					'FIELD_TYPE' => 'int',
				],
				'NAME' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'IE.NAME',
					'FIELD_TYPE' => 'string',
					'TABLE_ALIAS' => 'IE',
					'JOIN' => self::ELEMENT_INNER_JOIN,
					'LEFT_JOIN' => self::ELEMENT_LEFT_JOIN,
				],
				'TYPE' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'CASE ' . self::getTypeCases() . 'END',
					'FIELD_TYPE' => 'string',
				],
				'PARENT_ID' => self::getParentIdDescription($skuBinding),
				'SECTION_ID' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => $sectionSource['ID_EXPRESSION'],
					'FIELD_TYPE' => 'int',
					'TABLE_ALIAS' => 'IESEC',
					'JOIN' => $sectionSource['JOINS'],
					'LEFT_JOIN' => $sectionSource['JOINS'],
				],
				'SECTION_NAME' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'ISEC.NAME',
					'FIELD_TYPE' => 'string',
					'TABLE_ALIAS' => 'ISEC',
					'JOIN' => $sectionSource['NAME_JOINS'],
					'LEFT_JOIN' => $sectionSource['NAME_JOINS'],
				],
				// Joined fields declare their own alias: CSQLWhere::GetQuery() keeps the join mode per alias,
				// so fields sharing an empty alias would decide it for each other.
				'MEASURE' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'CASE WHEN M.SYMBOL_RUS IS NULL OR M.SYMBOL_RUS = \'\' THEN M.SYMBOL_INTL ELSE M.SYMBOL_RUS END',
					'FIELD_TYPE' => 'string',
					'TABLE_ALIAS' => 'M',
					'LEFT_JOIN' => 'LEFT JOIN b_catalog_measure M ON M.ID = P.MEASURE',
				],
				'PRICE' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => $basePriceId ? 'CP.PRICE' : 'NULL',
					'FIELD_TYPE' => 'double',
					'TABLE_ALIAS' => 'CP',
					'LEFT_JOIN' => $basePriceId ? ('LEFT JOIN b_catalog_price CP ON CP.PRODUCT_ID = P.ID AND (CP.QUANTITY_FROM = 1 OR CP.QUANTITY_FROM IS NULL) AND (CP.QUANTITY_TO = 1 OR CP.QUANTITY_TO IS NULL) AND CP.CATALOG_GROUP_ID = ' . $basePriceId) : null,
				],
				'PURCHASING_PRICE' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'P.PURCHASING_PRICE',
					'FIELD_TYPE' => 'double',
				],
				'PURCHASING_CURRENCY' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'P.PURCHASING_CURRENCY',
					'FIELD_TYPE' => 'string',
				],
			],
		];

		$messages = Loc::loadLanguageFile(__FILE__, $languageId);
		$result['crm_product']['TABLE_DESCRIPTION'] = $messages['CRM_BIC_PRODUCT_TABLE'] ?? 'crm_product';
		$result['crm_product']['TABLE_DESCRIPTION_FULL'] = $messages['CRM_BIC_PRODUCT_TABLE_DESCRIPTION_FULL_MSGVER_1'] ?? '';
		foreach ($result['crm_product']['FIELDS'] as $fieldCode => &$fieldInfo)
		{
			$fieldInfo['FIELD_DESCRIPTION'] = !empty($messages['CRM_BIC_PRODUCT_FIELD_' . $fieldCode]) ? $messages['CRM_BIC_PRODUCT_FIELD_' . $fieldCode] : null;
			if (!$fieldInfo['FIELD_DESCRIPTION'])
			{
				$fieldInfo['FIELD_DESCRIPTION'] = $fieldCode;
			}

			$fieldInfo['FIELD_DESCRIPTION_FULL'] = !empty($messages['CRM_BIC_PRODUCT_FIELD_' . $fieldCode . '_FULL']) ? $messages['CRM_BIC_PRODUCT_FIELD_' . $fieldCode . '_FULL'] : '';
		}
		unset($fieldInfo);
	}

	private static function getTypeCases(): string
	{
		$cases = '';
		$productTypes = \Bitrix\Catalog\ProductTable::getProductTypes(true);
		foreach ($productTypes as $productTypeId => $productTypeName)
		{
			$cases .= "WHEN P.TYPE = {$productTypeId} THEN '{$productTypeName}' ";
		}

		return $cases;
	}

	private static function getParentIdDescription(?array $skuBinding): array
	{
		if (!$skuBinding)
		{
			return [
				'IS_METRIC' => 'N',
				'FIELD_NAME' => 'null',
				'FIELD_TYPE' => 'int',
			];
		}

		return [
			'IS_METRIC' => 'N',
			'FIELD_NAME' => self::getParentIdExpression($skuBinding),
			'FIELD_TYPE' => 'int',
			'TABLE_ALIAS' => 'IEP',
			'LEFT_JOIN' => self::getSkuPropertyJoin($skuBinding),
		];
	}

	/**
	 * Offer iblock binding: which property of which storage version points to the parent product.
	 *
	 * @param int|null $crmCatalogIblockOfferId Offer iblock id.
	 *
	 * @return array{iblockId: int, propertyId: int, version: int}|null
	 */
	private static function getSkuBinding(?int $crmCatalogIblockOfferId): ?array
	{
		if (!$crmCatalogIblockOfferId)
		{
			return null;
		}

		$catalogIblockTableElement = \Bitrix\Catalog\CatalogIblockTable::getRow([
			'select' => ['SKU_PROPERTY_ID'],
			'filter' => ['=IBLOCK_ID' => $crmCatalogIblockOfferId],
		]);
		if (!$catalogIblockTableElement || !$catalogIblockTableElement['SKU_PROPERTY_ID'])
		{
			return null;
		}

		$crmCatalogIblockOffer = \Bitrix\Iblock\IblockTable::getRow([
			'select' => ['VERSION'],
			'filter' => [
				'=ID' => $crmCatalogIblockOfferId,
			],
		]);
		if (!$crmCatalogIblockOffer)
		{
			return null;
		}

		return [
			'iblockId' => $crmCatalogIblockOfferId,
			'propertyId' => (int)$catalogIblockTableElement['SKU_PROPERTY_ID'],
			'version' => (int)$crmCatalogIblockOffer['VERSION'],
		];
	}

	private static function getParentIdExpression(array $skuBinding): string
	{
		return $skuBinding['version'] === 1 ? 'IEP.VALUE' : 'IEP.PROPERTY_' . $skuBinding['propertyId'];
	}

	/**
	 * Joins are deduplicated by their exact text, so every field reusing the IEP alias must take
	 * the string from here.
	 */
	private static function getSkuPropertyJoin(array $skuBinding): string
	{
		if ($skuBinding['version'] === 1)
		{
			return 'LEFT JOIN b_iblock_element_property IEP ON IEP.IBLOCK_ELEMENT_ID = P.ID'
				. ' AND IEP.IBLOCK_PROPERTY_ID = ' . $skuBinding['propertyId'];
		}

		return "LEFT JOIN b_iblock_element_prop_s{$skuBinding['iblockId']} IEP ON IEP.IBLOCK_ELEMENT_ID = P.ID";
	}

	/**
	 * Catalog section of a product: its own section, or the section of the parent product for an offer.
	 *
	 * The element is joined under its own alias: the IE alias is shared with IBLOCK_ID, whose
	 * mandatory filter keeps it in INNER mode, while these fields are always optional. Joins are
	 * deduplicated by their exact text, so the same alias in two modes would be declared twice.
	 *
	 * @param \Bitrix\Main\DB\SqlHelper $helper Sql helper of the BI connection.
	 * @param array|null $skuBinding Offer iblock binding.
	 * @param int $crmCatalogIblockId Product iblock of the CRM catalog.
	 *
	 * @return array{ID_EXPRESSION: string, JOINS: array, NAME_JOINS: array}
	 */
	private static function getSectionSource(
		\Bitrix\Main\DB\SqlHelper $helper,
		?array $skuBinding,
		int $crmCatalogIblockId
	): array
	{
		$idExpression = 'IESEC.IBLOCK_SECTION_ID';
		$joins = [self::SECTION_ELEMENT_JOIN];

		if ($skuBinding)
		{
			$joins[] = self::getSkuPropertyJoin($skuBinding);
			$joins[] = 'LEFT JOIN b_iblock_element IEPARENT ON IEPARENT.ID = '
				. self::getParentIdNumericExpression($skuBinding)
				. ' AND IEPARENT.IBLOCK_ID = ' . $crmCatalogIblockId;
			$idExpression = $helper->getIsNullFunction('IESEC.IBLOCK_SECTION_ID', 'IEPARENT.IBLOCK_SECTION_ID');
		}

		return [
			'ID_EXPRESSION' => $idExpression,
			'JOINS' => $joins,
			'NAME_JOINS' => array_merge($joins, ['LEFT JOIN b_iblock_section ISEC ON ISEC.ID = ' . $idExpression]),
		];
	}

	/**
	 * Parent product id as a numeric expression usable in a join condition.
	 *
	 * VALUE of b_iblock_element_property is text, and casting it would abort the whole dataset on
	 * PgSQL as soon as one value is not a number; VALUE_NUM holds the same binding as a number.
	 */
	private static function getParentIdNumericExpression(array $skuBinding): string
	{
		return $skuBinding['version'] === 1 ? 'IEP.VALUE_NUM' : 'IEP.PROPERTY_' . $skuBinding['propertyId'];
	}
}
