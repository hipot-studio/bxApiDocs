<?php

IncludeModuleLangFile(__FILE__);
$GLOBALS['BLOG_USER_GROUP_PERMS'] = [];

class blog_user_group_perms
{
    public static function __AutoSetPerms($ID)
    {
        $ID = (int) $ID;

        $arGroupPerms = CBlogUserGroupPerms::GetByID($ID);
        if (0 === (int) $arGroupPerms['POST_ID']) {
            $dbBlogPosts = CBlogPost::GetList(
                [],
                ['BLOG_ID' => $arGroupPerms['BLOG_ID']],
                false,
                false,
                ['ID']
            );
            while ($arBlogPosts = $dbBlogPosts->Fetch()) {
                $dbGroupPerms1 = CBlogUserGroupPerms::GetList(
                    [],
                    [
                        'BLOG_ID' => $arGroupPerms['BLOG_ID'],
                        'USER_GROUP_ID' => $arGroupPerms['USER_GROUP_ID'],
                        'PERMS_TYPE' => $arGroupPerms['PERMS_TYPE'],
                        'POST_ID' => $arBlogPosts['ID'],
                    ],
                    false,
                    false,
                    ['ID', 'AUTOSET', 'PERMS']
                );
                if ($arGroupPerms1 = $dbGroupPerms1->Fetch()) {
                    if ('Y' === $arGroupPerms1['AUTOSET']
                        && $arGroupPerms['PERMS'] !== $arGroupPerms1['PERMS']) {
                        CBlogUserGroupPerms::Update(
                            $arGroupPerms1['ID'],
                            ['PERMS' => $arGroupPerms['PERMS']]
                        );
                    }
                } else {
                    CBlogUserGroupPerms::Add(
                        [
                            'BLOG_ID' => $arGroupPerms['BLOG_ID'],
                            'USER_GROUP_ID' => $arGroupPerms['USER_GROUP_ID'],
                            'PERMS_TYPE' => $arGroupPerms['PERMS_TYPE'],
                            'POST_ID' => $arBlogPosts['ID'],
                            'PERMS' => $arGroupPerms['PERMS'],
                            'AUTOSET' => 'Y',
                        ]
                    );
                }
            }
        }
    }

    // ADD, UPDATE, DELETE
    public static function CheckFields($ACTION, &$arFields, $ID = 0)
    {
        if ((is_set($arFields, 'BLOG_ID') || 'ADD' === $ACTION) && (int) $arFields['BLOG_ID'] <= 0) {
            $GLOBALS['APPLICATION']->ThrowException(GetMessage('BLG_GUGP_EMPTY_BLOG_ID'), 'EMPTY_BLOG_ID');

            return false;
        }
        if (is_set($arFields, 'BLOG_ID')) {
            $arResult = CBlog::GetByID($arFields['BLOG_ID']);
            if (!$arResult) {
                $GLOBALS['APPLICATION']->ThrowException(str_replace('#ID#', $arFields['BLOG_ID'], GetMessage('BLG_GUGP_ERROR_NO_BLOG')), 'ERROR_NO_BLOG');

                return false;
            }
        }

        if ((is_set($arFields, 'USER_GROUP_ID') || 'ADD' === $ACTION) && (int) $arFields['USER_GROUP_ID'] <= 0) {
            $GLOBALS['APPLICATION']->ThrowException(GetMessage('BLG_GUGP_EMPTY_USER_GROUP_ID'), 'EMPTY_USER_GROUP_ID');

            return false;
        }
        if (is_set($arFields, 'USER_GROUP_ID')) {
            $arResult = CBlogUserGroup::GetByID($arFields['USER_GROUP_ID']);
            if (!$arResult) {
                $GLOBALS['APPLICATION']->ThrowException(str_replace('#ID#', $arFields['USER_GROUP_ID'], GetMessage('BLG_GUGP_ERROR_NO_USER_GROUP')), 'ERROR_NO_USER_GROUP');

                return false;
            }
        }

        if ((is_set($arFields, 'PERMS_TYPE') || 'ADD' === $ACTION) && BLOG_PERMS_POST !== $arFields['PERMS_TYPE'] && BLOG_PERMS_COMMENT !== $arFields['PERMS_TYPE']) {
            $GLOBALS['APPLICATION']->ThrowException(GetMessage('BLG_GUGP_EMPTY_PERMS_TYPE'), 'EMPTY_PERMS_TYPE');

            return false;
        }

        if ((is_set($arFields, 'PERMS') || 'ADD' === $ACTION) && '' === $arFields['PERMS']) {
            $GLOBALS['APPLICATION']->ThrowException(GetMessage('BLG_GUGP_EMPTY_PERMS'), 'EMPTY_PERMS');

            return false;
        }
        if (is_set($arFields, 'PERMS')) {
            $arAvailPerms = array_keys($GLOBALS['AR_BLOG_PERMS']);
            if (!in_array($arFields['PERMS'], $arAvailPerms, true)) {
                $GLOBALS['APPLICATION']->ThrowException(str_replace('#ID#', $arFields['PERMS'], GetMessage('BLG_GUGP_ERROR_NO_PERMS')), 'ERROR_NO_PERMS');

                return false;
            }
        }

        if ((is_set($arFields, 'AUTOSET') || 'ADD' === $ACTION) && 'Y' !== $arFields['AUTOSET'] && 'N' !== $arFields['AUTOSET']) {
            $arFields['AUTOSET'] = 'N';
        }

        return true;
    }

    public static function Delete($ID)
    {
        global $DB;

        $ID = (int) $ID;

        $arGroupPerms = CBlogUserGroupPerms::GetByID($ID);
        if (0 === (int) $arGroupPerms['POST_ID']) {
            $dbResult = CBlogUserGroupPerms::GetList(
                [],
                [
                    'BLOG_ID' => $arGroupPerms['BLOG_ID'],
                    'USER_GROUP_ID' => $arGroupPerms['USER_GROUP_ID'],
                    'PERMS_TYPE' => $arGroupPerms['PERMS_TYPE'],
                    '!POST_ID' => 0,
                    'AUTOSET' => 'Y',
                ],
                false,
                false,
                ['ID']
            );
            while ($arResult = $dbResult->Fetch()) {
                CBlogUserGroupPerms::Delete($arResult['ID']);
            }
        }

        unset($GLOBALS['BLOG_USER_GROUP_PERMS']['BLOG_USER_GROUP_PERMS_CACHE_'.$ID]);

        return $DB->Query('DELETE FROM b_blog_user_group_perms WHERE ID = '.$ID.'', true);
    }

    // *************** SELECT *********************/
    public static function GetByID($ID)
    {
        global $DB;

        $ID = (int) $ID;

        if (isset($GLOBALS['BLOG_USER_GROUP_PERMS']['BLOG_USER_GROUP_PERMS_CACHE_'.$ID]) && is_array($GLOBALS['BLOG_USER_GROUP_PERMS']['BLOG_USER_GROUP_PERMS_CACHE_'.$ID]) && is_set($GLOBALS['BLOG_USER_GROUP_PERMS']['BLOG_USER_GROUP_PERMS_CACHE_'.$ID], 'ID')) {
            return $GLOBALS['BLOG_USER_GROUP_PERMS']['BLOG_USER_GROUP_PERMS_CACHE_'.$ID];
        }

        $strSql =
            'SELECT GP.ID, GP.BLOG_ID, GP.USER_GROUP_ID, GP.PERMS_TYPE, GP.POST_ID, '.
            '	GP.PERMS, GP.AUTOSET '.
            'FROM b_blog_user_group_perms GP '.
            'WHERE GP.ID = '.$ID.'';
        $dbResult = $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
        if ($arResult = $dbResult->Fetch()) {
            $GLOBALS['BLOG_USER_GROUP_PERMS']['BLOG_USER_GROUP_PERMS_CACHE_'.$ID] = $arResult;

            return $arResult;
        }

        return false;
    }
}
