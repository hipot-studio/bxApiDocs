<?php

namespace Bitrix\Crm\Integration\BiConnector;

use Bitrix\Catalog\CatalogIblockTable;
use Bitrix\Crm\Discount;
use Bitrix\Crm\Model\Dynamic\TypeTable;
use Bitrix\Crm\Product\Catalog;
use Bitrix\Iblock\IblockTable;
use Bitrix\Main\DB\MysqliSqlHelper;
use Bitrix\Main\DB\PgsqlSqlHelper;
use Bitrix\Main\Loader;

class DynamicItemsProductMapping
{
	public static function getMapping(MysqliSqlHelper|PgsqlSqlHelper $helper, string $languageId): array
	{
		$types = TypeTable::query()->setSelect(['ENTITY_TYPE_ID', 'TITLE'])->fetchCollection();

		$discountSql = self::getDiscountSql($helper);
		$parentData = self::getParentDescription($helper);
		$parentData['FIELD_DESCRIPTION'] = Localization::getMessage('CRM_DYNAMIC_ITEMS_PROD_FIELD_PARENT', $languageId);
		$superParentData = self::getSuperParentDescription($helper);
		$superParentData['FIELD_DESCRIPTION'] = Localization::getMessage('CRM_DYNAMIC_ITEMS_PROD_FIELD_SUPERPARENT', $languageId);
		$superSuperParentData = self::getSuperSuperParentDescription($helper);
		$superSuperParentData['FIELD_DESCRIPTION'] = Localization::getMessage('CRM_DYNAMIC_ITEMS_PROD_FIELD_SUPERSUPERPARENT', $languageId);

		$result = [];
		foreach ($types as $type)
		{
			$result['crm_dynamic_items_prod_' . $type->getEntityTypeId()] = [
				'TABLE_NAME' => 'b_crm_product_row',
				'TABLE_DESCRIPTION' => Localization::getMessage('CRM_DYNAMIC_ITEMS_PROD_TABLE', $languageId, ['#TITLE#' => $type->getTitle()]) ?? $type->getTitle(),
				'TABLE_ALIAS' => 'PR',
				'FILTER' => [
					'=OWNER_TYPE' => \CCrmOwnerTypeAbbr::ResolveByTypeID($type->getEntityTypeId()),
				],
				'FILTER_FIELDS' => [
					'OWNER_TYPE' => [
						'IS_METRIC' => 'N',
						'FIELD_NAME' => 'PR.OWNER_TYPE',
						'FIELD_TYPE' => 'string',
					],
				],
				'FIELDS' => [
					'ID' => [
						'IS_PRIMARY' => 'Y',
						'FIELD_NAME' => 'PR.ID',
						'FIELD_TYPE' => 'int',
						'FIELD_DESCRIPTION' => Localization::getMessage('CRM_DYNAMIC_ITEMS_PROD_FIELD_ID', $languageId),
					],
					'ITEM_ID' => [
						'IS_METRIC' => 'N', // 'Y'
						'FIELD_NAME' => 'PR.OWNER_ID',
						'FIELD_TYPE' => 'int',
						'FIELD_DESCRIPTION' => Localization::getMessage('CRM_DYNAMIC_ITEMS_PROD_FIELD_ITEM_ID', $languageId),
					],
					'PRODUCT' => [
						'FIELD_NAME' => 'concat_ws(\' \', ' . $helper->getConcatFunction('\'[\'', 'PR.PRODUCT_ID', '\']\'') . ', nullif(PR.PRODUCT_NAME, \'\'))',
						'FIELD_TYPE' => 'string',
						'FIELD_DESCRIPTION' => Localization::getMessage('CRM_DYNAMIC_ITEMS_PROD_FIELD_PRODUCT', $languageId),
					],
					'PRODUCT_ID' => [
						'FIELD_NAME' => 'PR.PRODUCT_ID',
						'FIELD_TYPE' => 'int',
						'FIELD_DESCRIPTION' => Localization::getMessage('CRM_DYNAMIC_ITEMS_PROD_FIELD_PRODUCT_ID', $languageId),
					],
					'PRODUCT_NAME' => [
						'FIELD_NAME' => 'PR.PRODUCT_NAME',
						'FIELD_TYPE' => 'string',
						'FIELD_DESCRIPTION' => Localization::getMessage('CRM_DYNAMIC_ITEMS_PROD_FIELD_PRODUCT_NAME', $languageId),
					],
					'PRICE' => [
						'FIELD_NAME' => 'PR.PRICE',
						'FIELD_TYPE' => 'double',
						'FIELD_DESCRIPTION' => Localization::getMessage('CRM_DYNAMIC_ITEMS_PROD_FIELD_PRICE', $languageId),
					],
					'PRICE_EXCLUSIVE' => [
						'FIELD_NAME' => 'PR.PRICE_EXCLUSIVE',
						'FIELD_TYPE' => 'double',
						'FIELD_DESCRIPTION' => Localization::getMessage('CRM_DYNAMIC_ITEMS_PROD_FIELD_PRICE_EXCLUSIVE', $languageId),
					],
					'PRICE_NETTO' => [
						'FIELD_NAME' => 'PR.PRICE_NETTO',
						'FIELD_TYPE' => 'double',
						'FIELD_DESCRIPTION' => Localization::getMessage('CRM_DYNAMIC_ITEMS_PROD_FIELD_PRICE_NETTO', $languageId),
					],
					'PRICE_BRUTTO' => [
						'FIELD_NAME' => 'PR.PRICE_BRUTTO',
						'FIELD_TYPE' => 'double',
						'FIELD_DESCRIPTION' => Localization::getMessage('CRM_DYNAMIC_ITEMS_PROD_FIELD_PRICE_BRUTTO', $languageId),
					],
					'QUANTITY' => [
						'FIELD_NAME' => 'PR.QUANTITY',
						'FIELD_TYPE' => 'double',
						'FIELD_DESCRIPTION' => Localization::getMessage('CRM_DYNAMIC_ITEMS_PROD_FIELD_QUANTITY', $languageId),
					],
					'DISCOUNT_TYPE' => [
						'FIELD_NAME' => 'concat_ws(\' \', ' . $helper->getConcatFunction('\'[\'', 'PR.DISCOUNT_TYPE_ID', '\']\'') . ', ' . str_replace('#FIELD_NAME#', 'PR.DISCOUNT_TYPE_ID', $discountSql) . ')',
						'FIELD_TYPE' => 'string',
						'FIELD_DESCRIPTION' => Localization::getMessage('CRM_DYNAMIC_ITEMS_PROD_FIELD_DISCOUNT_TYPE', $languageId),
					],
					'DISCOUNT_TYPE_ID' => [
						'FIELD_NAME' => 'PR.DISCOUNT_TYPE_ID',
						'FIELD_TYPE' => 'int',
						'FIELD_DESCRIPTION' => Localization::getMessage('CRM_DYNAMIC_ITEMS_PROD_FIELD_DISCOUNT_TYPE_ID', $languageId),
					],
					'DISCOUNT_TYPE_NAME' => [
						'FIELD_NAME' => str_replace('#FIELD_NAME#', 'PR.DISCOUNT_TYPE_ID', $discountSql),
						'FIELD_TYPE' => 'string',
						'FIELD_DESCRIPTION' => Localization::getMessage('CRM_DYNAMIC_ITEMS_PROD_FIELD_DISCOUNT_TYPE_NAME', $languageId),
					],
					'DISCOUNT_RATE' => [
						'FIELD_NAME' => 'PR.DISCOUNT_RATE',
						'FIELD_TYPE' => 'double',
						'FIELD_DESCRIPTION' => Localization::getMessage('CRM_DYNAMIC_ITEMS_PROD_FIELD_DISCOUNT_RATE', $languageId),
					],
					'DISCOUNT_SUM' => [
						'FIELD_NAME' => 'PR.DISCOUNT_SUM',
						'FIELD_TYPE' => 'double',
						'FIELD_DESCRIPTION' => Localization::getMessage('CRM_DYNAMIC_ITEMS_PROD_FIELD_DISCOUNT_SUM', $languageId),
					],
					'TAX_RATE' => [
						'FIELD_NAME' => 'PR.TAX_RATE',
						'FIELD_TYPE' => 'double',
						'FIELD_DESCRIPTION' => Localization::getMessage('CRM_DYNAMIC_ITEMS_PROD_FIELD_TAX_RATE', $languageId),
					],
					'TAX_INCLUDED' => [
						'FIELD_NAME' => 'PR.TAX_INCLUDED',
						'FIELD_TYPE' => 'string',
						'FIELD_DESCRIPTION' => Localization::getMessage('CRM_DYNAMIC_ITEMS_PROD_FIELD_TAX_INCLUDED', $languageId),
					],
					'CUSTOMIZED' => [
						'FIELD_NAME' => 'PR.CUSTOMIZED',
						'FIELD_TYPE' => 'string',
						'FIELD_DESCRIPTION' => Localization::getMessage('CRM_DYNAMIC_ITEMS_PROD_FIELD_CUSTOMIZED', $languageId),
						'FIELD_DESCRIPTION_FULL' => Localization::getMessage('CRM_DYNAMIC_ITEMS_PROD_FIELD_CUSTOMIZED_FULL', $languageId),
					],
					'MEASURE' => [
						'FIELD_NAME' => 'concat_ws(\' \', ' . $helper->getConcatFunction('\'[\'', 'PR.MEASURE_CODE', '\']\'') . ', nullif(PR.MEASURE_NAME, \'\'))',
						'FIELD_TYPE' => 'string',
						'FIELD_DESCRIPTION' => Localization::getMessage('CRM_DYNAMIC_ITEMS_PROD_FIELD_MEASURE', $languageId),
					],
					'MEASURE_CODE' => [
						'FIELD_NAME' => 'PR.MEASURE_CODE',
						'FIELD_TYPE' => 'int',
						'FIELD_DESCRIPTION' => Localization::getMessage('CRM_DYNAMIC_ITEMS_PROD_FIELD_MEASURE_CODE', $languageId),
					],
					'MEASURE_NAME' => [
						'FIELD_NAME' => 'PR.MEASURE_NAME',
						'FIELD_TYPE' => 'string',
						'FIELD_DESCRIPTION' => Localization::getMessage('CRM_DYNAMIC_ITEMS_PROD_FIELD_MEASURE_NAME', $languageId),
					],
					'SORT' => [
						'FIELD_NAME' => 'PR.SORT',
						'FIELD_TYPE' => 'int',
						'FIELD_DESCRIPTION' => Localization::getMessage('CRM_DYNAMIC_ITEMS_PROD_FIELD_SORT', $languageId),
					],
					'PARENT' => $parentData,
					'SUPERPARENT' => $superParentData,
					'SUPERSUPERPARENT' => $superSuperParentData,
				],
			];
		}

		return $result;
	}

	private static function getDiscountSql(MysqliSqlHelper|PgsqlSqlHelper $helper): string
	{
		$discountForSql = [];
		$discountSemantics = [
			Discount::UNDEFINED,
			Discount::MONETARY,
			Discount::PERCENTAGE,
		];

		foreach ($discountSemantics as $id)
		{
			$discountForSql[] = 'when #FIELD_NAME# = \'' . $helper->forSql($id) . '\' then \'' . $helper->forSql(Discount::resolveName($id)) . '\'';
		}

		return 'case ' . implode("\n", $discountForSql) . ' else null end';
	}


	private static function getParentDescription(MysqliSqlHelper|PgsqlSqlHelper $helper): array
	{
		if (Loader::includeModule('iblock'))
		{
			$parentProductQuery = self::getParentProductIdQuery();

			return [
				'FIELD_NAME' => 'IS1.NAME',
				'FIELD_TYPE' => 'string',
				'TABLE_ALIAS' => 'IS1',
				'LEFT_JOIN' => [
					'LEFT JOIN b_iblock_element IE ON IE.ID = ' . ($parentProductQuery ? $helper->getIsNullFunction($parentProductQuery, 'PR.PRODUCT_ID') : 'PR.PRODUCT_ID'),
					'LEFT JOIN b_iblock_section IS1 ON IS1.ID = IE.IBLOCK_SECTION_ID',
				],
			];
		}

		return [
			'FIELD_NAME' => 'null',
			'FIELD_TYPE' => 'string',
		];
	}

	private static function getSuperParentDescription(MysqliSqlHelper|PgsqlSqlHelper $helper): array
	{
		if (Loader::includeModule('iblock'))
		{
			$parentProductQuery = self::getParentProductIdQuery();

			return [
				'FIELD_NAME' => 'IS2.NAME',
				'FIELD_TYPE' => 'string',
				'TABLE_ALIAS' => 'IS2',
				'LEFT_JOIN' => [
					'LEFT JOIN b_iblock_element IE ON IE.ID = ' . ($parentProductQuery ? $helper->getIsNullFunction($parentProductQuery, 'PR.PRODUCT_ID') : 'PR.PRODUCT_ID'),
					'LEFT JOIN b_iblock_section IS1 ON IS1.ID = IE.IBLOCK_SECTION_ID',
					'LEFT JOIN b_iblock_section IS2 ON IS2.ID = IS1.IBLOCK_SECTION_ID',
				],
			];
		}

		return [
			'FIELD_NAME' => 'null',
			'FIELD_TYPE' => 'string',
		];
	}

	private static function getSuperSuperParentDescription(MysqliSqlHelper|PgsqlSqlHelper $helper): array
	{
		if (Loader::includeModule('iblock'))
		{
			$parentProductQuery = self::getParentProductIdQuery();

			return [
				'FIELD_NAME' => 'IS3.NAME',
				'FIELD_TYPE' => 'string',
				'TABLE_ALIAS' => 'IS3',
				'LEFT_JOIN' => [
					'LEFT JOIN b_iblock_element IE ON IE.ID = ' . ($parentProductQuery ? $helper->getIsNullFunction($parentProductQuery, 'PR.PRODUCT_ID') : 'PR.PRODUCT_ID'),
					'LEFT JOIN b_iblock_section IS1 ON IS1.ID = IE.IBLOCK_SECTION_ID',
					'LEFT JOIN b_iblock_section IS2 ON IS2.ID = IS1.IBLOCK_SECTION_ID',
					'LEFT JOIN b_iblock_section IS3 ON IS3.ID = IS2.IBLOCK_SECTION_ID',
				],
			];
		}

		return [
			'FIELD_NAME' => 'null',
			'FIELD_TYPE' => 'string',
		];
	}

	private static function getParentProductIdQuery(): ?string
	{
		static $query = null;
		if ($query)
		{
			return $query;
		}

		$crmCatalogIBlockOfferId = Catalog::getDefaultOfferId();
		if (!$crmCatalogIBlockOfferId)
		{
			return null;
		}

		$catalogIBlockTableElement = CatalogIblockTable::getByPrimary(
			$crmCatalogIBlockOfferId,
			[
				'select' => ['SKU_PROPERTY_ID'],
			],
		)->fetch();
		if (!$catalogIBlockTableElement)
		{
			return null;
		}

		$skuPropertyId = (int)$catalogIBlockTableElement['SKU_PROPERTY_ID'];
		if (!$skuPropertyId)
		{
			return null;
		}

		$crmCatalogIBlockOffer = IblockTable::getRow([
			'select' => ['VERSION'],
			'filter' => [
				'=ID' => $crmCatalogIBlockOfferId,
			],
		]);
		if (!$crmCatalogIBlockOffer)
		{
			return null;
		}

		$crmCatalogIBlockOfferVersion = (int)$crmCatalogIBlockOffer['VERSION'];
		if ($crmCatalogIBlockOfferVersion === 1)
		{
			$query = "(SELECT VALUE FROM b_iblock_element_property where IBLOCK_ELEMENT_ID = PR.PRODUCT_ID and IBLOCK_PROPERTY_ID = {$skuPropertyId})";
		} else
		{
			$query = "(SELECT PROPERTY_{$skuPropertyId} FROM b_iblock_element_prop_s{$crmCatalogIBlockOfferId} where IBLOCK_ELEMENT_ID = PR.PRODUCT_ID)";
		}

		return $query;
	}
}