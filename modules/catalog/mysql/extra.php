<?php

use Bitrix\Catalog;

require_once $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/catalog/general/extra.php';

class extra extends CAllExtra
{
    public static function Add($arFields)
    {
        global $DB;

        if (!static::CheckFields('ADD', $arFields)) {
            return false;
        }

        $arInsert = $DB->PrepareInsert('b_catalog_extra', $arFields);

        $strSql = 'INSERT INTO b_catalog_extra('.$arInsert[0].') VALUES('.$arInsert[1].')';
        $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);

        static::ClearCache();
        Catalog\ExtraTable::cleanCache();
        Catalog\Model\Price::clearSettings();

        return (int) $DB->LastID();
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

        // for old execution style
        if (!is_array($arOrder) && !is_array($arFilter)) {
            $arOrder = (string) $arOrder;
            $arFilter = (string) $arFilter;
            if ('' !== $arOrder && '' !== $arFilter) {
                $arOrder = [$arOrder => $arFilter];
            } else {
                $arOrder = [];
            }
            $arFilter = [];
            $arGroupBy = false;
        }

        if (empty($arSelectFields)) {
            $arSelectFields = ['ID', 'NAME', 'PERCENTAGE'];
        }

        $arFields = [
            'ID' => ['FIELD' => 'E.ID', 'TYPE' => 'int'],
            'NAME' => ['FIELD' => 'E.NAME', 'TYPE' => 'string'],
            'PERCENTAGE' => ['FIELD' => 'E.PERCENTAGE', 'TYPE' => 'double'],
        ];

        $arSqls = CCatalog::PrepareSql($arFields, $arOrder, $arFilter, $arGroupBy, $arSelectFields);

        $arSqls['SELECT'] = str_replace('%%_DISTINCT_%%', '', $arSqls['SELECT']);

        if (empty($arGroupBy) && is_array($arGroupBy)) {
            $strSql = 'SELECT '.$arSqls['SELECT'].' FROM b_catalog_extra E '.$arSqls['FROM'];
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

        $strSql = 'SELECT '.$arSqls['SELECT'].' FROM b_catalog_extra E '.$arSqls['FROM'];
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
            $strSql_tmp = "SELECT COUNT('x') as CNT FROM b_catalog_extra E ".$arSqls['FROM'];
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
