<?php

/** @global CMain $APPLICATION */
use Bitrix\Catalog;
use Bitrix\Main;
use Bitrix\Main\Config\Option;

require_once $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/catalog/general/product.php';

class product extends CAllCatalogProduct
{
    /**
     * @deprecated deprecated since catalog 17.6.0
     * @see Catalog\Model\Product::getList or Catalog\ProductTable::getList
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
        global $DB;

        $entityResult = new CCatalogResult('\Bitrix\Catalog\Model\Product');

        if (!is_array($arOrder) && !is_array($arFilter)) {
            $arOrder = (string) $arOrder;
            $arFilter = (string) $arFilter;
            $arOrder = ('' !== $arOrder && '' !== $arFilter ? [$arOrder => $arFilter] : []);
            $arFilter = (is_array($arGroupBy) ? $arGroupBy : []);
            $arGroupBy = false;
        }

        $defaultQuantityTrace = ('Y' === (string) Option::get('catalog', 'default_quantity_trace') ? 'Y' : 'N');
        $defaultCanBuyZero = ('Y' === (string) Option::get('catalog', 'default_can_buy_zero') ? 'Y' : 'N');
        $defaultNegativeAmount = ('Y' === (string) Option::get('catalog', 'allow_negative_amount') ? 'Y' : 'N');
        $defaultSubscribe = ('N' === (string) Option::get('catalog', 'default_subscribe') ? 'N' : 'Y');

        $arFields = [
            'ID' => ['FIELD' => 'CP.ID', 'TYPE' => 'int'],
            'QUANTITY' => ['FIELD' => 'CP.QUANTITY', 'TYPE' => 'double'],
            'QUANTITY_RESERVED' => ['FIELD' => 'CP.QUANTITY_RESERVED', 'TYPE' => 'double'],
            'QUANTITY_TRACE_ORIG' => ['FIELD' => 'CP.QUANTITY_TRACE', 'TYPE' => 'char'],
            'CAN_BUY_ZERO_ORIG' => ['FIELD' => 'CP.CAN_BUY_ZERO', 'TYPE' => 'char'],
            'NEGATIVE_AMOUNT_TRACE_ORIG' => ['FIELD' => 'CP.NEGATIVE_AMOUNT_TRACE', 'TYPE' => 'char'],
            'QUANTITY_TRACE' => ['FIELD' => "IF (CP.QUANTITY_TRACE = 'D', '".$defaultQuantityTrace."', CP.QUANTITY_TRACE)", 'TYPE' => 'char'],
            'CAN_BUY_ZERO' => ['FIELD' => "IF (CP.CAN_BUY_ZERO = 'D', '".$defaultCanBuyZero."', CP.CAN_BUY_ZERO)", 'TYPE' => 'char'],
            'NEGATIVE_AMOUNT_TRACE' => ['FIELD' => "IF (CP.NEGATIVE_AMOUNT_TRACE = 'D', '".$defaultNegativeAmount."', CP.NEGATIVE_AMOUNT_TRACE)", 'TYPE' => 'char'],
            'SUBSCRIBE_ORIG' => ['FIELD' => 'CP.SUBSCRIBE', 'TYPE' => 'char'],
            'SUBSCRIBE' => ['FIELD' => "IF (CP.SUBSCRIBE = 'D', '".$defaultSubscribe."', CP.SUBSCRIBE)", 'TYPE' => 'char'],
            'AVAILABLE' => ['FIELD' => 'CP.AVAILABLE', 'TYPE' => 'char'],
            'BUNDLE' => ['FIELD' => 'CP.BUNDLE', 'TYPE' => 'char'],
            'WEIGHT' => ['FIELD' => 'CP.WEIGHT', 'TYPE' => 'double'],
            'WIDTH' => ['FIELD' => 'CP.WIDTH', 'TYPE' => 'double'],
            'LENGTH' => ['FIELD' => 'CP.LENGTH', 'TYPE' => 'double'],
            'HEIGHT' => ['FIELD' => 'CP.HEIGHT', 'TYPE' => 'double'],
            'TIMESTAMP_X' => ['FIELD' => 'CP.TIMESTAMP_X', 'TYPE' => 'datetime'],
            'PRICE_TYPE' => ['FIELD' => 'CP.PRICE_TYPE', 'TYPE' => 'char'],
            'RECUR_SCHEME_TYPE' => ['FIELD' => 'CP.RECUR_SCHEME_TYPE', 'TYPE' => 'char'],
            'RECUR_SCHEME_LENGTH' => ['FIELD' => 'CP.RECUR_SCHEME_LENGTH', 'TYPE' => 'int'],
            'TRIAL_PRICE_ID' => ['FIELD' => 'CP.TRIAL_PRICE_ID', 'TYPE' => 'int'],
            'WITHOUT_ORDER' => ['FIELD' => 'CP.WITHOUT_ORDER', 'TYPE' => 'char'],
            'SELECT_BEST_PRICE' => ['FIELD' => 'CP.SELECT_BEST_PRICE', 'TYPE' => 'char'],
            'VAT_ID' => ['FIELD' => 'CP.VAT_ID', 'TYPE' => 'int'],
            'VAT_INCLUDED' => ['FIELD' => 'CP.VAT_INCLUDED', 'TYPE' => 'char'],
            'TMP_ID' => ['FIELD' => 'CP.TMP_ID', 'TYPE' => 'char'],
            'PURCHASING_PRICE' => ['FIELD' => 'CP.PURCHASING_PRICE', 'TYPE' => 'double'],
            'PURCHASING_CURRENCY' => ['FIELD' => 'CP.PURCHASING_CURRENCY', 'TYPE' => 'string'],
            'BARCODE_MULTI' => ['FIELD' => 'CP.BARCODE_MULTI', 'TYPE' => 'char'],
            'MEASURE' => ['FIELD' => 'CP.MEASURE', 'TYPE' => 'int'],
            'TYPE' => ['FIELD' => 'CP.TYPE', 'TYPE' => 'int'],
            'ELEMENT_IBLOCK_ID' => ['FIELD' => 'I.IBLOCK_ID', 'TYPE' => 'int', 'FROM' => 'INNER JOIN b_iblock_element I ON (CP.ID = I.ID)'],
            'ELEMENT_XML_ID' => ['FIELD' => 'I.XML_ID', 'TYPE' => 'string', 'FROM' => 'INNER JOIN b_iblock_element I ON (CP.ID = I.ID)'],
            'ELEMENT_NAME' => ['FIELD' => 'I.NAME', 'TYPE' => 'string', 'FROM' => 'INNER JOIN b_iblock_element I ON (CP.ID = I.ID)'],
        ];

        $arSelectFields = $entityResult->prepareSelect($arSelectFields);

        $arSqls = CCatalog::PrepareSql($arFields, $arOrder, $arFilter, $arGroupBy, $arSelectFields);

        $arSqls['SELECT'] = str_replace('%%_DISTINCT_%%', '', $arSqls['SELECT']);

        if (empty($arGroupBy) && is_array($arGroupBy)) {
            $strSql = 'SELECT '.$arSqls['SELECT'].' FROM b_catalog_product CP '.$arSqls['FROM'];
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

        $strSql = 'SELECT '.$arSqls['SELECT'].' FROM b_catalog_product CP '.$arSqls['FROM'];
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
            $strSql_tmp = "SELECT COUNT('x') as CNT FROM b_catalog_product CP ".$arSqls['FROM'];
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
     * @deprecated deprecated since catalog 8.5.1
     * @see CCatalogProduct::GetList()
     *
     * @param array $arOrder
     * @param array $arFilter
     *
     * @return false
     */
    public static function GetListEx($arOrder = ['SORT' => 'ASC'], $arFilter = [])
    {
        return false;
    }

    /**
     * @deprecated deprecated since catalog 17.6.3
     * @see CCatalogProduct::GetVATDataByID
     *
     * @param int $PRODUCT_ID
     *
     * @return CDBResult|false
     */
    public static function GetVATInfo($PRODUCT_ID)
    {
        $vat = self::GetVATDataByID($PRODUCT_ID);
        if (empty($vat)) {
            $vat = [];
        } else {
            $vat = [0 => $vat];
        }
        $result = new CDBResult();
        $result->InitFromArray($vat);
        unset($vat);

        return $result;
    }

    public static function GetVATDataByIDList(array $list): array
    {
        $output = [];
        if (empty($list)) {
            return $output;
        }
        Main\Type\Collection::normalizeArrayValuesByInt($list, true);
        if (empty($list)) {
            return $output;
        }

        return self::loadVatInfoFromDB($list);
    }

    /**
     * @return array|false
     */
    public static function GetVATDataByID($id)
    {
        $id = (int) $id;
        if ($id <= 0) {
            return false;
        }
        $result = self::loadVatInfoFromDB([$id]);

        return $result[$id] ?? false;
    }

    /**
     * @deprecated deprecated since catalog 17.6.0
     * @see Catalog\Model\Product::update
     *
     * @param int $intID
     * @param int $intTypeID
     *
     * @return bool
     */
    public static function SetProductType($intID, $intTypeID)
    {
        $intID = (int) $intID;
        if ($intID <= 0) {
            return false;
        }
        $intTypeID = (int) $intTypeID;
        if (Catalog\ProductTable::TYPE_PRODUCT !== $intTypeID && Catalog\ProductTable::TYPE_SET !== $intTypeID) {
            return false;
        }

        $result = Catalog\Model\Product::update($intID, ['TYPE' => $intTypeID]);

        return $result->isSuccess();
    }

    private static function loadVatInfoFromDB(array $list): array
    {
        $result = array_fill_keys($list, false);
        $ids = [];
        foreach ($list as $id) {
            if (isset(static::$vatCache[$id])) {
                $result[$id] = static::$vatCache[$id];
            } else {
                $ids[] = $id;
                static::$vatCache[$id] = false;
            }
        }
        if (!empty($ids)) {
            $conn = Main\Application::getConnection();
            $iterator = $conn->query(
                '
	select CAT_PR.ID as PRODUCT_ID, CAT_VAT.*, CAT_PR.VAT_INCLUDED
	from b_catalog_product CAT_PR
	left join b_iblock_element BE on (BE.ID = CAT_PR.ID)
	left join b_catalog_iblock CAT_IB on ((CAT_PR.VAT_ID is null or CAT_PR.VAT_ID = 0) and CAT_IB.IBLOCK_ID = BE.IBLOCK_ID)
	left join b_catalog_vat CAT_VAT on (CAT_VAT.ID = IF((CAT_PR.VAT_ID is null or CAT_PR.VAT_ID = 0), CAT_IB.VAT_ID, CAT_PR.VAT_ID))
	where CAT_PR.ID in ('.implode(', ', $ids).")
	and CAT_VAT.ACTIVE='Y'
	"
            );
            while ($row = $iterator->fetch()) {
                $productId = (int) $row['PRODUCT_ID'];
                if (isset($row['TIMESTAMP_X']) && $row['TIMESTAMP_X'] instanceof Main\Type\DateTime) {
                    $row['TIMESTAMP_X'] = $row['TIMESTAMP_X']->toString();
                }
                if (null !== $row['RATE']) {
                    $row['RATE'] = (float) $row['RATE'];
                }
                static::$vatCache[$productId] = $row;
                $result[$productId] = $row;
            }
            unset($productId, $row, $iterator, $conn);
        }
        unset($ids);

        return $result;
    }
}
