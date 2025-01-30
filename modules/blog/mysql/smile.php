<?php

require_once $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/blog/general/smile.php';

class smile extends CAllBlogSmile
{
    public static function Add($arFields)
    {
        global $DB, $CACHE_MANAGER;

        if (!CBlogSmile::CheckFields('ADD', $arFields)) {
            return false;
        }

        $arInsert = $DB->PrepareInsert('b_blog_smile', $arFields);

        $strSql =
            'INSERT INTO b_blog_smile('.$arInsert[0].') '.
            'VALUES('.$arInsert[1].')';
        $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
        $ID = (int) $DB->LastID();

        for ($i = 0; $i < count($arFields['LANG']); ++$i) {
            $arInsert = $DB->PrepareInsert('b_blog_smile_lang', $arFields['LANG'][$i]);
            $strSql =
                'INSERT INTO b_blog_smile_lang(SMILE_ID, '.$arInsert[0].') '.
                'VALUES('.$ID.', '.$arInsert[1].')';
            $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
        }
        $CACHE_MANAGER->Clean('b_blog_smile');
        BXClearCache(true, '/blog/smiles/');

        return $ID;
    }

    public static function Update($ID, $arFields)
    {
        global $DB, $CACHE_MANAGER;
        $ID = (int) $ID;
        if ($ID <= 0) {
            return false;
        }

        if (!CBlogSmile::CheckFields('UPDATE', $arFields)) {
            return false;
        }

        $strUpdate = $DB->PrepareUpdate('b_blog_smile', $arFields);
        $strSql = 'UPDATE b_blog_smile SET '.$strUpdate.' WHERE ID = '.$ID;
        $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);

        if (is_set($arFields, 'LANG')) {
            $DB->Query('DELETE FROM b_blog_smile_lang WHERE SMILE_ID = '.$ID.'');

            for ($i = 0; $i < count($arFields['LANG']); ++$i) {
                $arInsert = $DB->PrepareInsert('b_blog_smile_lang', $arFields['LANG'][$i]);
                $strSql =
                    'INSERT INTO b_blog_smile_lang(SMILE_ID, '.$arInsert[0].') '.
                    'VALUES('.$ID.', '.$arInsert[1].')';
                $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
            }
        }
        $CACHE_MANAGER->Clean('b_blog_smile');
        BXClearCache(true, '/blog/smiles/');

        return $ID;
    }

    public static function GetList($arOrder = ['ID' => 'DESC'], $arFilter = [], $arGroupBy = false, $arNavStartParams = false, $arSelectFields = [])
    {
        global $DB;

        if (count($arSelectFields) <= 0) {
            $arSelectFields = ['ID', 'SMILE_TYPE', 'TYPING', 'IMAGE', 'DESCRIPTION', 'CLICKABLE', 'SORT', 'IMAGE_WIDTH', 'IMAGE_HEIGHT'];
        }

        // FIELDS -->
        $arFields = [
            'ID' => ['FIELD' => 'B.ID', 'TYPE' => 'int'],
            'SMILE_TYPE' => ['FIELD' => 'B.SMILE_TYPE', 'TYPE' => 'char'],
            'TYPING' => ['FIELD' => 'B.TYPING', 'TYPE' => 'string'],
            'IMAGE' => ['FIELD' => 'B.IMAGE', 'TYPE' => 'string'],
            'DESCRIPTION' => ['FIELD' => 'B.DESCRIPTION', 'TYPE' => 'string'],
            'CLICKABLE' => ['FIELD' => 'B.CLICKABLE', 'TYPE' => 'char'],
            'SORT' => ['FIELD' => 'B.SORT', 'TYPE' => 'int'],
            'IMAGE_WIDTH' => ['FIELD' => 'B.IMAGE_WIDTH', 'TYPE' => 'int'],
            'IMAGE_HEIGHT' => ['FIELD' => 'B.IMAGE_HEIGHT', 'TYPE' => 'int'],

            'LANG_ID' => ['FIELD' => 'BL.ID', 'TYPE' => 'int', 'FROM' => 'LEFT JOIN b_blog_smile_lang BL ON (B.ID = BL.SMILE_ID'.((isset($arFilter['LANG_LID']) && '' !== $arFilter['LANG_LID']) ? " AND BL.LID = '".$arFilter['LANG_LID']."'" : '').')'],
            'LANG_SMILE_ID' => ['FIELD' => 'BL.SMILE_ID', 'TYPE' => 'int', 'FROM' => 'LEFT JOIN b_blog_smile_lang BL ON (B.ID = BL.SMILE_ID'.((isset($arFilter['LANG_LID']) && '' !== $arFilter['LANG_LID']) ? " AND BL.LID = '".$arFilter['LANG_LID']."'" : '').')'],
            'LANG_LID' => ['FIELD' => 'BL.LID', 'TYPE' => 'string', 'FROM' => 'LEFT JOIN b_blog_smile_lang BL ON (B.ID = BL.SMILE_ID'.((isset($arFilter['LANG_LID']) && '' !== $arFilter['LANG_LID']) ? " AND BL.LID = '".$arFilter['LANG_LID']."'" : '').')'],
            'LANG_NAME' => ['FIELD' => 'BL.NAME', 'TYPE' => 'string', 'FROM' => 'LEFT JOIN b_blog_smile_lang BL ON (B.ID = BL.SMILE_ID'.((isset($arFilter['LANG_LID']) && '' !== $arFilter['LANG_LID']) ? " AND BL.LID = '".$arFilter['LANG_LID']."'" : '').')'],
        ];
        // <-- FIELDS

        $arSqls = CBlog::PrepareSql($arFields, $arOrder, $arFilter, $arGroupBy, $arSelectFields);

        $arSqls['SELECT'] = str_replace('%%_DISTINCT_%%', '', $arSqls['SELECT']);

        if (is_array($arGroupBy) && 0 === count($arGroupBy)) {
            $strSql =
                'SELECT '.$arSqls['SELECT'].' '.
                'FROM b_blog_smile B '.
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
            'FROM b_blog_smile B '.
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
                'FROM b_blog_smile B '.
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
