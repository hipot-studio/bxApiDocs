<?php

require_once $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/blog/general/blog_image.php';

class blog_image extends CAllBlogImage
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

        if (!CBlogImage::CheckFields('ADD', $arFields)) {
            return false;
        }

        if (is_array($arFields['FILE_ID'])) {
            if (
                array_key_exists('FILE_ID', $arFields)
                && is_array($arFields['FILE_ID'])
                && (
                    !array_key_exists('MODULE_ID', $arFields['FILE_ID'])
                    || '' === $arFields['FILE_ID']['MODULE_ID']
                )
            ) {
                $arFields['FILE_ID']['MODULE_ID'] = 'blog';
            }

            $prefix = 'blog';
            if ('' !== $arFields['URL']) {
                $prefix .= '/'.$arFields['URL'];
            }

            CFile::SaveForDB($arFields, 'FILE_ID', $prefix);
        }

        if (
            isset($arFields['FILE_ID'])
            && ((int) $arFields['FILE_ID'] === $arFields['FILE_ID'])
        ) {
            $arInsert = $DB->PrepareInsert('b_blog_image', $arFields);

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
                    'INSERT INTO b_blog_image('.$arInsert[0].') '.
                    'VALUES('.$arInsert[1].')';
                $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);

                $ID = (int) $DB->LastID();

                return $ID;
            }
        } else {
            $GLOBALS['APPLICATION']->ThrowException('Error Adding file by CFile::SaveForDB');
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
        if (!CBlogImage::CheckFields('UPDATE', $arFields, $ID)) {
            return false;
        }
        $strUpdate = $DB->PrepareUpdate('b_blog_image', $arFields);

        foreach ($arFields1 as $key => $value) {
            if ('' !== $strUpdate) {
                $strUpdate .= ', ';
            }
            $strUpdate .= $key.'='.$value.' ';
        }
        if ('' !== $strUpdate) {
            $strSql =
                'UPDATE b_blog_image SET '.
                '	'.$strUpdate.' '.
                'WHERE ID = '.$ID.' ';
            $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);

            unset($GLOBALS['BLOG_IMAGE']['BLOG_IMAGE_CACHE_'.$ID]);

            return $ID;
        }

        return false;
    }

    // *************** SELECT *********************/
    public static function GetList($arOrder = ['ID' => 'DESC'], $arFilter = [], $arGroupBy = false, $arNavStartParams = false, $arSelectFields = [])
    {
        global $DB;

        if (count($arSelectFields) <= 0) {
            $arSelectFields = ['ID', 'FILE_ID', 'POST_ID', 'BLOG_ID', 'USER_ID', 'TITLE', 'TIMESTAMP_X', 'IMAGE_SIZE'];
        }

        // FIELDS -->
        $arFields = [
            'ID' => ['FIELD' => 'G.ID', 'TYPE' => 'int'],
            'FILE_ID' => ['FIELD' => 'G.FILE_ID', 'TYPE' => 'int'],
            'POST_ID' => ['FIELD' => 'G.POST_ID', 'TYPE' => 'int'],
            'BLOG_ID' => ['FIELD' => 'G.BLOG_ID', 'TYPE' => 'int'],
            'USER_ID' => ['FIELD' => 'G.USER_ID', 'TYPE' => 'int'],
            'TITLE' => ['FIELD' => 'G.TITLE', 'TYPE' => 'string'],
            'TIMESTAMP_X' => ['FIELD' => 'G.TIMESTAMP_X', 'TYPE' => 'datetime'],
            'IMAGE_SIZE' => ['FIELD' => 'G.IMAGE_SIZE', 'TYPE' => 'int'],
            'IS_COMMENT' => ['FIELD' => 'G.IS_COMMENT', 'TYPE' => 'string'],
            'COMMENT_ID' => ['FIELD' => 'G.COMMENT_ID', 'TYPE' => 'int'],
        ];
        // <-- FIELDS

        $arSqls = CBlog::PrepareSql($arFields, $arOrder, $arFilter, $arGroupBy, $arSelectFields);

        $arSqls['SELECT'] = str_replace('%%_DISTINCT_%%', '', $arSqls['SELECT']);

        if (is_array($arGroupBy) && 0 === count($arGroupBy)) {
            $strSql =
                'SELECT '.$arSqls['SELECT'].' '.
                'FROM b_blog_image G '.
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
            'FROM b_blog_image G '.
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
                'FROM b_blog_image G '.
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
