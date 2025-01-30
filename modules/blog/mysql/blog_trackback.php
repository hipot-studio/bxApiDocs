<?php

require_once $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/blog/general/blog_trackback.php';

class blog_trackback extends CAllBlogTrackback
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

        if (!CBlogTrackback::CheckFields('ADD', $arFields)) {
            return false;
        }

        $arInsert = $DB->PrepareInsert('b_blog_trackback', $arFields);

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

        $ID = false;
        if ('' !== $arInsert[0]) {
            $strSql =
                'INSERT INTO b_blog_trackback('.$arInsert[0].') '.
                'VALUES('.$arInsert[1].')';
            $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);

            $ID = (int) $DB->LastID();
        }

        if ($ID) {
            $arTrackback = CBlogTrackback::GetByID($ID);
            CBlogPost::Update($arTrackback['POST_ID'], ['=NUM_TRACKBACKS' => 'NUM_TRACKBACKS + 1']);
        }

        return $ID;
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

        if (!CBlogTrackback::CheckFields('UPDATE', $arFields, $ID)) {
            return false;
        }

        $strUpdate = $DB->PrepareUpdate('b_blog_trackback', $arFields);

        foreach ($arFields1 as $key => $value) {
            if ('' !== $strUpdate) {
                $strUpdate .= ', ';
            }
            $strUpdate .= $key.'='.$value.' ';
        }

        if ('' !== $strUpdate) {
            $strSql =
                'UPDATE b_blog_trackback SET '.
                '	'.$strUpdate.' '.
                'WHERE ID = '.$ID.' ';
            $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);

            unset($GLOBALS['BLOG_TRACKBACK']['BLOG_TRACKBACK_CACHE_'.$ID]);

            return $ID;
        }

        return false;
    }

    // *************** SELECT *********************/
    public static function GetList($arOrder = ['ID' => 'DESC'], $arFilter = [], $arGroupBy = false, $arNavStartParams = false, $arSelectFields = [])
    {
        global $DB;

        if (count($arSelectFields) <= 0) {
            $arSelectFields = ['ID', 'TITLE', 'URL', 'PREVIEW_TEXT', 'BLOG_NAME', 'POST_DATE', 'BLOG_ID', 'POST_ID'];
        }

        // FIELDS -->
        $arFields = [
            'ID' => ['FIELD' => 'C.ID', 'TYPE' => 'int'],
            'TITLE' => ['FIELD' => 'C.TITLE', 'TYPE' => 'string'],
            'URL' => ['FIELD' => 'C.URL', 'TYPE' => 'string'],
            'PREVIEW_TEXT' => ['FIELD' => 'C.PREVIEW_TEXT', 'TYPE' => 'string'],
            'BLOG_NAME' => ['FIELD' => 'C.BLOG_NAME', 'TYPE' => 'string'],
            'POST_DATE' => ['FIELD' => 'C.POST_DATE', 'TYPE' => 'datetime'],
            'BLOG_ID' => ['FIELD' => 'C.BLOG_ID', 'TYPE' => 'int'],
            'POST_ID' => ['FIELD' => 'C.POST_ID', 'TYPE' => 'int'],
        ];
        // <-- FIELDS

        $arSqls = CBlog::PrepareSql($arFields, $arOrder, $arFilter, $arGroupBy, $arSelectFields);

        $arSqls['SELECT'] = str_replace('%%_DISTINCT_%%', '', $arSqls['SELECT']);

        if (is_array($arGroupBy) && 0 === count($arGroupBy)) {
            $strSql =
                'SELECT '.$arSqls['SELECT'].' '.
                'FROM b_blog_trackback C '.
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
            'FROM b_blog_trackback C '.
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
                'FROM b_blog_trackback C '.
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
