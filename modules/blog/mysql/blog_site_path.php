<?php

require_once $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/blog/general/blog_site_path.php';

class blog_site_path extends CAllBlogSitePath
{
    // ADD, UPDATE, DELETE
    public static function Add($arFields)
    {
        global $DB;

        $arFields1 = [];
        foreach ($arFields as $key => $value) {
            if ('=' === substr($key, 0, 1)) {
                $arFields1[substr($key, 1)] = $value;
                unset($arFields[$key]);
            }
        }

        if (!CBlogSitePath::CheckFields('ADD', $arFields)) {
            return false;
        }

        $arInsert = $DB->PrepareInsert('b_blog_site_path', $arFields);

        foreach ($arFields1 as $key => $value) {
            if ('' !== $arInsert[0]) {
                $arInsert[0] .= ', ';
            }
            $arInsert[0] .= $key;
            if ('' !== $arInsert[1]) {
                $arInsert[1] .= ', ';
            }
            $arInsert[1] .= $value;
        }

        if ('' !== $arInsert[0]) {
            $strSql =
                'INSERT INTO b_blog_site_path('.$arInsert[0].') '.
                'VALUES('.$arInsert[1].')';
            $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);

            $ID = (int) $DB->LastID();

            return $ID;
        }

        return false;
    }

    public static function Update($ID, $arFields)
    {
        global $DB;

        $ID = (int) $ID;

        $arFields1 = [];
        foreach ($arFields as $key => $value) {
            if ('=' === substr($key, 0, 1)) {
                $arFields1[substr($key, 1)] = $value;
                unset($arFields[$key]);
            }
        }

        if (!CBlogSitePath::CheckFields('UPDATE', $arFields, $ID)) {
            return false;
        }

        $strUpdate = $DB->PrepareUpdate('b_blog_site_path', $arFields);

        foreach ($arFields1 as $key => $value) {
            if ('' !== $strUpdate) {
                $strUpdate .= ', ';
            }
            $strUpdate .= $key.'='.$value.' ';
        }

        if ('' !== $strUpdate) {
            $strSql =
                'UPDATE b_blog_site_path SET '.
                '	'.$strUpdate.' '.
                'WHERE ID = '.$ID.' ';
            $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);

            unset($GLOBALS['BLOG_SITE_PATH']['BLOG_SITE_PATH_CACHE_'.$ID]);

            return $ID;
        }

        return false;
    }

    // *************** SELECT *********************/
    public static function GetList($arOrder = ['ID' => 'DESC'], $arFilter = [], $arGroupBy = false, $arNavStartParams = false, $arSelectFields = [])
    {
        global $DB;

        if (count($arSelectFields) <= 0) {
            $arSelectFields = ['ID', 'SITE_ID', 'PATH', 'TYPE'];
        }

        // FIELDS -->
        $arFields = [
            'ID' => ['FIELD' => 'P.ID', 'TYPE' => 'int'],
            'SITE_ID' => ['FIELD' => 'P.SITE_ID', 'TYPE' => 'string'],
            'PATH' => ['FIELD' => 'P.PATH', 'TYPE' => 'string'],
            'TYPE' => ['FIELD' => 'P.TYPE', 'TYPE' => 'string'],
        ];
        // <-- FIELDS

        $arSqls = CBlog::PrepareSql($arFields, $arOrder, $arFilter, $arGroupBy, $arSelectFields);

        $arSqls['SELECT'] = str_replace('%%_DISTINCT_%%', '', $arSqls['SELECT']);

        if (is_array($arGroupBy) && 0 === count($arGroupBy)) {
            $strSql =
                'SELECT '.$arSqls['SELECT'].' '.
                'FROM b_blog_site_path P '.
                '	'.$arSqls['FROM'].' ';
            if ('' !== $arSqls['WHERE']) {
                $strSql .= 'WHERE '.$arSqls['WHERE'].' ';
            }
            if ('' !== $arSqls['GROUPBY']) {
                $strSql .= 'GROUP BY '.$arSqls['GROUPBY'].' ';
            }

            // echo "!1!=".htmlspecialcharsbx($strSql)."<br>";

            $dbRes = $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
            if ($arRes = $dbRes->Fetch()) {
                return $arRes['CNT'];
            }

            return false;
        }

        $strSql =
            'SELECT '.$arSqls['SELECT'].' '.
            'FROM b_blog_site_path P '.
            '	'.$arSqls['FROM'].' ';
        if ('' !== $arSqls['WHERE']) {
            $strSql .= 'WHERE '.$arSqls['WHERE'].' ';
        }
        if ('' !== $arSqls['GROUPBY']) {
            $strSql .= 'GROUP BY '.$arSqls['GROUPBY'].' ';
        }
        if ('' !== $arSqls['ORDERBY']) {
            $strSql .= 'ORDER BY '.$arSqls['ORDERBY'].' ';
        }

        if (is_array($arNavStartParams) && (int) $arNavStartParams['nTopCount'] <= 0) {
            $strSql_tmp =
                "SELECT COUNT('x') as CNT ".
                'FROM b_blog_site_path P '.
                '	'.$arSqls['FROM'].' ';
            if ('' !== $arSqls['WHERE']) {
                $strSql_tmp .= 'WHERE '.$arSqls['WHERE'].' ';
            }
            if ('' !== $arSqls['GROUPBY']) {
                $strSql_tmp .= 'GROUP BY '.$arSqls['GROUPBY'].' ';
            }

            // echo "!2.1!=".htmlspecialcharsbx($strSql_tmp)."<br>";

            $dbRes = $DB->Query($strSql_tmp, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
            $cnt = 0;
            if ('' === $arSqls['GROUPBY']) {
                if ($arRes = $dbRes->Fetch()) {
                    $cnt = $arRes['CNT'];
                }
            } else {
                // ТОЛЬКО ДЛЯ MYSQL!!! ДЛЯ ORACLE ДРУГОЙ КОД
                $cnt = $dbRes->SelectedRowsCount();
            }

            $dbRes = new CDBResult();

            // echo "!2.2!=".htmlspecialcharsbx($strSql)."<br>";

            $dbRes->NavQuery($strSql, $cnt, $arNavStartParams);
        } else {
            if (is_array($arNavStartParams) && (int) $arNavStartParams['nTopCount'] > 0) {
                $strSql .= 'LIMIT '.(int) $arNavStartParams['nTopCount'];
            }

            // echo "!3!=".htmlspecialcharsbx($strSql)."<br>";

            $dbRes = $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
        }

        return $dbRes;
    }
}
