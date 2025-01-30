<?php

IncludeModuleLangFile(__FILE__);
$GLOBALS['BLOG_GROUP'] = [];

class blog_group
{
    // ADD, UPDATE, DELETE
    public static function CheckFields($ACTION, &$arFields, $ID = 0)
    {
        if ((is_set($arFields, 'NAME') || 'ADD' === $ACTION) && '' === $arFields['NAME']) {
            $GLOBALS['APPLICATION']->ThrowException(GetMessage('BLG_GG_EMPTY_NAME'), 'EMPTY_NAME');

            return false;
        }
        if (is_set($arFields, 'NAME')) {
            $dbResult = CBlogGroup::GetList([], ['NAME' => $arFields['NAME'], '!ID' => $ID, 'SITE_ID' => $arFields['SITE_ID']], false, false, ['ID']);
            if ($dbResult->Fetch()) {
                $GLOBALS['APPLICATION']->ThrowException(GetMessage('BLG_GG_DUBLICATE_NAME'), 'DUBLICATE_NAME');

                return false;
            }
        }

        if ((is_set($arFields, 'SITE_ID') || 'ADD' === $ACTION) && '' === $arFields['SITE_ID']) {
            $GLOBALS['APPLICATION']->ThrowException(GetMessage('BLG_GG_EMPTY_SITE_ID'), 'EMPTY_SITE_ID');

            return false;
        }
        if (is_set($arFields, 'SITE_ID')) {
            $dbResult = CSite::GetByID($arFields['SITE_ID']);
            if (!$dbResult->Fetch()) {
                $GLOBALS['APPLICATION']->ThrowException(str_replace('#ID#', $arFields['SITE_ID'], GetMessage('BLG_GG_ERROR_NO_SITE')), 'ERROR_NO_SITE');

                return false;
            }
        }

        return true;
    }

    public static function Delete($ID)
    {
        global $DB;

        $ID = (int) $ID;

        $dbResult = CBlog::GetList([], ['GROUP_ID' => $ID], false, false, ['ID']);
        if ($dbResult->Fetch()) {
            $GLOBALS['APPLICATION']->ThrowException(str_replace('#ID#', $ID, GetMessage('BLG_GG_ERROR_NOT_EMPTY')), 'ERROR_NOT_EMPTY');

            return false;
        }

        unset($GLOBALS['BLOG_GROUP']['BLOG_GROUP_CACHE_'.$ID]);

        return $DB->Query('DELETE FROM b_blog_group WHERE ID = '.$ID.'', true);
    }

    // *************** SELECT *********************/
    public static function GetByID($ID)
    {
        global $DB;

        $ID = (int) $ID;
        if ($ID <= 0) {
            return false;
        }

        if (isset($GLOBALS['BLOG_GROUP']['BLOG_GROUP_CACHE_'.$ID]) && is_array($GLOBALS['BLOG_GROUP']['BLOG_GROUP_CACHE_'.$ID]) && is_set($GLOBALS['BLOG_GROUP']['BLOG_GROUP_CACHE_'.$ID], 'ID')) {
            return $GLOBALS['BLOG_GROUP']['BLOG_GROUP_CACHE_'.$ID];
        }

        $strSql =
            'SELECT G.ID, G.NAME, G.SITE_ID '.
            'FROM b_blog_group G '.
            'WHERE G.ID = '.$ID.'';
        $dbResult = $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
        if ($arResult = $dbResult->Fetch()) {
            $GLOBALS['BLOG_GROUP']['BLOG_GROUP_CACHE_'.$ID] = $arResult;

            return $arResult;
        }

        return false;
    }
}
