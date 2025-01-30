<?php

use Bitrix\Catalog\Model\Price;
use Bitrix\Main\Config\Option;

require_once $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/catalog/general/price.php';

class price extends CAllPrice
{
    /**
     * @deprecated deprecated since catalog 17.6.0
     * @see Price::getList
     *
     * @param array      $arOrder
     * @param array      $arFilter
     * @param array|bool $arGroupBy
     * @param array|bool $arNavStartParams
     * @param array      $arSelectFields
     *
     * @return bool|CDBResult
     */
    public static function GetList($arOrder = [], $arFilter = [], $arGroupBy = false, $arNavStartParams = false, $arSelectFields = [])
    {
        global $DB, $USER;

        $entityResult = new CCatalogResult('\Bitrix\Catalog\Model\Price');

        // for old execution style
        if (!is_array($arOrder) && !is_array($arFilter)) {
            $arOrder = (string) $arOrder;
            $arFilter = (string) $arFilter;
            $arOrder = ('' !== $arOrder && '' !== $arFilter ? [$arOrder => $arFilter] : []);
            $arFilter = (is_array($arGroupBy) ? $arGroupBy : []);
            $arGroupBy = false;
        }

        $strUserGroups = (CCatalog::IsUserExists() ? $USER->GetGroups() : '2');

        if (empty($arSelectFields)) {
            $arSelectFields = ['ID', 'PRODUCT_ID', 'EXTRA_ID', 'CATALOG_GROUP_ID', 'PRICE', 'CURRENCY', 'TIMESTAMP_X', 'QUANTITY_FROM', 'QUANTITY_TO', 'BASE', 'SORT', 'CATALOG_GROUP_NAME', 'CAN_ACCESS', 'CAN_BUY'];
        }

        $arFields = [
            'ID' => ['FIELD' => 'P.ID', 'TYPE' => 'int'],
            'PRODUCT_ID' => ['FIELD' => 'P.PRODUCT_ID', 'TYPE' => 'int'],
            'EXTRA_ID' => ['FIELD' => 'P.EXTRA_ID', 'TYPE' => 'int'],
            'CATALOG_GROUP_ID' => ['FIELD' => 'P.CATALOG_GROUP_ID', 'TYPE' => 'int'],
            'PRICE' => ['FIELD' => 'P.PRICE', 'TYPE' => 'double'],
            'CURRENCY' => ['FIELD' => 'P.CURRENCY', 'TYPE' => 'string'],
            'TIMESTAMP_X' => ['FIELD' => 'P.TIMESTAMP_X', 'TYPE' => 'datetime'],
            'QUANTITY_FROM' => ['FIELD' => 'P.QUANTITY_FROM', 'TYPE' => 'int'],
            'QUANTITY_TO' => ['FIELD' => 'P.QUANTITY_TO', 'TYPE' => 'int'],
            'TMP_ID' => ['FIELD' => 'P.TMP_ID', 'TYPE' => 'string'],
            'PRICE_BASE_RATE' => ['FIELD' => 'P.PRICE_SCALE', 'TYPE' => 'double'],
            'PRICE_SCALE' => ['FIELD' => 'P.PRICE_SCALE', 'TYPE' => 'double'],
            'BASE' => ['FIELD' => 'CG.BASE', 'TYPE' => 'char', 'FROM' => 'INNER JOIN b_catalog_group CG ON (P.CATALOG_GROUP_ID = CG.ID)'],
            'SORT' => ['FIELD' => 'CG.SORT', 'TYPE' => 'int', 'FROM' => 'INNER JOIN b_catalog_group CG ON (P.CATALOG_GROUP_ID = CG.ID)'],
            'PRODUCT_QUANTITY' => ['FIELD' => 'CP.QUANTITY', 'TYPE' => 'int', 'FROM' => 'INNER JOIN b_catalog_product CP ON (P.PRODUCT_ID = CP.ID)'],
            'PRODUCT_QUANTITY_TRACE' => ['FIELD' => "IF (CP.QUANTITY_TRACE = 'D', '".$DB->ForSql((string) Option::get('catalog', 'default_quantity_trace', 'N'))."', CP.QUANTITY_TRACE)", 'TYPE' => 'char', 'FROM' => 'INNER JOIN b_catalog_product CP ON (P.PRODUCT_ID = CP.ID)'],
            'PRODUCT_CAN_BUY_ZERO' => ['FIELD' => "IF (CP.CAN_BUY_ZERO = 'D', '".$DB->ForSql((string) Option::get('catalog', 'default_can_buy_zero', 'N'))."', CP.CAN_BUY_ZERO)", 'TYPE' => 'char', 'FROM' => 'INNER JOIN b_catalog_product CP ON (P.PRODUCT_ID = CP.ID)'],
            'PRODUCT_NEGATIVE_AMOUNT_TRACE' => ['FIELD' => "IF (CP.NEGATIVE_AMOUNT_TRACE = 'D', '".$DB->ForSql((string) Option::get('catalog', 'allow_negative_amount', 'N'))."', CP.NEGATIVE_AMOUNT_TRACE)", 'TYPE' => 'char', 'FROM' => 'INNER JOIN b_catalog_product CP ON (P.PRODUCT_ID = CP.ID)'],
            'PRODUCT_WEIGHT' => ['FIELD' => 'CP.WEIGHT', 'TYPE' => 'int', 'FROM' => 'INNER JOIN b_catalog_product CP ON (P.PRODUCT_ID = CP.ID)'],
            'ELEMENT_IBLOCK_ID' => ['FIELD' => 'IE.IBLOCK_ID', 'TYPE' => 'int', 'FROM' => 'INNER JOIN b_iblock_element IE ON (P.PRODUCT_ID = IE.ID)'],
            'CATALOG_GROUP_NAME' => ['FIELD' => 'CGL.NAME', 'TYPE' => 'string', 'FROM' => "LEFT JOIN b_catalog_group_lang CGL ON (P.CATALOG_GROUP_ID = CGL.CATALOG_GROUP_ID AND CGL.LANG = '".LANGUAGE_ID."')"],
        ];

        $arFields['CAN_ACCESS'] = [
            'FIELD' => "IF(CGG.ID IS NULL, 'N', 'Y')",
            'TYPE' => 'char',
            'FROM' => 'LEFT JOIN b_catalog_group2group CGG ON (P.CATALOG_GROUP_ID = CGG.CATALOG_GROUP_ID AND CGG.GROUP_ID IN ('.$strUserGroups.") AND CGG.BUY <> 'Y')",
        ];
        $arFields['CAN_BUY'] = [
            'FIELD' => "IF(CGG1.ID IS NULL, 'N', 'Y')",
            'TYPE' => 'char',
            'FROM' => 'LEFT JOIN b_catalog_group2group CGG1 ON (P.CATALOG_GROUP_ID = CGG1.CATALOG_GROUP_ID AND CGG1.GROUP_ID IN ('.$strUserGroups.") AND CGG1.BUY = 'Y')",
        ];

        $arSelectFields = $entityResult->prepareSelect($arSelectFields);

        $arSqls = CCatalog::PrepareSql($arFields, $arOrder, $arFilter, $arGroupBy, $arSelectFields);

        if (array_key_exists('CAN_ACCESS', $arFields) || array_key_exists('CAN_BUY', $arFields)) {
            $arSqls['SELECT'] = str_replace('%%_DISTINCT_%%', 'DISTINCT', $arSqls['SELECT']);
        } else {
            $arSqls['SELECT'] = str_replace('%%_DISTINCT_%%', '', $arSqls['SELECT']);
        }

        if (empty($arGroupBy) && is_array($arGroupBy)) {
            $strSql = 'SELECT '.$arSqls['SELECT'].' FROM b_catalog_price P '.$arSqls['FROM'];
            if (!empty($arSqls['WHERE'])) {
                $strSql .= ' WHERE '.$arSqls['WHERE'];
            }
            if (!empty($arSqls['GROUPBY'])) {
                $strSql .= ' GROUP BY '.$arSqls['GROUPBY'];
            }

            $dbRes = $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
            if ($arRes = $dbRes->Fetch()) {
                return $arRes['CNT'];
            }

            return false;
        }

        $strSql = 'SELECT '.$arSqls['SELECT'].' FROM b_catalog_price P '.$arSqls['FROM'];
        if (!empty($arSqls['WHERE'])) {
            $strSql .= ' WHERE '.$arSqls['WHERE'];
        }
        if (!empty($arSqls['GROUPBY'])) {
            $strSql .= ' GROUP BY '.$arSqls['GROUPBY'];
        }
        if (!empty($arSqls['ORDERBY'])) {
            $strSql .= ' ORDER BY '.$arSqls['ORDERBY'];
        }

        $intTopCount = 0;
        $boolNavStartParams = (!empty($arNavStartParams) && is_array($arNavStartParams));
        if ($boolNavStartParams && isset($arNavStartParams['nTopCount'])) {
            $intTopCount = (int) $arNavStartParams['nTopCount'];
        }
        if ($boolNavStartParams && $intTopCount <= 0) {
            $strSql_tmp = "SELECT COUNT('x') as CNT FROM b_catalog_price P ".$arSqls['FROM'];
            if (!empty($arSqls['WHERE'])) {
                $strSql_tmp .= ' WHERE '.$arSqls['WHERE'];
            }
            if (!empty($arSqls['GROUPBY'])) {
                $strSql_tmp .= ' GROUP BY '.$arSqls['GROUPBY'];
            }

            $dbRes = $DB->Query($strSql_tmp, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
            $cnt = 0;
            if (empty($arSqls['GROUPBY'])) {
                if ($arRes = $dbRes->Fetch()) {
                    $cnt = $arRes['CNT'];
                }
            } else {
                $cnt = $dbRes->SelectedRowsCount();
            }

            $dbRes = new CDBResult();
            $dbRes->NavQuery($strSql, $cnt, $arNavStartParams);
        } else {
            if ($boolNavStartParams && $intTopCount > 0) {
                $strSql .= ' LIMIT '.$intTopCount;
            }
            $entityResult->setResult($DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__));

            $dbRes = $entityResult;
        }

        return $dbRes;
    }

    /**
     * @deprecated deprecated since catalog 17.6.0
     * @see Price::getList
     *
     * @param array      $arOrder
     * @param array      $arFilter
     * @param array|bool $arGroupBy
     * @param array|bool $arNavStartParams
     * @param array      $arSelectFields
     *
     * @return bool|CDBResult
     */
    public static function GetListEx($arOrder = [], $arFilter = [], $arGroupBy = false, $arNavStartParams = false, $arSelectFields = [])
    {
        global $DB;

        $entityResult = new CCatalogResult('\Bitrix\Catalog\Model\Price');

        if (empty($arSelectFields)) {
            $arSelectFields = ['ID', 'PRODUCT_ID', 'EXTRA_ID', 'CATALOG_GROUP_ID', 'PRICE', 'CURRENCY', 'TIMESTAMP_X', 'QUANTITY_FROM', 'QUANTITY_TO', 'TMP_ID'];
        }

        $arFields = [
            'ID' => ['FIELD' => 'P.ID', 'TYPE' => 'int'],
            'PRODUCT_ID' => ['FIELD' => 'P.PRODUCT_ID', 'TYPE' => 'int'],
            'EXTRA_ID' => ['FIELD' => 'P.EXTRA_ID', 'TYPE' => 'int'],
            'CATALOG_GROUP_ID' => ['FIELD' => 'P.CATALOG_GROUP_ID', 'TYPE' => 'int'],
            'PRICE' => ['FIELD' => 'P.PRICE', 'TYPE' => 'double'],
            'CURRENCY' => ['FIELD' => 'P.CURRENCY', 'TYPE' => 'string'],
            'TIMESTAMP_X' => ['FIELD' => 'P.TIMESTAMP_X', 'TYPE' => 'datetime'],
            'QUANTITY_FROM' => ['FIELD' => 'P.QUANTITY_FROM', 'TYPE' => 'int'],
            'QUANTITY_TO' => ['FIELD' => 'P.QUANTITY_TO', 'TYPE' => 'int'],
            'TMP_ID' => ['FIELD' => 'P.TMP_ID', 'TYPE' => 'string'],
            'PRICE_BASE_RATE' => ['FIELD' => 'P.PRICE_SCALE', 'TYPE' => 'double'],
            'PRICE_SCALE' => ['FIELD' => 'P.PRICE_SCALE', 'TYPE' => 'double'],
            'PRODUCT_QUANTITY' => ['FIELD' => 'CP.QUANTITY', 'TYPE' => 'int', 'FROM' => 'INNER JOIN b_catalog_product CP ON (P.PRODUCT_ID = CP.ID)'],
            'PRODUCT_QUANTITY_TRACE' => ['FIELD' => "IF (CP.QUANTITY_TRACE = 'D', '".$DB->ForSql((string) Option::get('catalog', 'default_quantity_trace', 'N'))."', CP.QUANTITY_TRACE)", 'TYPE' => 'char', 'FROM' => 'INNER JOIN b_catalog_product CP ON (P.PRODUCT_ID = CP.ID)'],
            'PRODUCT_CAN_BUY_ZERO' => ['FIELD' => "IF (CP.CAN_BUY_ZERO = 'D', '".$DB->ForSql((string) Option::get('catalog', 'default_can_buy_zero', 'N'))."', CP.CAN_BUY_ZERO)", 'TYPE' => 'char', 'FROM' => 'INNER JOIN b_catalog_product CP ON (P.PRODUCT_ID = CP.ID)'],
            'PRODUCT_NEGATIVE_AMOUNT_TRACE' => ['FIELD' => "IF (CP.NEGATIVE_AMOUNT_TRACE = 'D', '".$DB->ForSql((string) Option::get('catalog', 'allow_negative_amount', 'N'))."', CP.NEGATIVE_AMOUNT_TRACE)", 'TYPE' => 'char', 'FROM' => 'INNER JOIN b_catalog_product CP ON (P.PRODUCT_ID = CP.ID)'],
            'PRODUCT_WEIGHT' => ['FIELD' => 'CP.WEIGHT', 'TYPE' => 'int', 'FROM' => 'INNER JOIN b_catalog_product CP ON (P.PRODUCT_ID = CP.ID)'],
            'ELEMENT_IBLOCK_ID' => ['FIELD' => 'IE.IBLOCK_ID', 'TYPE' => 'int', 'FROM' => 'INNER JOIN b_iblock_element IE ON (P.PRODUCT_ID = IE.ID)'],
            'ELEMENT_NAME' => ['FIELD' => 'IE.NAME', 'TYPE' => 'string', 'FROM' => 'INNER JOIN b_iblock_element IE ON (P.PRODUCT_ID = IE.ID)'],
            'CATALOG_GROUP_CODE' => ['FIELD' => 'CG.NAME', 'TYPE' => 'string', 'FROM' => 'INNER JOIN b_catalog_group CG ON (P.CATALOG_GROUP_ID = CG.ID)'],
            'CATALOG_GROUP_BASE' => ['FIELD' => 'CG.BASE', 'TYPE' => 'char', 'FROM' => 'INNER JOIN b_catalog_group CG ON (P.CATALOG_GROUP_ID = CG.ID)'],
            'CATALOG_GROUP_SORT' => ['FIELD' => 'CG.SORT', 'TYPE' => 'int', 'FROM' => 'INNER JOIN b_catalog_group CG ON (P.CATALOG_GROUP_ID = CG.ID)'],
            'CATALOG_GROUP_NAME' => ['FIELD' => 'CGL.NAME', 'TYPE' => 'string', 'FROM' => "LEFT JOIN b_catalog_group_lang CGL ON (P.CATALOG_GROUP_ID = CGL.CATALOG_GROUP_ID AND CGL.LANG = '".LANGUAGE_ID."')"],
            'GROUP_GROUP_ID' => ['FIELD' => 'CGG.GROUP_ID', 'TYPE' => 'int', 'FROM' => 'INNER JOIN b_catalog_group2group CGG ON (P.CATALOG_GROUP_ID = CGG.CATALOG_GROUP_ID)'],
            'GROUP_BUY' => ['FIELD' => 'CGG.BUY', 'TYPE' => 'char', 'FROM' => 'INNER JOIN b_catalog_group2group CGG ON (P.CATALOG_GROUP_ID = CGG.CATALOG_GROUP_ID)'],
        ];

        $arSelectFields = $entityResult->prepareSelect($arSelectFields);

        $arSqls = CCatalog::PrepareSql($arFields, $arOrder, $arFilter, $arGroupBy, $arSelectFields);

        $arSqls['SELECT'] = str_replace('%%_DISTINCT_%%', '', $arSqls['SELECT']);

        if (empty($arGroupBy) && is_array($arGroupBy)) {
            $strSql = 'SELECT '.$arSqls['SELECT'].' FROM b_catalog_price P '.$arSqls['FROM'];
            if (!empty($arSqls['WHERE'])) {
                $strSql .= ' WHERE '.$arSqls['WHERE'];
            }
            if (!empty($arSqls['GROUPBY'])) {
                $strSql .= ' GROUP BY '.$arSqls['GROUPBY'];
            }

            $dbRes = $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
            if ($arRes = $dbRes->Fetch()) {
                return $arRes['CNT'];
            }

            return false;
        }

        $strSql = 'SELECT '.$arSqls['SELECT'].' FROM b_catalog_price P '.$arSqls['FROM'];
        if (!empty($arSqls['WHERE'])) {
            $strSql .= ' WHERE '.$arSqls['WHERE'];
        }
        if (!empty($arSqls['GROUPBY'])) {
            $strSql .= ' GROUP BY '.$arSqls['GROUPBY'];
        }
        if (!empty($arSqls['ORDERBY'])) {
            $strSql .= ' ORDER BY '.$arSqls['ORDERBY'];
        }

        $intTopCount = 0;
        $boolNavStartParams = (!empty($arNavStartParams) && is_array($arNavStartParams));
        if ($boolNavStartParams && isset($arNavStartParams['nTopCount'])) {
            $intTopCount = (int) $arNavStartParams['nTopCount'];
        }
        if ($boolNavStartParams && $intTopCount <= 0) {
            $strSql_tmp = "SELECT COUNT('x') as CNT FROM b_catalog_price P ".$arSqls['FROM'];
            if (!empty($arSqls['WHERE'])) {
                $strSql_tmp .= ' WHERE '.$arSqls['WHERE'];
            }
            if (!empty($arSqls['GROUPBY'])) {
                $strSql_tmp .= ' GROUP BY '.$arSqls['GROUPBY'];
            }

            $dbRes = $DB->Query($strSql_tmp, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
            $cnt = 0;
            if (empty($arSqls['GROUPBY'])) {
                if ($arRes = $dbRes->Fetch()) {
                    $cnt = $arRes['CNT'];
                }
            } else {
                $cnt = $dbRes->SelectedRowsCount();
            }

            $dbRes = new CDBResult();
            $dbRes->NavQuery($strSql, $cnt, $arNavStartParams);
        } else {
            if ($boolNavStartParams && $intTopCount > 0) {
                $strSql .= ' LIMIT '.$intTopCount;
            }
            $entityResult->setResult($DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__));

            $dbRes = $entityResult;
        }

        return $dbRes;
    }
}
