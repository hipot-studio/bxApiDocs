<?php

require_once $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/catalog/general/store_product.php';

class store_product extends CCatalogStoreProductAll
{
    public static function Add($arFields)
    {
        global $DB;

        foreach (GetModuleEvents('catalog', 'OnBeforeStoreProductAdd', true) as $arEvent) {
            if (false === ExecuteModuleEventEx($arEvent, [&$arFields])) {
                return false;
            }
        }

        if (!static::CheckFields('ADD', $arFields)) {
            return false;
        }

        $arInsert = $DB->PrepareInsert('b_catalog_store_product', $arFields);
        $strSql = 'INSERT INTO b_catalog_store_product ('.$arInsert[0].') VALUES('.$arInsert[1].')';

        $res = $DB->Query($strSql, true, 'File: '.__FILE__.'<br>Line: '.__LINE__);
        if (!$res) {
            return false;
        }

        $lastId = (int) $DB->LastID();

        foreach (GetModuleEvents('catalog', 'OnStoreProductAdd', true) as $arEvent) {
            ExecuteModuleEventEx($arEvent, [$lastId, $arFields]);
        }

        return $lastId;
    }

    /**
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
        $arFields = [
            'ID' => ['FIELD' => 'CP.ID', 'TYPE' => 'int'],
            'PRODUCT_ID' => ['FIELD' => 'CP.PRODUCT_ID', 'TYPE' => 'int'],
            'STORE_ID' => ['FIELD' => 'CP.STORE_ID', 'TYPE' => 'int'],
            'AMOUNT' => ['FIELD' => 'CP.AMOUNT', 'TYPE' => 'double'],
            'QUANTITY_RESERVED' => ['FIELD' => 'CP.QUANTITY_RESERVED', 'TYPE' => 'double'],
            'STORE_NAME' => ['FIELD' => 'CS.TITLE', 'TYPE' => 'string', 'FROM' => 'RIGHT JOIN b_catalog_store CS ON (CS.ID = CP.STORE_ID)'],
            'STORE_ADDR' => ['FIELD' => 'CS.ADDRESS', 'TYPE' => 'string', 'FROM' => 'RIGHT JOIN b_catalog_store CS ON (CS.ID = CP.STORE_ID)'],
            'STORE_DESCR' => ['FIELD' => 'CS.DESCRIPTION', 'TYPE' => 'string', 'FROM' => 'RIGHT JOIN b_catalog_store CS ON (CS.ID = CP.STORE_ID)'],
            'STORE_GPS_N' => ['FIELD' => 'CS.GPS_N', 'TYPE' => 'string', 'FROM' => 'RIGHT JOIN b_catalog_store CS ON (CS.ID = CP.STORE_ID)'],
            'STORE_GPS_S' => ['FIELD' => 'CS.GPS_S', 'TYPE' => 'string', 'FROM' => 'RIGHT JOIN b_catalog_store CS ON (CS.ID = CP.STORE_ID)'],
            'STORE_IMAGE' => ['FIELD' => 'CS.IMAGE_ID', 'TYPE' => 'int', 'FROM' => 'RIGHT JOIN b_catalog_store CS ON (CS.ID = CP.STORE_ID)'],
            'STORE_LOCATION' => ['FIELD' => 'CS.LOCATION_ID', 'TYPE' => 'int', 'FROM' => 'RIGHT JOIN b_catalog_store CS ON (CS.ID = CP.STORE_ID)'],
            'STORE_PHONE' => ['FIELD' => 'CS.PHONE', 'TYPE' => 'string', 'FROM' => 'RIGHT JOIN b_catalog_store CS ON (CS.ID = CP.STORE_ID)'],
        ];
        $arSqls = CCatalog::PrepareSql($arFields, $arOrder, $arFilter, $arGroupBy, $arSelectFields);
        $arSqls['SELECT'] = str_replace('%%_DISTINCT_%%', '', $arSqls['SELECT']);

        if (empty($arGroupBy) && is_array($arGroupBy)) {
            $strSql = 'SELECT '.$arSqls['SELECT'].' FROM b_catalog_store_product CP '.$arSqls['FROM'];
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
        $strSql = 'SELECT '.$arSqls['SELECT'].' FROM b_catalog_store_product CP '.$arSqls['FROM'];
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
        if ($boolNavStartParams && array_key_exists('nTopCount', $arNavStartParams)) {
            $intTopCount = (int) $arNavStartParams['nTopCount'];
        }
        if ($boolNavStartParams && 0 >= $intTopCount) {
            $strSql_tmp = "SELECT COUNT('x') as CNT FROM b_catalog_store_product CP ".$arSqls['FROM'];
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
            if ($boolNavStartParams && 0 < $intTopCount) {
                $strSql .= ' LIMIT '.$intTopCount;
            }
            $dbRes = $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
        }

        return $dbRes;
    }
}
