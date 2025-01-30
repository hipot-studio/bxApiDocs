<?php

require_once $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/catalog/general/measure_ratio.php';

class measure_ratio extends CCatalogMeasureRatioAll
{
    /**
     * @param array      $arOrder
     * @param array      $arFilter
     * @param array|bool $arGroupBy
     * @param array|bool $arNavStartParams
     * @param array      $arSelectFields
     *
     * @return bool|CDBResult
     */
    public static function getList($arOrder = [], $arFilter = [], $arGroupBy = false, $arNavStartParams = false, $arSelectFields = [])
    {
        global $DB;
        if (empty($arSelectFields)) {
            $arSelectFields = ['ID', 'PRODUCT_ID', 'RATIO', 'IS_DEFAULT'];
        }
        $arFields = [
            'ID' => ['FIELD' => 'MR.ID', 'TYPE' => 'int'],
            'PRODUCT_ID' => ['FIELD' => 'MR.PRODUCT_ID', 'TYPE' => 'int'],
            'RATIO' => ['FIELD' => 'MR.RATIO', 'TYPE' => 'double'],
            'IS_DEFAULT' => ['FIELD' => 'MR.IS_DEFAULT', 'TYPE' => 'char'],
        ];
        if (isset($arFilter['IS_DEFAULT']) && '' === $arFilter['IS_DEFAULT']) {
            unset($arFilter['IS_DEFAULT']);
        } elseif (!isset($arFilter['IS_DEFAULT'])) {
            $arFilter['IS_DEFAULT'] = 'Y';
        }

        $arSqls = CCatalog::PrepareSql($arFields, $arOrder, $arFilter, $arGroupBy, $arSelectFields);
        $arSqls['SELECT'] = str_replace('%%_DISTINCT_%%', '', $arSqls['SELECT']);

        if (empty($arGroupBy) && is_array($arGroupBy)) {
            $strSql = 'select '.$arSqls['SELECT'].' from b_catalog_measure_ratio MR '.$arSqls['FROM'];
            if (!empty($arSqls['WHERE'])) {
                $strSql .= ' where '.$arSqls['WHERE'];
            }
            if (!empty($arSqls['GROUPBY'])) {
                $strSql .= ' group by '.$arSqls['GROUPBY'];
            }

            $dbRes = $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
            if ($arRes = $dbRes->Fetch()) {
                return $arRes['CNT'];
            }

            return false;
        }

        $strSql = 'select '.$arSqls['SELECT'].' from b_catalog_measure_ratio MR '.$arSqls['FROM'];
        if (!empty($arSqls['WHERE'])) {
            $strSql .= ' where '.$arSqls['WHERE'];
        }
        if (!empty($arSqls['GROUPBY'])) {
            $strSql .= ' group by '.$arSqls['GROUPBY'];
        }
        if (!empty($arSqls['ORDERBY'])) {
            $strSql .= ' order by '.$arSqls['ORDERBY'];
        }

        $intTopCount = 0;
        $boolNavStartParams = (!empty($arNavStartParams) && is_array($arNavStartParams));
        if ($boolNavStartParams && isset($arNavStartParams['nTopCount'])) {
            $intTopCount = (int) $arNavStartParams['nTopCount'];
        }

        if ($boolNavStartParams && $intTopCount <= 0) {
            $strSql_tmp = "select COUNT('x') as CNT FROM b_catalog_measure_ratio MR ".$arSqls['FROM'];
            if (!empty($arSqls['WHERE'])) {
                $strSql_tmp .= ' where '.$arSqls['WHERE'];
            }
            if (!empty($arSqls['GROUPBY'])) {
                $strSql_tmp .= ' group by '.$arSqls['GROUPBY'];
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
                $strSql .= ' limit '.$intTopCount;
            }

            $dbRes = $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
        }

        return $dbRes;
    }
}
