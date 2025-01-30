<?php

use Bitrix\Mail\User;
use Bitrix\Main\FinderDestTable;
use Bitrix\Main\Loader;
use Bitrix\Main\UserTable;
use Bitrix\Socialnetwork\ComponentHelper;

IncludeModuleLangFile(__FILE__);

class blog_post
{
    public static $arSocNetPostPermsCache = [];
    public static $arUACCache = [];
    public static $arBlogPostCache = [];
    public static $arBlogPostIdCache = [];
    public static $arBlogPCCache = [];
    public static $arBlogUCache = [];

    public static function __AddSocNetPerms($ID, $entityType, $entityID, $entity)
    {
        global $DB;

        static $allowedTypes = false;

        if (false === $allowedTypes) {
            $allowedTypes = ['D', 'U', 'SG', 'DR', 'G', 'AU'];
            if (IsModuleInstalled('crm')) {
                $allowedTypes[] = 'CRMCONTACT';
            }
        }

        if ((int) $ID > 0 && '' !== $entityType && '' !== $entity && in_array($entityType, $allowedTypes, true)) {
            $arSCFields = ['POST_ID' => $ID, 'ENTITY_TYPE' => $entityType, 'ENTITY_ID' => (int) $entityID, 'ENTITY' => $entity];
            $arSCInsert = $DB->PrepareInsert('b_blog_socnet_rights', $arSCFields);

            if ('' !== $arSCInsert[0]) {
                $strSql =
                    'INSERT INTO b_blog_socnet_rights('.$arSCInsert[0].') '.
                    'VALUES('.$arSCInsert[1].')';
                $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);

                return true;
            }
        }

        return false;
    }

    public static function CanUserEditPost($ID, $userID)
    {
        global $APPLICATION;
        $ID = (int) $ID;
        $userID = (int) $userID;

        $blogModulePermissions = $APPLICATION->GetGroupRight('blog');
        if ($blogModulePermissions >= 'W') {
            return true;
        }

        $arPost = CBlogPost::GetByID($ID);
        if (!$arPost) {
            return false;
        }

        if (CBlog::IsBlogOwner($arPost['BLOG_ID'], $userID)) {
            return true;
        }

        $arBlogUser = CBlogUser::GetByID($userID, BLOG_BY_USER_ID);
        if ($arBlogUser && 'Y' !== $arBlogUser['ALLOW_POST']) {
            return false;
        }

        if (CBlogPost::GetBlogUserPostPerms($ID, $userID) < BLOG_PERMS_WRITE) {
            return false;
        }

        if ($arPost['AUTHOR_ID'] === $userID) {
            return true;
        }

        return false;
    }

    public static function CanUserDeletePost($ID, $userID)
    {
        global $APPLICATION;

        $ID = (int) $ID;
        $userID = (int) $userID;

        $blogModulePermissions = $APPLICATION->GetGroupRight('blog');
        if ($blogModulePermissions >= 'W') {
            return true;
        }

        $arPost = CBlogPost::GetByID($ID);
        if (!$arPost) {
            return false;
        }

        if (CBlog::IsBlogOwner($arPost['BLOG_ID'], $userID)) {
            return true;
        }

        $arBlogUser = CBlogUser::GetByID($userID, BLOG_BY_USER_ID);
        if ($arBlogUser && 'Y' !== $arBlogUser['ALLOW_POST']) {
            return false;
        }

        $perms = CBlogPost::GetBlogUserPostPerms($ID, $userID);
        if ($perms <= BLOG_PERMS_WRITE && $userID !== $arPost['AUTHOR_ID']) {
            return false;
        }

        if ($perms > BLOG_PERMS_WRITE) {
            return true;
        }

        if ($arPost['AUTHOR_ID'] === $userID) {
            return true;
        }

        return false;
    }

    public static function GetBlogUserPostPerms($ID, $userID)
    {
        global $APPLICATION;

        $ID = (int) $ID;
        $userID = (int) $userID;

        $arAvailPerms = array_keys($GLOBALS['AR_BLOG_PERMS']);
        $blogModulePermissions = $APPLICATION->GetGroupRight('blog');
        if ($blogModulePermissions >= 'W') {
            return $arAvailPerms[count($arAvailPerms) - 1];
        }

        $arPost = CBlogPost::GetByID($ID);
        if (!$arPost) {
            return $arAvailPerms[0];
        }

        if (CBlog::IsBlogOwner($arPost['BLOG_ID'], $userID)) {
            return $arAvailPerms[count($arAvailPerms) - 1];
        }

        $arBlogUser = CBlogUser::GetByID($userID, BLOG_BY_USER_ID);
        if ($arBlogUser && 'Y' !== $arBlogUser['ALLOW_POST']) {
            return $arAvailPerms[0];
        }

        $arUserGroups = CBlogUser::GetUserGroups($userID, $arPost['BLOG_ID'], 'Y', BLOG_BY_USER_ID);

        $perms = CBlogUser::GetUserPerms($arUserGroups, $arPost['BLOG_ID'], $ID, BLOG_PERMS_POST, BLOG_BY_USER_ID);
        if ($perms) {
            return $perms;
        }

        return $arAvailPerms[0];
    }

    public static function GetBlogUserCommentPerms($ID, $userID)
    {
        global $APPLICATION;
        $ID = (int) $ID;
        $userID = (int) $userID;

        $arAvailPerms = array_keys($GLOBALS['AR_BLOG_PERMS']);

        $blogModulePermissions = $APPLICATION->GetGroupRight('blog');
        if ($blogModulePermissions >= 'W') {
            return $arAvailPerms[count($arAvailPerms) - 1];
        }

        if ((int) $ID > 0) {
            if (!($arPost = CBlogPost::GetByID($ID))) {
                return $arAvailPerms[0];
            }

            $arBlog = CBlog::GetByID($arPost['BLOG_ID']);
            if ('Y' !== $arBlog['ENABLE_COMMENTS']) {
                return $arAvailPerms[0];
            }

            if (CBlog::IsBlogOwner($arPost['BLOG_ID'], $userID)) {
                return $arAvailPerms[count($arAvailPerms) - 1];
            }

            $arUserGroups = CBlogUser::GetUserGroups($userID, $arPost['BLOG_ID'], 'Y', BLOG_BY_USER_ID);

            $perms = CBlogUser::GetUserPerms($arUserGroups, $arPost['BLOG_ID'], $ID, BLOG_PERMS_COMMENT, BLOG_BY_USER_ID);
            if ($perms) {
                return $perms;
            }
        } else {
            return $arAvailPerms[0];
        }

        if ((int) $userID > 0) {
            $arBlogUser = CBlogUser::GetByID($userID, BLOG_BY_USER_ID);
            if ($arBlogUser && 'Y' !== $arBlogUser['ALLOW_POST']) {
                return $arAvailPerms[0];
            }
        }

        return $arAvailPerms[0];
    }

    // ADD, UPDATE, DELETE
    public static function CheckFields($ACTION, &$arFields, $ID = 0)
    {
        global $DB, $APPLICATION;

        if ((is_set($arFields, 'TITLE') || 'ADD' === $ACTION) && '' === $arFields['TITLE']) {
            $APPLICATION->ThrowException(GetMessage('BLG_GP_EMPTY_TITLE'), 'EMPTY_TITLE');

            return false;
        }

        if ((is_set($arFields, 'DETAIL_TEXT') || 'ADD' === $ACTION) && '' === $arFields['DETAIL_TEXT']) {
            $APPLICATION->ThrowException(GetMessage('BLG_GP_EMPTY_DETAIL_TEXT'), 'EMPTY_DETAIL_TEXT');

            return false;
        }

        if ((is_set($arFields, 'BLOG_ID') || 'ADD' === $ACTION) && (int) $arFields['BLOG_ID'] <= 0) {
            $APPLICATION->ThrowException(GetMessage('BLG_GP_EMPTY_BLOG_ID'), 'EMPTY_BLOG_ID');

            return false;
        }
        if (is_set($arFields, 'BLOG_ID')) {
            $arResult = CBlog::GetByID($arFields['BLOG_ID']);
            if (!$arResult) {
                $APPLICATION->ThrowException(str_replace('#ID#', $arFields['BLOG_ID'], GetMessage('BLG_GP_ERROR_NO_BLOG')), 'ERROR_NO_BLOG');

                return false;
            }
        }

        if ((is_set($arFields, 'AUTHOR_ID') || 'ADD' === $ACTION) && (int) $arFields['AUTHOR_ID'] <= 0) {
            $APPLICATION->ThrowException(GetMessage('BLG_GP_EMPTY_AUTHOR_ID'), 'EMPTY_AUTHOR_ID');

            return false;
        }
        if (is_set($arFields, 'AUTHOR_ID')) {
            $dbResult = CUser::GetByID($arFields['AUTHOR_ID']);
            if (!$dbResult->Fetch()) {
                $APPLICATION->ThrowException(GetMessage('BLG_GP_ERROR_NO_AUTHOR'), 'ERROR_NO_AUTHOR');

                return false;
            }
        }

        if (is_set($arFields, 'DATE_CREATE') && (!$DB->IsDate($arFields['DATE_CREATE'], false, LANG, 'FULL'))) {
            $APPLICATION->ThrowException(GetMessage('BLG_GP_ERROR_DATE_CREATE'), 'ERROR_DATE_CREATE');

            return false;
        }

        if (is_set($arFields, 'DATE_PUBLISH') && (!$DB->IsDate($arFields['DATE_PUBLISH'], false, LANG, 'FULL'))) {
            $APPLICATION->ThrowException(GetMessage('BLG_GP_ERROR_DATE_PUBLISH'), 'ERROR_DATE_PUBLISH');

            return false;
        }

        $arFields['PREVIEW_TEXT_TYPE'] = strtolower($arFields['PREVIEW_TEXT_TYPE']);
        if ((is_set($arFields, 'PREVIEW_TEXT_TYPE') || 'ADD' === $ACTION) && 'text' !== $arFields['PREVIEW_TEXT_TYPE'] && 'html' !== $arFields['PREVIEW_TEXT_TYPE']) {
            $arFields['PREVIEW_TEXT_TYPE'] = 'text';
        }

        // $arFields["DETAIL_TEXT_TYPE"] = strtolower($arFields["DETAIL_TEXT_TYPE"]);
        if ((is_set($arFields, 'DETAIL_TEXT_TYPE') || 'ADD' === $ACTION) && 'text' !== strtolower($arFields['DETAIL_TEXT_TYPE']) && 'html' !== strtolower($arFields['DETAIL_TEXT_TYPE'])) {
            $arFields['DETAIL_TEXT_TYPE'] = 'text';
        }
        if ('' !== $arFields['DETAIL_TEXT_TYPE']) {
            $arFields['DETAIL_TEXT_TYPE'] = strtolower($arFields['DETAIL_TEXT_TYPE']);
        }

        $arStatus = array_keys($GLOBALS['AR_BLOG_PUBLISH_STATUS']);
        if ((is_set($arFields, 'PUBLISH_STATUS') || 'ADD' === $ACTION) && !in_array($arFields['PUBLISH_STATUS'], $arStatus, true)) {
            $arFields['PUBLISH_STATUS'] = $arStatus[0];
        }

        if ((is_set($arFields, 'ENABLE_TRACKBACK') || 'ADD' === $ACTION) && 'Y' !== $arFields['ENABLE_TRACKBACK'] && 'N' !== $arFields['ENABLE_TRACKBACK']) {
            $arFields['ENABLE_TRACKBACK'] = 'Y';
        }

        if ((is_set($arFields, 'ENABLE_COMMENTS') || 'ADD' === $ACTION) && 'Y' !== $arFields['ENABLE_COMMENTS'] && 'N' !== $arFields['ENABLE_COMMENTS']) {
            $arFields['ENABLE_COMMENTS'] = 'Y';
        }

        if (!empty($arFields['ATTACH_IMG'])) {
            $res = CFile::CheckImageFile($arFields['ATTACH_IMG'], 0, 0, 0);
            if ('' !== $res) {
                $APPLICATION->ThrowException(GetMessage('BLG_GP_ERROR_ATTACH_IMG').': '.$res, 'ERROR_ATTACH_IMG');

                return false;
            }
        } else {
            $arFields['ATTACH_IMG'] = false;
        }

        if (is_set($arFields, 'NUM_COMMENTS')) {
            $arFields['NUM_COMMENTS'] = (int) $arFields['NUM_COMMENTS'];
        }
        if (is_set($arFields, 'NUM_TRACKBACKS')) {
            $arFields['NUM_TRACKBACKS'] = (int) $arFields['NUM_TRACKBACKS'];
        }
        if (is_set($arFields, 'FAVORITE_SORT')) {
            $arFields['FAVORITE_SORT'] = (int) $arFields['FAVORITE_SORT'];
            if ($arFields['FAVORITE_SORT'] <= 0) {
                $arFields['FAVORITE_SORT'] = false;
            }
        }

        if (is_set($arFields, 'CODE') && '' !== $arFields['CODE']) {
            $arFields['CODE'] = preg_replace('/[^a-zA-Z0-9_-]/is', '', trim($arFields['CODE']));

            if (in_array(strtolower($arFields['CODE']), $GLOBALS['AR_BLOG_POST_RESERVED_CODES'], true)) {
                $APPLICATION->ThrowException(str_replace('#CODE#', $arFields['CODE'], GetMessage('BLG_GP_RESERVED_CODE')), 'CODE_RESERVED');

                return false;
            }

            $arFilter = [
                'CODE' => $arFields['CODE'],
            ];
            if ((int) $ID > 0) {
                $arPost = CBlogPost::GetByID($ID);
                $arFilter['!ID'] = $arPost['ID'];
                $arFilter['BLOG_ID'] = $arPost['BLOG_ID'];
            } else {
                if ((int) $arFields['BLOG_ID'] > 0) {
                    $arFilter['BLOG_ID'] = $arFields['BLOG_ID'];
                }
            }

            $dbItem = CBlogPost::GetList([], $arFilter, false, ['nTopCount' => 1], ['ID', 'CODE', 'BLOG_ID']);
            if ($dbItem->Fetch()) {
                $APPLICATION->ThrowException(GetMessage('BLG_GP_CODE_EXIST', ['#CODE#' => $arFields['CODE']]), 'CODE_EXIST');

                return false;
            }
        }

        return true;
    }

    public static function SetPostPerms($ID, $arPerms = [], $permsType = BLOG_PERMS_POST)
    {
        global $DB;

        $ID = (int) $ID;
        $permsType = ((BLOG_PERMS_COMMENT === $permsType) ? BLOG_PERMS_COMMENT : BLOG_PERMS_POST);
        if (!is_array($arPerms)) {
            $arPerms = [];
        }

        $arPost = CBlogPost::GetByID($ID);
        if ($arPost) {
            $arInsertedGroups = [];
            foreach ($arPerms as $key => $value) {
                $dbGroupPerms = CBlogUserGroupPerms::GetList(
                    [],
                    [
                        'BLOG_ID' => $arPost['BLOG_ID'],
                        'USER_GROUP_ID' => $key,
                        'PERMS_TYPE' => $permsType,
                        'POST_ID' => $arPost['ID'],
                    ],
                    false,
                    false,
                    ['ID']
                );
                if ($arGroupPerms = $dbGroupPerms->Fetch()) {
                    CBlogUserGroupPerms::Update(
                        $arGroupPerms['ID'],
                        [
                            'PERMS' => $value,
                            'AUTOSET' => 'N',
                        ]
                    );
                } else {
                    CBlogUserGroupPerms::Add(
                        [
                            'BLOG_ID' => $arPost['BLOG_ID'],
                            'USER_GROUP_ID' => $key,
                            'PERMS_TYPE' => $permsType,
                            'POST_ID' => $arPost['ID'],
                            'AUTOSET' => 'N',
                            'PERMS' => $value,
                        ]
                    );
                }

                $arInsertedGroups[] = $key;
            }

            $dbResult = CBlogUserGroupPerms::GetList(
                [],
                [
                    'BLOG_ID' => $arPost['BLOG_ID'],
                    'PERMS_TYPE' => $permsType,
                    'POST_ID' => 0,
                    '!USER_GROUP_ID' => $arInsertedGroups,
                ],
                false,
                false,
                ['ID', 'USER_GROUP_ID', 'PERMS']
            );
            while ($arResult = $dbResult->Fetch()) {
                $dbGroupPerms = CBlogUserGroupPerms::GetList(
                    [],
                    [
                        'BLOG_ID' => $arPost['BLOG_ID'],
                        'USER_GROUP_ID' => $arResult['USER_GROUP_ID'],
                        'PERMS_TYPE' => $permsType,
                        'POST_ID' => $arPost['ID'],
                    ],
                    false,
                    false,
                    ['ID']
                );
                if ($arGroupPerms = $dbGroupPerms->Fetch()) {
                    CBlogUserGroupPerms::Update(
                        $arGroupPerms['ID'],
                        [
                            'PERMS' => $arResult['PERMS'],
                            'AUTOSET' => 'Y',
                        ]
                    );
                } else {
                    CBlogUserGroupPerms::Add(
                        [
                            'BLOG_ID' => $arPost['BLOG_ID'],
                            'USER_GROUP_ID' => $arResult['USER_GROUP_ID'],
                            'PERMS_TYPE' => $permsType,
                            'POST_ID' => $arPost['ID'],
                            'AUTOSET' => 'Y',
                            'PERMS' => $arResult['PERMS'],
                        ]
                    );
                }
            }
        }
    }

    public static function Delete($ID)
    {
        global $DB, $CACHE_MANAGER, $USER_FIELD_MANAGER;

        $ID = (int) $ID;

        $arPost = CBlogPost::GetByID($ID);
        if ($arPost) {
            foreach (GetModuleEvents('blog', 'OnBeforePostDelete', true) as $arEvent) {
                if (false === ExecuteModuleEventEx($arEvent, [$ID])) {
                    return false;
                }
            }

            $dbResult = CBlogComment::GetList(
                [],
                ['POST_ID' => $ID],
                false,
                false,
                ['ID']
            );
            while ($arResult = $dbResult->Fetch()) {
                if (!CBlogComment::Delete($arResult['ID'])) {
                    return false;
                }
            }

            $dbResult = CBlogUserGroupPerms::GetList(
                [],
                ['POST_ID' => $ID, 'BLOG_ID' => $arPost['BLOG_ID']],
                false,
                false,
                ['ID']
            );
            while ($arResult = $dbResult->Fetch()) {
                if (!CBlogUserGroupPerms::Delete($arResult['ID'])) {
                    return false;
                }
            }

            $dbResult = CBlogTrackback::GetList(
                [],
                ['POST_ID' => $ID, 'BLOG_ID' => $arPost['BLOG_ID']],
                false,
                false,
                ['ID']
            );
            while ($arResult = $dbResult->Fetch()) {
                if (!CBlogTrackback::Delete($arResult['ID'])) {
                    return false;
                }
            }

            $dbResult = CBlogPostCategory::GetList(
                [],
                ['POST_ID' => $ID, 'BLOG_ID' => $arPost['BLOG_ID']],
                false,
                false,
                ['ID']
            );
            while ($arResult = $dbResult->Fetch()) {
                if (!CBlogPostCategory::Delete($arResult['ID'])) {
                    return false;
                }
            }

            $strSql =
                'SELECT F.ID '.
                'FROM b_blog_post P, b_file F '.
                'WHERE P.ID = '.$ID.' '.
                '	AND P.ATTACH_IMG = F.ID ';
            $z = $DB->Query($strSql, false, 'FILE: '.__FILE__.' LINE:'.__LINE__);
            while ($zr = $z->Fetch()) {
                CFile::Delete($zr['ID']);
            }

            CBlogPost::DeleteSocNetPostPerms($ID);

            unset(static::$arBlogPostCache[$ID]);

            $arBlog = CBlog::GetByID($arPost['BLOG_ID']);

            $result = $DB->Query('DELETE FROM b_blog_post WHERE ID = '.$ID, true);

            if ((int) $arBlog['LAST_POST_ID'] === $ID) {
                CBlog::SetStat($arPost['BLOG_ID']);
            }

            if ($result) {
                $res = CBlogImage::GetList([], ['POST_ID' => $ID, 'IS_COMMENT' => 'N']);
                while ($aImg = $res->Fetch()) {
                    CBlogImage::Delete($aImg['ID']);
                }
            }
            if ($result) {
                $USER_FIELD_MANAGER->Delete('BLOG_POST', $ID);
            }

            foreach (GetModuleEvents('blog', 'OnPostDelete', true) as $arEvent) {
                ExecuteModuleEventEx($arEvent, [$ID, &$result]);
            }

            if (CModule::IncludeModule('search')) {
                CSearch::Index(
                    'blog',
                    'P'.$ID,
                    [
                        'TITLE' => '',
                        'BODY' => '',
                    ]
                );
                // CSearch::DeleteIndex("blog", false, "COMMENT", $arPost["BLOG_ID"]."|".$ID);
            }
            if (defined('BX_COMP_MANAGED_CACHE')) {
                $CACHE_MANAGER->ClearByTag('blog_post_'.$ID);
            }

            return $result;
        }

        return false;
    }

    // *************** SELECT *********************/
    public static function PreparePath($blogUrl, $postID = 0, $siteID = false, $is404 = true, $userID = 0, $groupID = 0)
    {
        $blogUrl = trim($blogUrl);
        $postID = (int) $postID;
        $groupID = (int) $groupID;
        $userID = (int) $userID;

        if (!$siteID) {
            $siteID = SITE_ID;
        }

        $dbPath = CBlogSitePath::GetList([], ['SITE_ID' => $siteID]);
        while ($arPath = $dbPath->Fetch()) {
            if ('' !== $arPath['TYPE']) {
                $arPaths[$arPath['TYPE']] = $arPath['PATH'];
            } else {
                $arPaths['OLD'] = $arPath['PATH'];
            }
        }

        if ($postID > 0) {
            if ($groupID > 0) {
                if ('' !== $arPaths['H']) {
                    $result = str_replace('#blog#', $blogUrl, $arPaths['H']);
                    $result = str_replace('#post_id#', $postID, $result);
                    $result = str_replace('#user_id#', $userID, $result);
                    $result = str_replace('#group_id#', $groupID, $result);
                } elseif ('' !== $arPaths['G']) {
                    $result = str_replace('#blog#', $blogUrl, $arPaths['G']);
                    $result = str_replace('#user_id#', $userID, $result);
                    $result = str_replace('#group_id#', $groupID, $result);
                }
            } elseif ('' !== $arPaths['P']) {
                $result = str_replace('#blog#', $blogUrl, $arPaths['P']);
                $result = str_replace('#post_id#', $postID, $result);
                $result = str_replace('#user_id#', $userID, $result);
            } elseif ('' !== $arPaths['B']) {
                $result = str_replace('#blog#', $blogUrl, $arPaths['B']);
                $result = str_replace('#user_id#', $userID, $result);
            } else {
                if ($is404) {
                    $result = htmlspecialcharsbx($arPaths['OLD']).'/'.htmlspecialcharsbx($blogUrl).'/'.$postID.'.php';
                } else {
                    $result = htmlspecialcharsbx($arPaths['OLD']).'/post.php?blog='.$blogUrl.'&post_id='.$postID;
                }
            }
        } else {
            if ('' !== $arPaths['B']) {
                $result = str_replace('#blog#', $blogUrl, $arPaths['B']);
                $result = str_replace('#user_id#', $userID, $result);
            } else {
                if ($is404) {
                    $result = htmlspecialcharsbx($arPaths['OLD']).'/'.htmlspecialcharsbx($blogUrl).'/';
                } else {
                    $result = htmlspecialcharsbx($arPaths['OLD']).'/post.php?blog='.$blogUrl;
                }
            }
        }

        return $result;
    }

    public static function PreparePath2Post($realUrl, $url, $arParams = [])
    {
        return CBlogPost::PreparePath(
            $url,
            isset($arParams['POST_ID']) ? $arParams['POST_ID'] : 0,
            isset($arParams['SITE_ID']) ? $arParams['SITE_ID'] : false
        );
    }

    public static function CounterInc($ID)
    {
        global $DB;
        $ID = (int) $ID;
        if (!is_array($_SESSION['BLOG_COUNTER'])) {
            $_SESSION['BLOG_COUNTER'] = [];
        }
        if (in_array($ID, $_SESSION['BLOG_COUNTER'], true)) {
            return;
        }
        $_SESSION['BLOG_COUNTER'][] = $ID;
        $strSql =
            'UPDATE b_blog_post SET '.
            '	VIEWS =  '.$DB->IsNull('VIEWS', 0).' + 1 '.
            'WHERE ID='.$ID;
        $DB->Query($strSql);
    }

    public static function Notify($arPost, $arBlog, $arParams)
    {
        global $DB;
        if (empty($arBlog)) {
            $arBlog = CBlog::GetByID($arPost['BLOG_ID']);
        }

        $arImages = $arOwner = [];
        $parserBlog = false;
        $text4mail = $serverName = $AuthorName = '';

        if ($arParams['bSoNet'] || ('Y' === $arBlog['EMAIL_NOTIFY'] && $arParams['user_id'] !== $arBlog['OWNER_ID'])) {
            $BlogUser = CBlogUser::GetByID($arParams['user_id'], BLOG_BY_USER_ID);
            $BlogUser = CBlogTools::htmlspecialcharsExArray($BlogUser);
            $res = CUser::GetByID($arBlog['OWNER_ID']);
            $arOwner = $res->GetNext();
            $dbUser = CUser::GetByID($arParams['user_id']);
            $arUser = $dbUser->Fetch();
            $AuthorName = CBlogUser::GetUserNameEx($arUser, $BlogUser, $arParams);
            $parserBlog = new blogTextParser(false, $arParams['PATH_TO_SMILE']);
            $text4mail = $arPost['DETAIL_TEXT'];
            if ('html' === $arPost['DETAIL_TEXT_TYPE']) {
                $text4mail = HTMLToTxt($text4mail);
            }

            $res = CBlogImage::GetList(['ID' => 'ASC'], ['POST_ID' => $arPost['ID'], 'BLOG_ID' => $arBlog['ID'], 'IS_COMMENT' => 'N']);
            while ($arImage = $res->Fetch()) {
                $arImages[$arImage['ID']] = $arImage['FILE_ID'];
            }

            $text4mail = $parserBlog->convert4mail($text4mail, $arImages);
            $serverName = ((defined('SITE_SERVER_NAME') && SITE_SERVER_NAME !== '') ? SITE_SERVER_NAME : COption::GetOptionString('main', 'server_name', ''));
        }

        if (!$arParams['bSoNet'] && 'Y' === $arBlog['EMAIL_NOTIFY'] && $arParams['user_id'] !== $arBlog['OWNER_ID'] && (int) $arBlog['OWNER_ID'] > 0) { // Send notification to email
            CEvent::Send(
                'NEW_BLOG_MESSAGE',
                SITE_ID,
                [
                    'BLOG_ID' => $arBlog['ID'],
                    'BLOG_NAME' => htmlspecialcharsBack($arBlog['NAME']),
                    'BLOG_URL' => $arBlog['URL'],
                    'MESSAGE_TITLE' => $arPost['TITLE'],
                    'MESSAGE_TEXT' => $text4mail,
                    'MESSAGE_DATE' => GetTime(MakeTimeStamp($arPost['DATE_PUBLISH']) - CTimeZone::GetOffset(), 'FULL'),
                    'MESSAGE_PATH' => 'http://'.$serverName.CComponentEngine::MakePathFromTemplate(htmlspecialcharsBack($arParams['PATH_TO_POST']), ['blog' => $arBlog['URL'], 'post_id' => $arPost['ID'], 'user_id' => $arBlog['OWNER_ID'], 'group_id' => $arParams['SOCNET_GROUP_ID']]),
                    'AUTHOR' => $AuthorName,
                    'EMAIL_FROM' => COption::GetOptionString('main', 'email_from', 'nobody@nobody.com'),
                    'EMAIL_TO' => $arOwner['EMAIL'],
                ]
            );
        }

        if (
            $arParams['bSoNet'] && $arPost['ID']
            && CModule::IncludeModule('socialnetwork')
            && $parserBlog
        ) {
            if ('html' === $arPost['DETAIL_TEXT_TYPE'] && 'Y' === $arParams['allowHTML'] && 'Y' === $arBlog['ALLOW_HTML']) {
                $arAllow = ['HTML' => 'Y', 'ANCHOR' => 'Y', 'IMG' => 'Y', 'SMILES' => 'N', 'NL2BR' => 'N', 'VIDEO' => 'Y', 'QUOTE' => 'Y', 'CODE' => 'Y'];
                if ('Y' !== $arParams['allowVideo']) {
                    $arAllow['VIDEO'] = 'N';
                }
                $text4message = $parserBlog->convert($arPost['DETAIL_TEXT'], false, $arImages, $arAllow);
            } else {
                $arAllow = ['HTML' => 'N', 'ANCHOR' => 'N', 'BIU' => 'N', 'IMG' => 'N', 'QUOTE' => 'N', 'CODE' => 'N', 'FONT' => 'N', 'TABLE' => 'N', 'LIST' => 'N', 'SMILES' => 'N', 'NL2BR' => 'N', 'VIDEO' => 'N'];
                $text4message = $parserBlog->convert($arPost['DETAIL_TEXT'], false, $arImages, $arAllow, ['isSonetLog' => true]);
            }

            $arSoFields = [
                'EVENT_ID' => ((int) $arPost['UF_BLOG_POST_IMPRTNT'] > 0 ? 'blog_post_important' : 'blog_post'),
                '=LOG_DATE' => (
                    strlen($arPost['DATE_PUBLISH']) > 0
                        ? (
                            MakeTimeStamp($arPost['DATE_PUBLISH'], CSite::GetDateFormat('FULL', SITE_ID)) > time() + CTimeZone::GetOffset()
                                ? $DB->CharToDateFunction($arPost['DATE_PUBLISH'], 'FULL', SITE_ID)
                                : $DB->CurrentTimeFunction()
                        )
                        :
                        $DB->CurrentTimeFunction()
                ),
                'TITLE_TEMPLATE' => '#USER_NAME# '.GetMessage('BLG_SONET_TITLE'),
                'TITLE' => $arPost['TITLE'],
                'MESSAGE' => $text4message,
                'TEXT_MESSAGE' => $text4mail,
                'MODULE_ID' => 'blog',
                'CALLBACK_FUNC' => false,
                'SOURCE_ID' => $arPost['ID'],
                'ENABLE_COMMENTS' => (array_key_exists('ENABLE_COMMENTS', $arPost) && 'N' === $arPost['ENABLE_COMMENTS'] ? 'N' : 'Y'),
            ];

            $arSoFields['RATING_TYPE_ID'] = 'BLOG_POST';
            $arSoFields['RATING_ENTITY_ID'] = (int) $arPost['ID'];

            if ($arParams['bGroupMode']) {
                $arSoFields['ENTITY_TYPE'] = SONET_ENTITY_GROUP;
                $arSoFields['ENTITY_ID'] = $arParams['SOCNET_GROUP_ID'];
                $arSoFields['URL'] = CComponentEngine::MakePathFromTemplate($arParams['PATH_TO_POST'], ['blog' => $arBlog['URL'], 'user_id' => $arBlog['OWNER_ID'], 'group_id' => $arParams['SOCNET_GROUP_ID'], 'post_id' => $arPost['ID']]);
            } else {
                $arSoFields['ENTITY_TYPE'] = SONET_ENTITY_USER;
                $arSoFields['ENTITY_ID'] = $arBlog['OWNER_ID'];
                $arSoFields['URL'] = CComponentEngine::MakePathFromTemplate($arParams['PATH_TO_POST'], ['blog' => $arBlog['URL'], 'user_id' => $arBlog['OWNER_ID'], 'group_id' => $arParams['SOCNET_GROUP_ID'], 'post_id' => $arPost['ID']]);
            }

            if ((int) $arParams['user_id'] > 0) {
                $arSoFields['USER_ID'] = $arParams['user_id'];
            }

            $logID = CSocNetLog::Add($arSoFields, false);

            if ((int) $logID > 0) {
                $socnetPerms = CBlogPost::GetSocNetPermsCode($arPost['ID']);
                if (!in_array('U'.$arPost['AUTHOR_ID'], $socnetPerms, true)) {
                    $socnetPerms[] = 'U'.$arPost['AUTHOR_ID'];
                }
                $socnetPerms[] = 'SA'; // socnet admin

                if (
                    in_array('AU', $socnetPerms, true)
                    || in_array('G2', $socnetPerms, true)
                ) {
                    $socnetPermsAdd = [];

                    foreach ($socnetPerms as $perm_tmp) {
                        if (preg_match('/^SG(\d+)$/', $perm_tmp, $matches)) {
                            if (
                                !in_array('SG'.$matches[1].'_'.SONET_ROLES_USER, $socnetPerms, true)
                                && !in_array('SG'.$matches[1].'_'.SONET_ROLES_MODERATOR, $socnetPerms, true)
                                && !in_array('SG'.$matches[1].'_'.SONET_ROLES_OWNER, $socnetPerms, true)
                            ) {
                                $socnetPermsAdd[] = 'SG'.$matches[1].'_'.SONET_ROLES_USER;
                            }
                        }
                    }
                    if (count($socnetPermsAdd) > 0) {
                        $socnetPerms = array_merge($socnetPerms, $socnetPermsAdd);
                    }
                }

                CSocNetLog::Update($logID, ['TMP_ID' => $logID]);
                if (CModule::IncludeModule('extranet')) {
                    CSocNetLog::Update($logID, [
                        'SITE_ID' => CExtranet::GetSitesByLogDestinations($socnetPerms, $arPost['AUTHOR_ID'], SITE_ID),
                    ]);
                }

                CSocNetLogRights::DeleteByLogID($logID);
                CSocNetLogRights::Add($logID, $socnetPerms);

                if (Loader::includeModule('crm')) {
                    CCrmLiveFeedComponent::processCrmBlogPostRights($logID, $arSoFields, $arPost, 'new');
                }

                FinderDestTable::merge([
                    'CONTEXT' => 'blog_post',
                    'CODE' => FinderDestTable::convertRights($socnetPerms, ['U'.$arPost['AUTHOR_ID']]),
                ]);

                $arUsrId = [];
                $bForAll = (in_array('AU', $socnetPerms, true) || in_array('G2', $socnetPerms, true));
                if (!$bForAll) {
                    foreach ($socnetPerms as $code) {
                        if (preg_match('/^U(\d+)$/', $code, $matches)) {
                            $arUsrId[] = $matches[1];
                        } elseif (!in_array($code, ['SA'], true)) {
                            $arUsrId = [];

                            break;
                        }
                    }
                }

                CSocNetLog::CounterIncrement([
                    'ENTITY_ID' => $logID,
                    'EVENT_ID' => $arSoFields['EVENT_ID'],
                    'TYPE' => 'L',
                    'FOR_ALL_ACCESS' => $bForAll,
                    'USERS_TO_PUSH' => (
                        $bForAll
                        || empty($arUsrId)
                        || count($arUsrId) > 20
                            ? []
                            : $arUsrId
                    ),
                    'SEND_TO_AUTHOR' => (
                        !empty($arParams['SEND_COUNTER_TO_AUTHOR'])
                        && 'Y' === $arParams['SEND_COUNTER_TO_AUTHOR']
                            ? 'Y'
                            : 'N'
                    ),
                ]);

                return $logID;
            }
        }
    }

    public static function UpdateLog($postID, $arPost, $arBlog, $arParams)
    {
        if (!CModule::IncludeModule('socialnetwork')) {
            return;
        }

        $parserBlog = new blogTextParser(false, $arParams['PATH_TO_SMILE']);

        preg_match('#^(.*?)<cut[\\s]*(/>|>).*?$#is', $arPost['DETAIL_TEXT'], $arMatches);
        if (count($arMatches) <= 0) {
            preg_match('#^(.*?)\\[cut[\\s]*(/\\]|\\]).*?$#is', $arPost['DETAIL_TEXT'], $arMatches);
        }

        $cut_suffix = (count($arMatches) > 0 ? '#CUT#' : '');

        $arImages = [];
        $res = CBlogImage::GetList(['ID' => 'ASC'], ['POST_ID' => $postID, 'BLOG_ID' => $arBlog['ID'], 'IS_COMMENT' => 'N']);
        while ($arImage = $res->Fetch()) {
            $arImages[$arImage['ID']] = $arImage['FILE_ID'];
        }

        if ('html' === $arPost['DETAIL_TEXT_TYPE'] && 'Y' === $arParams['allowHTML'] && 'Y' === $arBlog['ALLOW_HTML']) {
            $arAllow = ['HTML' => 'Y', 'ANCHOR' => 'Y', 'IMG' => 'Y', 'SMILES' => 'N', 'NL2BR' => 'N', 'VIDEO' => 'Y', 'QUOTE' => 'Y', 'CODE' => 'Y'];
            if ('Y' !== $arParams['allowVideo']) {
                $arAllow['VIDEO'] = 'N';
            }
            $text4message = $parserBlog->convert($arPost['DETAIL_TEXT'], true, $arImages, $arAllow);
        } else {
            $arAllow = ['HTML' => 'N', 'ANCHOR' => 'N', 'BIU' => 'N', 'IMG' => 'N', 'QUOTE' => 'N', 'CODE' => 'N', 'FONT' => 'N', 'TABLE' => 'N', 'LIST' => 'N', 'SMILES' => 'N', 'NL2BR' => 'N', 'VIDEO' => 'N'];
            $text4message = $parserBlog->convert($arPost['DETAIL_TEXT'], true, $arImages, $arAllow, ['isSonetLog' => true]);
        }

        $text4message .= $cut_suffix;

        $arSoFields = [
            'TITLE_TEMPLATE' => '#USER_NAME# '.GetMessage('BLG_SONET_TITLE'),
            'TITLE' => $arPost['TITLE'],
            'MESSAGE' => $text4message,
            'TEXT_MESSAGE' => $text4message,
            'ENABLE_COMMENTS' => (array_key_exists('ENABLE_COMMENTS', $arPost) && 'N' === $arPost['ENABLE_COMMENTS'] ? 'N' : 'Y'),
            'EVENT_ID' => ((int) $arPost['UF_BLOG_POST_IMPRTNT'] > 0 ? 'blog_post_important' : 'blog_post'),
        ];

        $dbRes = CSocNetLog::GetList(
            ['ID' => 'DESC'],
            [
                'EVENT_ID' => ['blog_post', 'blog_post_important'],
                'SOURCE_ID' => $postID,
            ],
            false,
            false,
            ['ID', 'ENTITY_TYPE', 'ENTITY_ID', 'EVENT_ID', 'USER_ID']
        );
        if ($arLog = $dbRes->Fetch()) {
            CSocNetLog::Update($arLog['ID'], $arSoFields);
            $socnetPerms = CBlogPost::GetSocNetPermsCode($postID);
            if (!in_array('U'.$arPost['AUTHOR_ID'], $socnetPerms, true)) {
                $socnetPerms[] = 'U'.$arPost['AUTHOR_ID'];
            }
            if (CModule::IncludeModule('extranet')) {
                CSocNetLog::Update($arLog['ID'], [
                    'SITE_ID' => CExtranet::GetSitesByLogDestinations($socnetPerms, $arPost['AUTHOR_ID']),
                ]);
            }
            $socnetPerms[] = 'SA'; // socnet admin
            CSocNetLogRights::DeleteByLogID($arLog['ID']);
            CSocNetLogRights::Add($arLog['ID'], $socnetPerms);

            if (Loader::includeModule('crm')) {
                CCrmLiveFeedComponent::processCrmBlogPostRights($arLog['ID'], $arLog, $arPost, 'edit');
            }
        }
    }

    public static function DeleteLog($postID, $bMicroblog = false)
    {
        if (!CModule::IncludeModule('socialnetwork')) {
            return;
        }

        $dbComment = CBlogComment::GetList(
            [],
            [
                'POST_ID' => $postID,
            ],
            false,
            false,
            ['ID']
        );

        while ($arComment = $dbComment->Fetch()) {
            $dbRes = CSocNetLog::GetList(
                ['ID' => 'DESC'],
                [
                    'EVENT_ID' => ['blog_comment', 'blog_comment_micro'],
                    'SOURCE_ID' => $arComment['ID'],
                ],
                false,
                false,
                ['ID']
            );
            while ($arRes = $dbRes->Fetch()) {
                CSocNetLog::Delete($arRes['ID']);
            }
        }

        $dbRes = CSocNetLog::GetList(
            ['ID' => 'DESC'],
            [
                'EVENT_ID' => ['blog_post_micro', 'blog_post', 'blog_post_important'],
                'SOURCE_ID' => $postID,
            ],
            false,
            false,
            ['ID']
        );
        while ($arRes = $dbRes->Fetch()) {
            CSocNetLog::Delete($arRes['ID']);
        }
    }

    public static function GetID($code, $blogID)
    {
        $postID = false;
        $blogID = (int) $blogID;

        $code = preg_replace('/[^a-zA-Z0-9_-]/is', '', trim($code));
        if ('' === $code || (int) $blogID <= 0) {
            return false;
        }

        if (
            !empty(static::$arBlogPostIdCache[$blogID.'_'.$code])
            && (int) static::$arBlogPostIdCache[$blogID.'_'.$code] > 0) {
            return static::$arBlogPostIdCache[$blogID.'_'.$code];
        }

        $arFilter = ['CODE' => $code];
        if ((int) $blogID > 0) {
            $arFilter['BLOG_ID'] = $blogID;
        }
        $dbPost = CBlogPost::GetList([], $arFilter, false, ['nTopCount' => 1], ['ID']);
        if ($arPost = $dbPost->Fetch()) {
            static::$arBlogPostIdCache[$blogID.'_'.$code] = $arPost['ID'];
            $postID = $arPost['ID'];
        }

        return $postID;
    }

    public static function GetPostID($postID, $code, $allowCode = false)
    {
        $postID = (int) $postID;
        $code = preg_replace('/[^a-zA-Z0-9_-]/is', '', trim($code));
        if ('' === $code && (int) $postID <= 0) {
            return false;
        }

        if ($allowCode && '' !== $code) {
            return $code;
        }

        return $postID;
    }

    public static function AddSocNetPerms($ID, $perms = false, $arPost = [])
    {
        global $CACHE_MANAGER;

        if ((int) $ID <= 0) {
            return false;
        }

        $arResult = [];

        // D - department
        // U - user
        // SG - socnet group
        // DR - department and hier
        // G - user group
        // AU - authorized user
        // CRMCONTACT - CRM contact
        // $bAU = false;

        if (empty($perms) || in_array('UA', $perms, true)) {// if default rights or for everyone
            CBlogPost::__AddSocNetPerms($ID, 'U', $arPost['AUTHOR_ID'], 'US'.$arPost['AUTHOR_ID']); // for myself
            $perms1 = CBlogPost::GetSocnetGroups('U', $arPost['AUTHOR_ID']);
            foreach ($perms1 as $val) {
                if ('' !== $val) {
                    CBlogPost::__AddSocNetPerms($ID, 'U', $arPost['AUTHOR_ID'], $val);

                    if (!in_array($val, $arResult, true)) {
                        $arResult[] = $val;
                    }
                }
            }
        }
        if (!empty($perms)) {
            foreach ($perms as $val) {
                if ('UA' === $val) {
                    continue;
                }

                if ('' !== $val) {
                    if (
                        preg_match('/^(CRMCONTACT)(\d+)$/i', $val, $matches)
                        || preg_match('/^(DR)(\d+)$/i', $val, $matches)
                        || preg_match('/^(SG)(\d+)$/i', $val, $matches)
                        || preg_match('/^(AU)(\d+)$/i', $val, $matches)
                        || preg_match('/^(U)(\d+)$/i', $val, $matches)
                        || preg_match('/^(D)(\d+)$/i', $val, $matches)
                        || preg_match('/^(G)(\d+)$/i', $val, $matches)
                    ) {
                        $scT = $matches[1];
                        $scID = $matches[2];
                    }

                    if ('SG' === $scT) {
                        $permsNew = CBlogPost::GetSocnetGroups('G', $scID);
                        foreach ($permsNew as $val1) {
                            CBlogPost::__AddSocNetPerms($ID, $scT, $scID, $val1);
                            if (!in_array($val1, $arResult, true)) {
                                $arResult[] = $val1;
                            }
                        }
                    }

                    CBlogPost::__AddSocNetPerms($ID, $scT, $scID, $val);
                    if (!in_array($val, $arResult, true)) {
                        $arResult[] = $val;
                    }
                }
            }
        }

        BXClearCache(true, '/blog/getsocnetperms/'.$ID.'/');
        if (defined('BX_COMP_MANAGED_CACHE')) {
            $CACHE_MANAGER->ClearByTag('blog_post_getsocnetperms_'.$ID);
        }

        return $arResult;
    }

    public static function UpdateSocNetPerms($ID, $perms = false, $arPost = [])
    {
        global $DB;
        $ID = (int) $ID;
        if ($ID <= 0) {
            return false;
        }

        $strSql = 'DELETE FROM b_blog_socnet_rights WHERE POST_ID='.$ID;
        $dbRes = $DB->Query($strSql);

        return CBlogPost::AddSocNetPerms($ID, $perms, $arPost);
    }

    public static function GetSocNetGroups($entity_type, $entity_id, $operation = 'view_post')
    {
        $entity_id = (int) $entity_id;
        if ($entity_id <= 0) {
            return false;
        }
        if (!CModule::IncludeModule('socialnetwork')) {
            return false;
        }
        $feature = 'blog';

        $arResult = [];

        if ('G' === $entity_type) {
            $prefix = 'SG'.$entity_id.'_';
            $letter = CSocNetFeaturesPerms::GetOperationPerm(SONET_ENTITY_GROUP, $entity_id, $feature, $operation);

            switch ($letter) {
                case 'N':// All
                    $arResult[] = 'G2';

                    break;

                case 'L':// Authorized
                    $arResult[] = 'AU';

                    break;

                case 'K':// Group members includes moderators and admins
                    $arResult[] = $prefix.'K';
                    $arResult[] = $prefix.'E';
                    $arResult[] = $prefix.'A';

                    break;

                case 'E':// Moderators includes admins
                    $arResult[] = $prefix.'E';
                    $arResult[] = $prefix.'A';

                    break;

                case 'A':// Admins
                    $arResult[] = $prefix.'A';

                    break;
            }
        } else {
            $prefix = 'SU'.$entity_id.'_';
            $letter = CSocNetFeaturesPerms::GetOperationPerm(SONET_ENTITY_USER, $entity_id, $feature, $operation);

            switch ($letter) {
                case 'A':// All
                    $arResult[] = 'G2';

                    break;

                case 'C':// Authorized
                    $arResult[] = 'AU';

                    break;

                case 'E':// Friends of friends (has no rights yet) so it counts as
                case 'M':// Friends
                    $arResult[] = $prefix.'M';

                    break;

                case 'Z':// Personal
                    $arResult[] = $prefix.'Z';

                    break;
            }
        }

        return $arResult;
    }

    public static function GetSocNetPerms($ID)
    {
        global $DB, $CACHE_MANAGER;
        $ID = (int) $ID;
        if ($ID <= 0) {
            return false;
        }

        $arResult = [];

        $cacheTtl = defined('BX_COMP_MANAGED_CACHE') ? 3_153_600 : 3_600 * 4;
        $cacheId = 'blog_post_getsocnetperms_'.$ID;
        $cacheDir = '/blog/getsocnetperms/'.$ID;

        $obCache = new CPHPCache();
        if ($obCache->InitCache($cacheTtl, $cacheId, $cacheDir)) {
            $arResult = $obCache->GetVars();
        } else {
            $obCache->StartDataCache();

            $strSql = 'SELECT SR.ENTITY_ID, SR.ENTITY_TYPE, SR.ENTITY FROM b_blog_socnet_rights SR
				INNER JOIN b_blog_post P ON (P.ID = SR.POST_ID)
				WHERE SR.POST_ID='.$ID.' ORDER BY SR.ENTITY ASC';
            $dbRes = $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
            while ($arRes = $dbRes->Fetch()) {
                $arResult[$arRes['ENTITY_TYPE']][$arRes['ENTITY_ID']][] = $arRes['ENTITY'];
            }

            if (defined('BX_COMP_MANAGED_CACHE')) {
                $CACHE_MANAGER->StartTagCache($cacheDir);
                $CACHE_MANAGER->RegisterTag('blog_post_getsocnetperms_'.$ID);
                $CACHE_MANAGER->EndTagCache();
            }
            $obCache->EndDataCache($arResult);
        }

        return $arResult;
    }

    public static function GetSocNetPermsName($ID)
    {
        global $DB;
        $ID = (int) $ID;
        if ($ID <= 0) {
            return false;
        }

        $arResult = [];
        $strSql = "SELECT SR.ENTITY_TYPE, SR.ENTITY_ID, SR.ENTITY,
						U.NAME as U_NAME, U.LAST_NAME as U_LAST_NAME, U.SECOND_NAME as U_SECOND_NAME, U.LOGIN as U_LOGIN, U.PERSONAL_PHOTO as U_PERSONAL_PHOTO, U.EXTERNAL_AUTH_ID as U_EXTERNAL_AUTH_ID,
						EL.NAME as EL_NAME
					FROM b_blog_socnet_rights SR
					INNER JOIN b_blog_post P
						ON (P.ID = SR.POST_ID)
					LEFT JOIN b_user U
						ON (U.ID = SR.ENTITY_ID AND SR.ENTITY_TYPE = 'U')
					LEFT JOIN b_iblock_section EL
						ON (EL.ID = SR.ENTITY_ID AND SR.ENTITY_TYPE = 'DR' AND EL.ACTIVE = 'Y')
					WHERE
						SR.POST_ID = ".$ID;
        $dbRes = $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
        while ($arRes = $dbRes->GetNext()) {
            if (!is_array($arResult[$arRes['ENTITY_TYPE']][$arRes['ENTITY_ID']])) {
                $arResult[$arRes['ENTITY_TYPE']][$arRes['ENTITY_ID']] = $arRes;
            }
            if (!is_array($arResult[$arRes['ENTITY_TYPE']][$arRes['ENTITY_ID']]['ENTITY'])) {
                $arResult[$arRes['ENTITY_TYPE']][$arRes['ENTITY_ID']]['ENTITY'] = [];
            }
            $arResult[$arRes['ENTITY_TYPE']][$arRes['ENTITY_ID']]['ENTITY'][] = $arRes['ENTITY'];
        }

        return $arResult;
    }

    public static function GetSocNetPermsCode($ID)
    {
        global $DB;
        $ID = (int) $ID;
        if ($ID <= 0) {
            return false;
        }

        $arResult = [];
        $strSql = 'SELECT SR.ENTITY FROM b_blog_socnet_rights SR
						INNER JOIN b_blog_post P ON (P.ID = SR.POST_ID)
						WHERE SR.POST_ID='.$ID.'
						ORDER BY SR.ENTITY ASC';
        $dbRes = $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
        while ($arRes = $dbRes->Fetch()) {
            if (!in_array($arRes['ENTITY'], $arResult, true)) {
                $arResult[] = $arRes['ENTITY'];
            }
        }

        return $arResult;
    }

    public static function ChangeSocNetPermission($entity_type, $entity_id, $operation)
    {
        global $DB;
        $entity_id = (int) $entity_id;
        $perms = CBlogPost::GetSocnetGroups($entity_type, $entity_id, $operation);
        $type = 'U';
        $type2 = 'US';
        if ('G' === $entity_type) {
            $type = $type2 = 'SG';
        }
        $DB->Query("DELETE FROM b_blog_socnet_rights
					WHERE
						ENTITY_TYPE = '".$type."'
						AND ENTITY_ID = ".$entity_id."
						AND ENTITY <> '".$type2.$entity_id."'
						AND ENTITY <> '".$type.$entity_id."'
						", false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
        foreach ($perms as $val) {
            $DB->Query("INSERT INTO b_blog_socnet_rights (POST_ID, ENTITY_TYPE, ENTITY_ID, ENTITY)
						SELECT SR.POST_ID, SR.ENTITY_TYPE, SR.ENTITY_ID, '".$DB->ForSql($val)."' FROM b_blog_socnet_rights SR
						WHERE SR.ENTITY = '".$type2.$entity_id."'", false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
        }
    }

    public static function GetSocNetPostsPerms($entity_type, $entity_id)
    {
        global $DB;
        $entity_id = (int) $entity_id;
        if ($entity_id <= 0) {
            return false;
        }

        $type = 'U';
        $type2 = 'US';
        if ('G' === $entity_type) {
            $type = $type2 = 'SG';
        }

        $arResult = [];
        $dbRes = $DB->Query("
			SELECT SR.POST_ID, SR.ENTITY, SR.ENTITY_ID, SR.ENTITY_TYPE FROM b_blog_socnet_rights SR
			WHERE
				SR.POST_ID IN (SELECT POST_ID FROM b_blog_socnet_rights WHERE ENTITY_TYPE='".$type."' AND ENTITY_ID=".$entity_id." AND ENTITY = '".$type.$entity_id."')
				AND SR.ENTITY <> '".$type2.$entity_id."'
		", false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
        while ($arRes = $dbRes->Fetch()) {
            $arResult[$arRes['POST_ID']]['PERMS'][] = $arRes['ENTITY'];
            $arResult[$arRes['POST_ID']]['PERMS_FULL'][$arRes['ENTITY_TYPE'].$arRes['ENTITY_ID']] = ['TYPE' => $arRes['ENTITY_TYPE'], 'ID' => $arRes['ENTITY_ID']];
        }

        return $arResult;
    }

    public static function GetSocNetPostPerms($postId = 0, $bNeedFull = false, $userId = false, $postAuthor = 0)
    {
        global $USER;

        $cId = md5(serialize(func_get_args()));

        if (
            is_array($postId)
            && isset($postId['POST_ID'])
        ) {
            $arParams = $postId;
            $postId = (int) $arParams['POST_ID'];
            $bNeedFull = (isset($arParams['NEED_FULL']) ? $arParams['NEED_FULL'] : false);
            $userId = (isset($arParams['USER_ID']) ? $arParams['USER_ID'] : false);
            $postAuthor = (isset($arParams['POST_AUTHOR_ID']) ? $arParams['POST_AUTHOR_ID'] : 0);
            $bPublic = (isset($arParams['PUBLIC']) ? $arParams['PUBLIC'] : false);
            $logId = (isset($arParams['LOG_ID']) ? (int) ($arParams['PUBLIC']) : false);
            $bIgnoreAdmin = (isset($arParams['IGNORE_ADMIN']) ? $arParams['IGNORE_ADMIN'] : false);
        } else {
            $bPublic = $logId = $bIgnoreAdmin = false;
        }

        if (!$userId) {
            $userId = (int) $USER->GetID();
            $bByUserId = false;
        } else {
            $userId = (int) $userId;
            $bByUserId = true;
        }
        $postId = (int) $postId;
        if ($postId <= 0) {
            return false;
        }

        if (!empty(static::$arSocNetPostPermsCache[$cId])) {
            return static::$arSocNetPostPermsCache[$cId];
        }

        if (!CModule::IncludeModule('socialnetwork')) {
            return false;
        }

        $perms = BLOG_PERMS_DENY;
        $arAvailPerms = array_keys($GLOBALS['AR_BLOG_PERMS']);

        if (!$bByUserId) {
            if (CSocNetUser::IsCurrentUserModuleAdmin()) {
                $perms = $arAvailPerms[count($arAvailPerms) - 1];
            }
        } elseif (
            !$bIgnoreAdmin
            && CSocNetUser::IsUserModuleAdmin($userId)
        ) {
            $perms = $arAvailPerms[count($arAvailPerms) - 1];
        }

        if ((int) $postAuthor <= 0) {
            $dbPost = CBlogPost::GetList([], ['ID' => $postId], false, false, ['ID', 'AUTHOR_ID']);
            $arPost = $dbPost->Fetch();
        } else {
            $arPost['AUTHOR_ID'] = $postAuthor;
        }

        if ($arPost['AUTHOR_ID'] === $userId) {
            $perms = BLOG_PERMS_FULL;
        }

        if ($perms <= BLOG_PERMS_DENY) {
            $arPerms = CBlogPost::GetSocNetPerms($postId);

            if ((int) $userId > 0) {
                if (IsModuleInstalled('mail')) { // check for email authorization users
                    $rsUsers = CUser::GetList(
                        $by = 'ID',
                        $order = 'asc',
                        [
                            'ID' => $userId,
                        ],
                        [
                            'FIELDS' => ['ID', 'EXTERNAL_AUTH_ID'],
                            'SELECT' => ['UF_DEPARTMENT'],
                        ]
                    );

                    if ($arUser = $rsUsers->Fetch()) {
                        if ('email' === $arUser['EXTERNAL_AUTH_ID']) {
                            return
                                isset($arPerms['U'])
                                && isset($arPerms['U'][$userId])
                                    ? BLOG_PERMS_READ
                                    : BLOG_PERMS_DENY;
                        }
                        if (
                            $bPublic
                            && (
                                !is_array($arUser['UF_DEPARTMENT'])
                                || empty($arUser['UF_DEPARTMENT'])
                                || (int) $arUser['UF_DEPARTMENT'][0] <= 0
                            )
                            && CModule::IncludeModule('extranet')
                            && ($extranet_site_id = CExtranet::GetExtranetSiteID()) // for extranet users in public section
                        ) {
                            if ($logId) {
                                $arPostSite = [];
                                $rsLogSite = CSocNetLog::GetSite($logId);
                                while ($arLogSite = $rsLogSite->Fetch()) {
                                    $arPostSite[] = $arLogSite['LID'];
                                }

                                if (!in_array($extranet_site_id, $arPostSite, true)) {
                                    return BLOG_PERMS_DENY;
                                }
                            } else {
                                return BLOG_PERMS_DENY;
                            }
                        }
                    } else {
                        return BLOG_PERMS_DENY;
                    }
                }
            }

            $arEntities = [];
            if (!empty(static::$arUACCache[$userId])) {
                $arEntities = static::$arUACCache[$userId];
            } else {
                $arCodes = CAccess::GetUserCodesArray($userId);
                foreach ($arCodes as $code) {
                    if (
                        preg_match('/^DR([0-9]+)/', $code, $match)
                        || preg_match('/^D([0-9]+)/', $code, $match)
                        || preg_match('/^IU([0-9]+)/', $code, $match)
                    ) {
                        $arEntities['DR'][$code] = $code;
                    } elseif (preg_match('/^SG([0-9]+)_([A-Z])/', $code, $match)) {
                        $arEntities['SG'][$match[1]][$match[2]] = $match[2];
                    }
                }
                static::$arUACCache[$userId] = $arEntities;
            }

            foreach ($arPerms as $t => $val) {
                foreach ($val as $id => $p) {
                    if (!is_array($p)) {
                        $p = [];
                    }
                    if ($userId > 0 && 'U' === $t && $userId === $id) {
                        $perms = BLOG_PERMS_READ;
                        if (in_array('US'.$userId, $p, true)) { // if author
                            $perms = BLOG_PERMS_FULL;
                        }

                        break;
                    }
                    if (in_array('G2', $p, true)) {
                        $perms = BLOG_PERMS_READ;

                        break;
                    }
                    if ($userId > 0 && in_array('AU', $p, true)) {
                        $perms = BLOG_PERMS_READ;

                        break;
                    }
                    if ('SG' === $t) {
                        if (!empty($arEntities['SG'][$id])) {
                            foreach ($arEntities['SG'][$id] as $gr) {
                                if (in_array('SG'.$id.'_'.$gr, $p, true)) {
                                    $perms = BLOG_PERMS_READ;

                                    break;
                                }
                            }
                        }
                    }

                    if ('DR' === $t && !empty($arEntities['DR'])) {
                        if (in_array('DR'.$id, $arEntities['DR'], true)) {
                            $perms = BLOG_PERMS_READ;

                            break;
                        }
                    }
                }

                if ($perms > BLOG_PERMS_DENY) {
                    break;
                }
            }

            if ($bNeedFull && $perms <= BLOG_PERMS_FULL) {
                $arGroupsId = [];
                if (!empty($arPerms['SG'])) {
                    foreach ($arPerms['SG'] as $gid => $val) {
                        if (!empty($arEntities['SG'][$gid])) {
                            $arGroupsId[] = $gid;
                        }
                    }
                }

                $operation = ['full_post', 'moderate_post', 'write_post', 'premoderate_post'];
                if (!empty($arGroupsId)) {
                    foreach ($operation as $v) {
                        if ($perms <= BLOG_PERMS_READ) {
                            $f = CSocNetFeaturesPerms::GetOperationPerm(SONET_ENTITY_GROUP, $arGroupsId, 'blog', $v);
                            if (is_array($f)) {
                                foreach ($f as $gid => $val) {
                                    if (in_array($val, $arEntities['SG'][$gid], true)) {
                                        switch ($v) {
                                            case 'full_post':
                                                $perms = BLOG_PERMS_FULL;

                                                break;

                                            case 'moderate_post':
                                                $perms = BLOG_PERMS_MODERATE;

                                                break;

                                            case 'write_post':
                                                $perms = BLOG_PERMS_WRITE;

                                                break;

                                            case 'premoderate_post':
                                                $perms = BLOG_PERMS_PREMODERATE;

                                                break;
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        static::$arSocNetPostPermsCache[$cId] = $perms;

        return $perms;
    }

    public static function NotifyIm($arParams)
    {
        $arUserIDSent = [];

        if (!CModule::IncludeModule('im')) {
            return $arUserIDSent;
        }

        $arUsers = [];

        if (!empty($arParams['TO_USER_ID'])) {
            foreach ($arParams['TO_USER_ID'] as $val) {
                $val = (int) $val;
                if (
                    $val > 0
                    && $val !== $arParams['FROM_USER_ID']
                ) {
                    $arUsers[] = $val;
                }
            }
        }
        if (!empty($arParams['TO_SOCNET_RIGHTS'])) {
            foreach ($arParams['TO_SOCNET_RIGHTS'] as $v) {
                if ('U' === substr($v, 0, 1)) {
                    $u = (int) substr($v, 1);
                    if (
                        $u > 0
                        && !in_array($u, $arUsers, true)
                        && (
                            !array_key_exists('U', $arParams['TO_SOCNET_RIGHTS_OLD'])
                            || empty($arParams['TO_SOCNET_RIGHTS_OLD']['U'][$u])
                        )
                        && $u !== $arParams['FROM_USER_ID']
                    ) {
                        $arUsers[] = $u;
                    }
                }
            }
        }

        if (!empty($arUsers)) {
            $rsUser = UserTable::getList([
                'order' => [],
                'filter' => [
                    'ID' => $arUsers,
                    'ACTIVE' => 'Y',
                    '!=EXTERNAL_AUTH_ID' => 'email',
                ],
                'select' => ['ID'],
            ]);

            $arUsers = [];

            while ($arUser = $rsUser->fetch()) {
                $arUsers[] = $arUser['ID'];
            }
        }

        $arMessageFields = [
            'MESSAGE_TYPE' => IM_MESSAGE_SYSTEM,
            'TO_USER_ID' => '',
            'FROM_USER_ID' => $arParams['FROM_USER_ID'],
            'NOTIFY_TYPE' => IM_NOTIFY_FROM,
            'NOTIFY_MODULE' => 'blog',
        ];

        $aditGM = $authorName = '';
        if ((int) $arParams['FROM_USER_ID'] > 0) {
            $dbUser = CUser::GetByID($arParams['FROM_USER_ID']);
            if ($arUser = $dbUser->Fetch()) {
                if ('F' === $arUser['PERSONAL_GENDER']) {
                    $aditGM = '_FEMALE';
                }

                $authorName = (
                    $arUser
                        ? CUser::FormatName(CSite::GetNameFormat(), $arUser, true)
                        : GetMessage('BLG_GP_PUSH_USER')
                );
            }
        }

        if (CModule::IncludeModule('socialnetwork')) {
            $rsLog = CSocNetLog::GetList(
                [],
                [
                    'EVENT_ID' => ['blog_post', 'blog_post_important', 'blog_post_micro'],
                    'SOURCE_ID' => $arParams['ID'],
                ],
                false,
                false,
                ['ID']
            );
            if ($arLog = $rsLog->Fetch()) {
                $arMessageFields['LOG_ID'] = $arLog['ID'];
            }
        }

        $arParams['TITLE'] = str_replace(["\r\n", "\n"], ' ', $arParams['TITLE']);
        $arParams['TITLE'] = TruncateText($arParams['TITLE'], 100);
        $arParams['TITLE_OUT'] = TruncateText($arParams['TITLE'], 255);
        $bTitleEmpty = ('' === trim($arParams['TITLE'], " \t\n\r\0\x0B\xA0"));

        $serverName = (CMain::IsHTTPS() ? 'https' : 'http').'://'.((defined('SITE_SERVER_NAME') && SITE_SERVER_NAME !== '') ? SITE_SERVER_NAME : COption::GetOptionString('main', 'server_name', ''));

        if (IsModuleInstalled('extranet')) {
            $user_path = COption::GetOptionString('socialnetwork', 'user_page', false, SITE_ID);
            if (
                '' !== $user_path
                && str_starts_with($arParams['URL'], $user_path)
            ) {
                $arParams['URL'] = str_replace($user_path, '#USER_PATH#', $arParams['URL']);
            }
        }

        if ('POST' === $arParams['TYPE']) {
            $arMessageFields['PUSH_PARAMS'] = [
                'ACTION' => 'post',
            ];

            $arMessageFields['NOTIFY_EVENT'] = 'post';
            $arMessageFields['NOTIFY_TAG'] = 'BLOG|POST|'.$arParams['ID'];

            if (!$bTitleEmpty) {
                $arMessageFields['NOTIFY_MESSAGE'] = GetMessage(
                    'BLG_GP_IM_1'.$aditGM,
                    [
                        '#title#' => '<a href="'.$arParams['URL'].'" class="bx-notifier-item-action">'.htmlspecialcharsbx($arParams['TITLE']).'</a>',
                    ]
                );
                $arMessageFields['NOTIFY_MESSAGE_OUT'] = GetMessage(
                    'BLG_GP_IM_1'.$aditGM,
                    [
                        '#title#' => htmlspecialcharsbx($arParams['TITLE_OUT']),
                    ]
                ).' '.$serverName.$arParams['URL'].'';
                $arMessageFields['PUSH_MESSAGE'] = GetMessage(
                    'BLG_GP_PUSH_1'.$aditGM,
                    [
                        '#name#' => htmlspecialcharsbx($authorName),
                        '#title#' => htmlspecialcharsbx($arParams['TITLE']),
                    ]
                );
            } else {
                $arMessageFields['NOTIFY_MESSAGE'] = GetMessage(
                    'BLG_GP_IM_1A'.$aditGM,
                    [
                        '#post#' => '<a href="'.$arParams['URL'].'" class="bx-notifier-item-action">'.GetMessage('BLG_GP_IM_1B').'</a>',
                    ]
                );
                $arMessageFields['NOTIFY_MESSAGE_OUT'] = GetMessage(
                    'BLG_GP_IM_1A'.$aditGM,
                    [
                        '#post#' => GetMessage('BLG_GP_IM_1B'),
                    ]
                ).' '.$serverName.$arParams['URL'].'';
                $arMessageFields['PUSH_MESSAGE'] = GetMessage(
                    'BLG_GP_PUSH_1A'.$aditGM,
                    [
                        '#name#' => htmlspecialcharsbx($authorName),
                        '#post#' => GetMessage('BLG_GP_IM_1B'),
                    ]
                );
            }
        } elseif ('COMMENT' === $arParams['TYPE']) {
            $arMessageFields['PUSH_PARAMS'] = [
                'ACTION' => 'comment',
            ];

            $arMessageFields['NOTIFY_EVENT'] = 'comment';
            $arMessageFields['NOTIFY_TAG'] = 'BLOG|COMMENT|'.$arParams['ID'];
            if (!$bTitleEmpty) {
                $arMessageFields['NOTIFY_MESSAGE'] = GetMessage(
                    'BLG_GP_IM_4'.$aditGM,
                    [
                        '#title#' => '<a href="'.$arParams['URL'].'" class="bx-notifier-item-action">'.htmlspecialcharsbx($arParams['TITLE']).'</a>',
                    ]
                );
                $arMessageFields['NOTIFY_MESSAGE_OUT'] = GetMessage(
                    'BLG_GP_IM_4'.$aditGM,
                    [
                        '#title#' => htmlspecialcharsbx($arParams['TITLE_OUT']),
                    ]
                ).' '.$serverName.$arParams['URL']."\n\n".$arParams['BODY'];
                $arMessageFields['PUSH_MESSAGE'] = GetMessage(
                    'BLG_GP_PUSH_4'.$aditGM,
                    [
                        '#name#' => htmlspecialcharsbx($authorName),
                        '#title#' => htmlspecialcharsbx($arParams['TITLE']),
                    ]
                );

                $arMessageFields['NOTIFY_MESSAGE_AUTHOR'] = GetMessage(
                    'BLG_GP_IM_5'.$aditGM,
                    [
                        '#title#' => '<a href="'.$arParams['URL'].'" class="bx-notifier-item-action">'.htmlspecialcharsbx($arParams['TITLE']).'</a>',
                    ]
                );
                $arMessageFields['NOTIFY_MESSAGE_AUTHOR_OUT'] = GetMessage(
                    'BLG_GP_IM_5'.$aditGM,
                    [
                        '#title#' => htmlspecialcharsbx($arParams['TITLE_OUT']),
                    ]
                ).' '.$serverName.$arParams['URL']."\n\n".$arParams['BODY'];
                $arMessageFields['PUSH_MESSAGE_AUTHOR'] = GetMessage(
                    'BLG_GP_PUSH_5'.$aditGM,
                    [
                        '#name#' => htmlspecialcharsbx($authorName),
                        '#title#' => htmlspecialcharsbx($arParams['TITLE']),
                    ]
                );
            } else {
                $arMessageFields['NOTIFY_MESSAGE'] = GetMessage(
                    'BLG_GP_IM_4A'.$aditGM,
                    [
                        '#post#' => '<a href="'.$arParams['URL'].'" class="bx-notifier-item-action">'.GetMessage('BLG_GP_IM_4B').'</a>',
                    ]
                );
                $arMessageFields['NOTIFY_MESSAGE_OUT'] = GetMessage(
                    'BLG_GP_IM_4A'.$aditGM,
                    [
                        '#post#' => GetMessage('BLG_GP_IM_4B'),
                    ]
                ).' '.$serverName.$arParams['URL']."\n\n".$arParams['BODY'];
                $arMessageFields['PUSH_MESSAGE'] = GetMessage(
                    'BLG_GP_PUSH_4A'.$aditGM,
                    [
                        '#name#' => htmlspecialcharsbx($authorName),
                        '#post#' => GetMessage('BLG_GP_IM_4B'),
                    ]
                );

                $arMessageFields['NOTIFY_MESSAGE_AUTHOR'] = GetMessage(
                    'BLG_GP_IM_5A'.$aditGM,
                    [
                        '#post#' => '<a href="'.$arParams['URL'].'" class="bx-notifier-item-action">'.GetMessage('BLG_GP_IM_5B').'</a>',
                    ]
                );
                $arMessageFields['NOTIFY_MESSAGE_AUTHOR_OUT'] = GetMessage(
                    'BLG_GP_IM_5A'.$aditGM,
                    [
                        '#post#' => GetMessage('BLG_GP_IM_5B'),
                    ]
                ).' '.$serverName.$arParams['URL']."\n\n".$arParams['BODY'];
                $arMessageFields['PUSH_MESSAGE_AUTHOR'] = GetMessage(
                    'BLG_GP_PUSH_5A'.$aditGM,
                    [
                        '#name#' => htmlspecialcharsbx($authorName),
                        '#post#' => GetMessage('BLG_GP_IM_5B'),
                    ]
                );
            }
        } elseif ('SHARE' === $arParams['TYPE']) {
            $arMessageFields['PUSH_PARAMS'] = [
                'ACTION' => 'share',
            ];

            $arMessageFields['NOTIFY_EVENT'] = 'share';
            $arMessageFields['NOTIFY_TAG'] = 'BLOG|SHARE|'.$arParams['ID'];
            if (!$bTitleEmpty) {
                $arMessageFields['NOTIFY_MESSAGE'] = GetMessage(
                    'BLG_GP_IM_8'.$aditGM,
                    [
                        '#title#' => '<a href="'.$arParams['URL'].'" class="bx-notifier-item-action">'.htmlspecialcharsbx($arParams['TITLE']).'</a>',
                    ]
                );
                $arMessageFields['NOTIFY_MESSAGE_OUT'] = GetMessage(
                    'BLG_GP_IM_8'.$aditGM,
                    [
                        '#title#' => htmlspecialcharsbx($arParams['TITLE_OUT']),
                    ]
                ).' '.$serverName.$arParams['URL'].'';
                $arMessageFields['PUSHMESSAGE'] = GetMessage(
                    'BLG_GP_PUSH_8'.$aditGM,
                    [
                        '#name#' => htmlspecialcharsbx($authorName),
                        '#title#' => htmlspecialcharsbx($arParams['TITLE']),
                    ]
                );
            } else {
                $arMessageFields['NOTIFY_MESSAGE'] = GetMessage(
                    'BLG_GP_IM_8A'.$aditGM,
                    [
                        '#post#' => '<a href="'.$arParams['URL'].'" class="bx-notifier-item-action">'.GetMessage('BLG_GP_IM_8B').'</a>',
                    ]
                );
                $arMessageFields['NOTIFY_MESSAGE_OUT'] = GetMessage(
                    'BLG_GP_IM_8A'.$aditGM,
                    [
                        '#post#' => GetMessage('BLG_GP_IM_8B'),
                    ]
                ).' '.$serverName.$arParams['URL'].'';
                $arMessageFields['PUSH_MESSAGE'] = GetMessage(
                    'BLG_GP_PUSH_8A'.$aditGM,
                    [
                        '#name#' => htmlspecialcharsbx($authorName),
                        '#post#' => GetMessage('BLG_GP_IM_8B'),
                    ]
                );
            }
        } elseif ('SHARE2USERS' === $arParams['TYPE']) {
            $arMessageFields['PUSH_PARAMS'] = [
                'ACTION' => 'share2users',
            ];

            $arMessageFields['NOTIFY_EVENT'] = 'share2users';
            $arMessageFields['NOTIFY_TAG'] = 'BLOG|SHARE2USERS|'.$arParams['ID'];
            if (!$bTitleEmpty) {
                $arMessageFields['NOTIFY_MESSAGE'] = GetMessage(
                    'BLG_GP_IM_9'.$aditGM,
                    [
                        '#title#' => '<a href="'.$arParams['URL'].'" class="bx-notifier-item-action">'.htmlspecialcharsbx($arParams['TITLE']).'</a>',
                    ]
                );
                $arMessageFields['NOTIFY_MESSAGE_OUT'] = GetMessage(
                    'BLG_GP_IM_9'.$aditGM,
                    [
                        '#title#' => htmlspecialcharsbx($arParams['TITLE_OUT']),
                    ]
                ).' '.$serverName.$arParams['URL'].'';
                $arMessageFields['PUSH_MESSAGE'] = GetMessage(
                    'BLG_GP_PUSH_9'.$aditGM,
                    [
                        '#name#' => htmlspecialcharsbx($authorName),
                        '#title#' => htmlspecialcharsbx($arParams['TITLE']),
                    ]
                );
            } else {
                $arMessageFields['NOTIFY_MESSAGE'] = GetMessage(
                    'BLG_GP_IM_9A'.$aditGM,
                    [
                        '#post#' => '<a href="'.$arParams['URL'].'" class="bx-notifier-item-action">'.GetMessage('BLG_GP_IM_9B').'</a>',
                    ]
                );
                $arMessageFields['NOTIFY_MESSAGE_OUT'] = GetMessage(
                    'BLG_GP_IM_9A'.$aditGM,
                    [
                        '#post#' => GetMessage('BLG_GP_IM_9B'),
                    ]
                ).' '.$serverName.$arParams['URL'].'';
                $arMessageFields['PUSH_MESSAGE'] = GetMessage(
                    'BLG_GP_PUSH_9A'.$aditGM,
                    [
                        '#name#' => htmlspecialcharsbx($authorName),
                        '#post#' => GetMessage('BLG_GP_IM_9B'),
                    ]
                );
            }
        }

        $arMessageFields['PUSH_PARAMS']['TAG'] = $arMessageFields['NOTIFY_TAG'];

        foreach ($arUsers as $v) {
            if (
                !empty($arParams['EXCLUDE_USERS'])
                && (int) $arParams['EXCLUDE_USERS'][$v] > 0
            ) {
                continue;
            }

            if (IsModuleInstalled('extranet')) {
                $arTmp = CSocNetLogTools::ProcessPath(
                    [
                        'URL' => $arParams['URL'],
                    ],
                    $v,
                    SITE_ID
                );
                $url = $arTmp['URLS']['URL'];

                $serverName = (
                    str_starts_with($url, 'http://')
                    || str_starts_with($url, 'https://')
                        ? ''
                        : $arTmp['SERVER_NAME']
                );

                if ('POST' === $arParams['TYPE']) {
                    if (!$bTitleEmpty) {
                        $arMessageFields['NOTIFY_MESSAGE'] = GetMessage('BLG_GP_IM_1'.$aditGM, ['#title#' => '<a href="'.$url.'" class="bx-notifier-item-action">'.htmlspecialcharsbx($arParams['TITLE']).'</a>']);
                        $arMessageFields['NOTIFY_MESSAGE_OUT'] = GetMessage('BLG_GP_IM_1'.$aditGM, ['#title#' => htmlspecialcharsbx($arParams['TITLE_OUT'])]).' ('.$serverName.$url.')';
                    } else {
                        $arMessageFields['NOTIFY_MESSAGE'] = GetMessage('BLG_GP_IM_1A'.$aditGM, ['#post#' => '<a href="'.$url.'" class="bx-notifier-item-action">'.GetMessage('BLG_GP_IM_1B').'</a>']);
                        $arMessageFields['NOTIFY_MESSAGE_OUT'] = GetMessage('BLG_GP_IM_1A'.$aditGM, ['#post#' => GetMessage('BLG_GP_IM_1B')]).' ('.$serverName.$url.')';
                    }
                } elseif ('COMMENT' === $arParams['TYPE']) {
                    if (!$bTitleEmpty) {
                        $arMessageFields['NOTIFY_MESSAGE'] = GetMessage('BLG_GP_IM_4'.$aditGM, ['#title#' => '<a href="'.$url.'" class="bx-notifier-item-action">'.htmlspecialcharsbx($arParams['TITLE']).'</a>']);
                        $arMessageFields['NOTIFY_MESSAGE_OUT'] = GetMessage('BLG_GP_IM_4'.$aditGM, ['#title#' => htmlspecialcharsbx($arParams['TITLE_OUT'])]).' '.$serverName.$url."\n\n".$arParams['BODY'];
                        $arMessageFields['NOTIFY_MESSAGE_AUTHOR'] = GetMessage('BLG_GP_IM_5'.$aditGM, ['#title#' => '<a href="'.$url.'" class="bx-notifier-item-action">'.htmlspecialcharsbx($arParams['TITLE']).'</a>']);
                        $arMessageFields['NOTIFY_MESSAGE_AUTHOR_OUT'] = GetMessage('BLG_GP_IM_5'.$aditGM, ['#title#' => htmlspecialcharsbx($arParams['TITLE_OUT'])]).' '.$serverName.$url."\n\n".$arParams['BODY'];
                    } else {
                        $arMessageFields['NOTIFY_MESSAGE'] = GetMessage('BLG_GP_IM_4A'.$aditGM, ['#post#' => '<a href="'.$url.'" class="bx-notifier-item-action">'.GetMessage('BLG_GP_IM_4B').'</a>']);
                        $arMessageFields['NOTIFY_MESSAGE_OUT'] = GetMessage('BLG_GP_IM_4A'.$aditGM, ['#post#' => GetMessage('BLG_GP_IM_4B')]).' '.$serverName.$url."\n\n".$arParams['BODY'];
                        $arMessageFields['NOTIFY_MESSAGE_AUTHOR'] = GetMessage('BLG_GP_IM_5A'.$aditGM, ['#post#' => '<a href="'.$url.'" class="bx-notifier-item-action">'.GetMessage('BLG_GP_IM_5B').'</a>']);
                        $arMessageFields['NOTIFY_MESSAGE_AUTHOR_OUT'] = GetMessage('BLG_GP_IM_5A'.$aditGM, ['#post#' => GetMessage('BLG_GP_IM_5B')]).' '.$serverName.$url."\n\n".$arParams['BODY'];
                    }
                } elseif ('SHARE' === $arParams['TYPE']) {
                    if (!$bTitleEmpty) {
                        $arMessageFields['NOTIFY_MESSAGE'] = GetMessage('BLG_GP_IM_8'.$aditGM, ['#title#' => '<a href="'.$url.'" class="bx-notifier-item-action">'.htmlspecialcharsbx($arParams['TITLE']).'</a>']);
                        $arMessageFields['NOTIFY_MESSAGE_OUT'] = GetMessage('BLG_GP_IM_8'.$aditGM, ['#title#' => htmlspecialcharsbx($arParams['TITLE_OUT'])]).' '.$serverName.$url.'';
                    } else {
                        $arMessageFields['NOTIFY_MESSAGE'] = GetMessage('BLG_GP_IM_8A'.$aditGM, ['#post#' => '<a href="'.$url.'" class="bx-notifier-item-action">'.GetMessage('BLG_GP_IM_8B').'</a>']);
                        $arMessageFields['NOTIFY_MESSAGE_OUT'] = GetMessage('BLG_GP_IM_8A'.$aditGM, ['#post#' => GetMessage('BLG_GP_IM_8B')]).' '.$serverName.$url.'';
                    }
                } elseif ('SHARE2USERS' === $arParams['TYPE']) {
                    if (!$bTitleEmpty) {
                        $arMessageFields['NOTIFY_MESSAGE'] = GetMessage('BLG_GP_IM_9'.$aditGM, ['#title#' => '<a href="'.$url.'" class="bx-notifier-item-action">'.htmlspecialcharsbx($arParams['TITLE']).'</a>']);
                        $arMessageFields['NOTIFY_MESSAGE_OUT'] = GetMessage('BLG_GP_IM_9'.$aditGM, ['#title#' => htmlspecialcharsbx($arParams['TITLE_OUT'])]).' '.$serverName.$url.'';
                    } else {
                        $arMessageFields['NOTIFY_MESSAGE'] = GetMessage('BLG_GP_IM_9A'.$aditGM, ['#post#' => '<a href="'.$url.'" class="bx-notifier-item-action">'.GetMessage('BLG_GP_IM_9B').'</a>']);
                        $arMessageFields['NOTIFY_MESSAGE_OUT'] = GetMessage('BLG_GP_IM_9A'.$aditGM, ['#post#' => GetMessage('BLG_GP_IM_9B')]).' '.$serverName.$url.'';
                    }
                }
            }

            $arMessageFieldsTmp = $arMessageFields;
            if ('COMMENT' === $arParams['TYPE']) {
                if ($arParams['AUTHOR_ID'] === $v) {
                    $arMessageFieldsTmp['NOTIFY_MESSAGE'] = $arMessageFields['NOTIFY_MESSAGE_AUTHOR'];
                    $arMessageFieldsTmp['NOTIFY_MESSAGE_OUT'] = $arMessageFields['NOTIFY_MESSAGE_AUTHOR_OUT'];
                    $arMessageFieldsTmp['PUSH_MESSAGE'] = $arMessageFields['PUSH_MESSAGE_AUTHOR'];
                }
            }

            $arMessageFieldsTmp['TO_USER_ID'] = $v;
            CIMNotify::Add($arMessageFieldsTmp);

            $arUserIDSent[] = $v;
        }

        if (!empty($arParams['MENTION_ID'])) {
            if (!is_array($arParams['MENTION_ID_OLD'])) {
                $arParams['MENTION_ID_OLD'] = [];
            }

            $arUserIdToMention = $arUserIdToShare = $arNewRights = [];

            foreach ($arParams['MENTION_ID'] as $val) {
                $val = (int) $val;
                if (
                    (int) $val > 0
                    && !in_array($val, $arUsers, true)
                    && !in_array($val, $arParams['MENTION_ID_OLD'], true)
                    && $val !== $arParams['FROM_USER_ID']
                ) {
                    $postPerm = CBlogPost::GetSocNetPostPerms([
                        'POST_ID' => $arParams['ID'],
                        'NEED_FULL' => false,
                        'USER_ID' => $val,
                        'IGNORE_ADMIN' => true,
                    ]);

                    if (
                        $postPerm < BLOG_PERMS_READ
                        && 'COMMENT' === $arParams['TYPE']
                    ) {
                        $arUserIdToShare[] = $val;
                    }

                    if (
                        $postPerm >= BLOG_PERMS_READ
                        || 'COMMENT' === $arParams['TYPE']
                    ) {
                        $arUserIdToMention[] = $val;
                    }
                }
            }

            foreach ($arUserIdToShare as $val) {
                $arParams['TO_SOCNET_RIGHTS'][] = 'U'.$val;
                $arNewRights[] = 'U'.$val;
            }

            if (!empty($arUserIdToShare)) {
                $arPost = CBlogPost::GetByID($arParams['ID']);
                $arSocnetPerms = CBlogPost::GetSocnetPerms($arPost['ID']);
                $arSocNetRights = $arNewRights;

                foreach ($arSocnetPerms as $entityType => $arEntities) {
                    foreach ($arEntities as $entityId => $arRights) {
                        $arSocNetRights = array_merge($arSocNetRights, $arRights);
                    }
                }

                ComponentHelper::processBlogPostShare(
                    [
                        'POST_ID' => $arParams['ID'],
                        'BLOG_ID' => $arPost['BLOG_ID'],
                        'SITE_ID' => SITE_ID,
                        'SONET_RIGHTS' => $arSocNetRights,
                        'NEW_RIGHTS' => $arNewRights,
                        'USER_ID' => $arParams['FROM_USER_ID'],
                    ],
                    [
                        'PATH_TO_USER' => COption::GetOptionString('main', 'TOOLTIP_PATH_TO_USER', '/company/personal/user/#user_id#/', SITE_ID),
                        'PATH_TO_POST' => COption::GetOptionString('socialnetwork', 'userblogpost_page', '/company/personal/user/#user_id#/blog/#post_id#', SITE_ID),
                        'NAME_TEMPLATE' => CSite::GetNameFormat(),
                        'SHOW_LOGIN' => 'Y',
                        'LIVE' => 'N',
                    ]
                );

                if (
                    isset($arParams['COMMENT_ID'])
                    && (int) $arParams['COMMENT_ID'] > 0
                ) {
                    $res = CSocNetLogComments::GetList(
                        [],
                        [
                            'EVENT_ID' => 'blog_comment',
                            'SOURCE_ID' => $arParams['COMMENT_ID'],
                        ],
                        false,
                        false,
                        ['ID', 'LOG_ID']
                    );

                    if ($arSonetLogComment = $res->Fetch()) {
                        $commentId = (int) $arSonetLogComment['ID'];
                        if ($commentId > 0) {
                            CUserCounter::IncrementWithSelect(
                                CSocNetLogCounter::GetSubSelect2(
                                    $commentId,
                                    [
                                        'TYPE' => 'LC',
                                        'MULTIPLE' => 'Y',
                                        'SET_TIMESTAMP' => 'Y',
                                        'USER_ID' => $arUserIdToShare,
                                    ]
                                ),
                                true,
                                [
                                    'SET_TIMESTAMP' => 'Y',
                                    'USERS_TO_PUSH' => $arUserIdToShare,
                                ]
                            );
                        }
                    }
                }
            }

            foreach ($arUserIdToMention as $val) {
                $val = (int) $val;
                $arMessageFields['TO_USER_ID'] = $val;

                if (IsModuleInstalled('extranet')) {
                    $arTmp = CSocNetLogTools::ProcessPath(
                        [
                            'URL' => $arParams['URL'],
                        ],
                        $val,
                        SITE_ID
                    );
                    $url = $arTmp['URLS']['URL'];

                    $serverName = (
                        str_starts_with($url, 'http://')
                        || str_starts_with($url, 'https://')
                            ? ''
                            : $arTmp['SERVER_NAME']
                    );
                } else {
                    $url = $arParams['URL'];
                }

                $arMessageFields['PUSH_PARAMS'] = [
                    'ACTION' => 'mention',
                ];

                if ('POST' === $arParams['TYPE']) {
                    $arMessageFields['NOTIFY_EVENT'] = 'mention';
                    $arMessageFields['NOTIFY_TAG'] = 'BLOG|POST_MENTION|'.$arParams['ID'];

                    if (!$bTitleEmpty) {
                        $arMessageFields['NOTIFY_MESSAGE'] = GetMessage(
                            'BLG_GP_IM_6'.$aditGM,
                            [
                                '#title#' => '<a href="'.$url.'" class="bx-notifier-item-action">'.htmlspecialcharsbx($arParams['TITLE']).'</a>',
                            ]
                        );
                        $arMessageFields['NOTIFY_MESSAGE_OUT'] = GetMessage(
                            'BLG_GP_IM_6'.$aditGM,
                            [
                                '#title#' => htmlspecialcharsbx($arParams['TITLE_OUT']),
                            ]
                        ).' '.$serverName.$url.'';
                        $arMessageFields['PUSH_MESSAGE'] = GetMessage(
                            'BLG_GP_PUSH_6'.$aditGM,
                            [
                                '#name#' => htmlspecialcharsbx($authorName),
                                '#title#' => htmlspecialcharsbx($arParams['TITLE']),
                            ]
                        );
                    } else {
                        $arMessageFields['NOTIFY_MESSAGE'] = GetMessage(
                            'BLG_GP_IM_6A'.$aditGM,
                            [
                                '#post#' => '<a href="'.$url.'" class="bx-notifier-item-action">'.GetMessage('BLG_GP_IM_6B').'</a>',
                            ]
                        );
                        $arMessageFields['NOTIFY_MESSAGE_OUT'] = GetMessage(
                            'BLG_GP_IM_6A'.$aditGM,
                            [
                                '#post#' => GetMessage('BLG_GP_IM_6B'),
                            ]
                        ).' '.$serverName.$url.'';
                        $arMessageFields['PUSH_MESSAGE'] = GetMessage(
                            'BLG_GP_PUSH_6A'.$aditGM,
                            [
                                '#name#' => htmlspecialcharsbx($authorName),
                                '#post#' => GetMessage('BLG_GP_IM_6B'),
                            ]
                        );
                    }
                } elseif ('COMMENT' === $arParams['TYPE']) {
                    $arMessageFields['NOTIFY_EVENT'] = 'mention_comment';
                    $arMessageFields['NOTIFY_TAG'] = 'BLOG|COMMENT_MENTION|'.$arParams['ID'];
                    if (!$bTitleEmpty) {
                        $arMessageFields['NOTIFY_MESSAGE'] = GetMessage(
                            'BLG_GP_IM_7'.$aditGM,
                            [
                                '#title#' => '<a href="'.$url.'" class="bx-notifier-item-action">'.htmlspecialcharsbx($arParams['TITLE']).'</a>',
                            ]
                        );
                        $arMessageFields['NOTIFY_MESSAGE_OUT'] = GetMessage(
                            'BLG_GP_IM_7'.$aditGM,
                            [
                                '#title#' => htmlspecialcharsbx($arParams['TITLE_OUT']),
                            ]
                        ).' '.$serverName.$url.'';
                        $arMessageFields['PUSH_MESSAGE'] = GetMessage(
                            'BLG_GP_PUSH_7'.$aditGM,
                            [
                                '#name#' => htmlspecialcharsbx($authorName),
                                '#title#' => htmlspecialcharsbx($arParams['TITLE']),
                            ]
                        );
                    } else {
                        $arMessageFields['NOTIFY_MESSAGE'] = GetMessage(
                            'BLG_GP_IM_7A'.$aditGM,
                            [
                                '#post#' => '<a href="'.$url.'" class="bx-notifier-item-action">'.GetMessage('BLG_GP_IM_7B').'</a>',
                            ]
                        );
                        $arMessageFields['NOTIFY_MESSAGE_OUT'] = GetMessage(
                            'BLG_GP_IM_7A'.$aditGM,
                            [
                                '#post#' => GetMessage('BLG_GP_IM_7B'),
                            ]
                        ).' '.$serverName.$url.'';
                        $arMessageFields['PUSH_MESSAGE'] = GetMessage(
                            'BLG_GP_PUSH_7A'.$aditGM,
                            [
                                '#name#' => htmlspecialcharsbx($authorName),
                                '#post#' => GetMessage('BLG_GP_IM_7B'),
                            ]
                        );
                    }
                }

                $arMessageFields['PUSH_PARAMS']['TAG'] = $arMessageFields['NOTIFY_TAG'];

                $ID = CIMNotify::Add($arMessageFields);
                $arUserIDSent[] = $val;

                if (
                    (int) $ID > 0
                    && (int) $arMessageFields['LOG_ID'] > 0
                ) {
                    foreach (GetModuleEvents('blog', 'OnBlogPostMentionNotifyIm', true) as $arEvent) {
                        ExecuteModuleEventEx($arEvent, [$ID, $arMessageFields]);
                    }
                }
            }
        }

        if (
            'POST' === $arParams['TYPE']
            && !empty($arParams['TO_SOCNET_RIGHTS'])
        ) {
            $arGroupsId = [];
            foreach ($arParams['TO_SOCNET_RIGHTS'] as $perm_tmp) {
                if (
                    preg_match('/^SG(\d+)_'.SONET_ROLES_USER.'$/', $perm_tmp, $matches)
                    || preg_match('/^SG(\d+)$/', $perm_tmp, $matches)
                ) {
                    $group_id_tmp = $matches[1];
                    if (
                        $group_id_tmp > 0
                        && (
                            !array_key_exists('SG', $arParams['TO_SOCNET_RIGHTS_OLD'])
                            || empty($arParams['TO_SOCNET_RIGHTS_OLD']['SG'][$group_id_tmp])
                        )
                    ) {
                        $arGroupsId[] = $group_id_tmp;
                    }
                }
            }

            if (!empty($arGroupsId)) {
                $title_tmp = str_replace(["\r\n", "\n"], ' ', $arParams['TITLE']);
                $title = TruncateText($title_tmp, 100);
                $title_out = TruncateText($title_tmp, 255);

                $arNotifyParams = [
                    'LOG_ID' => $arMessageFields['LOG_ID'],
                    'GROUP_ID' => $arGroupsId,
                    'NOTIFY_MESSAGE' => '',
                    'FROM_USER_ID' => $arParams['FROM_USER_ID'],
                    'URL' => $arParams['URL'],
                    'MESSAGE' => GetMessage('SONET_IM_NEW_POST', [
                        '#title#' => '<a href="#URL#" class="bx-notifier-item-action">'.$title.'</a>',
                    ]),
                    'MESSAGE_OUT' => GetMessage('SONET_IM_NEW_POST', [
                        '#title#' => $title_out,
                    ]).' #URL#',
                    'EXCLUDE_USERS' => array_merge([$arParams['FROM_USER_ID']], [$arUserIDSent]),
                ];

                $arUserIDSentBySubscription = CSocNetSubscription::NotifyGroup($arNotifyParams);
                if (!$arUserIDSentBySubscription) {
                    $arUserIDSentBySubscription = [];
                }
                $arUserIDSent = array_merge($arUserIDSent, $arUserIDSentBySubscription);
            }
        }

        return $arUserIDSent;
    }

    public static function NotifyMail($arFields)
    {
        if (!CModule::IncludeModule('mail')) {
            return false;
        }

        if (
            !isset($arFields['postId'])
            || (int) $arFields['postId'] <= 0
            || !isset($arFields['userId'])
            || !isset($arFields['postUrl'])
            || '' === $arFields['postUrl']
        ) {
            return false;
        }

        if (!is_array($arFields['userId'])) {
            $arFields['userId'] = [$arFields['userId']];
        }

        if (!isset($arFields['siteId'])) {
            $arFields['siteId'] = SITE_ID;
        }

        $nameTemplate = CSite::GetNameFormat('', $arFields['siteId']);
        $authorName = '';

        if (!empty($arFields['authorId'])) {
            $rsAuthor = CUser::GetById($arFields['authorId']);
            $arAuthor = $rsAuthor->Fetch();
            $authorName = CUser::FormatName(
                $nameTemplate,
                $arAuthor,
                true,
                false
            );

            if (check_email($authorName)) {
                $authorName = '"'.$authorName.'"';
            }

            foreach ($arFields['userId'] as $key => $val) {
                if ((int) $val === (int) $arFields['authorId']) {
                    unset($arFields['userId'][$key]);
                }
            }
        }

        if (empty($arFields['userId'])) {
            return false;
        }

        if (
            !isset($arFields['type'])
            || !in_array(strtoupper($arFields['type']), ['POST', 'POST_SHARE', 'COMMENT'], true)
        ) {
            $arFields['type'] = 'COMMENT';
        }

        $arEmail = User::getUserData($arFields['userId'], $nameTemplate);
        if (empty($arEmail)) {
            return false;
        }

        $arBlogPost = CBlogPost::GetByID((int) $arFields['postId']);
        if (!$arBlogPost) {
            return false;
        }

        $postTitle = str_replace(["\r\n", "\n"], ' ', $arBlogPost['TITLE']);
        $postTitle = TruncateText($postTitle, 100);

        switch (strtoupper($arFields['type'])) {
            case 'COMMENT':
                $mailMessageId = '<BLOG_COMMENT_'.$arFields['commentId'].'@'.$GLOBALS['SERVER_NAME'].'>';
                $mailTemplateType = 'BLOG_SONET_NEW_COMMENT';

                break;

            case 'POST_SHARE':
                $mailMessageId = '<BLOG_POST_'.$arFields['postId'].'@'.$GLOBALS['SERVER_NAME'].'>';
                $mailTemplateType = 'BLOG_SONET_POST_SHARE';

                break;

            default:
                $mailMessageId = '<BLOG_POST_'.$arFields['postId'].'@'.$GLOBALS['SERVER_NAME'].'>';
                $mailTemplateType = 'BLOG_SONET_NEW_POST';
        }

        $mailMessageInReplyTo = '<BLOG_POST_'.$arFields['postId'].'@'.$GLOBALS['SERVER_NAME'].'>';
        $defaultEmailFrom = User::getDefaultEmailFrom();

        foreach ($arEmail as $userId => $arUser) {
            $email = $arUser['EMAIL'];
            $nameFormatted = $arUser['NAME_FORMATTED'];

            if (
                (int) $userId <= 0
                && '' === $email
            ) {
                continue;
            }

            $res = User::getReplyTo(
                $arFields['siteId'],
                $userId,
                'BLOG_POST',
                $arFields['postId'],
                $arFields['postUrl']
            );
            if (is_array($res)) {
                list($replyTo, $backUrl) = $res;

                if (
                    $replyTo
                    && $backUrl
                ) {
                    CEvent::Send(
                        $mailTemplateType,
                        $arFields['siteId'],
                        [
                            '=Reply-To' => $authorName.' <'.$replyTo.'>',
                            '=Message-Id' => $mailMessageId,
                            '=In-Reply-To' => $mailMessageInReplyTo,
                            'EMAIL_FROM' => $authorName.' <'.$defaultEmailFrom.'>',
                            'EMAIL_TO' => (!empty($nameFormatted) ? ''.$nameFormatted.' <'.$email.'>' : $email),
                            'RECIPIENT_ID' => $userId,
                            'COMMENT_ID' => (isset($arFields['commentId']) ? (int) ($arFields['commentId']) : false),
                            'POST_ID' => (int) $arFields['postId'],
                            'POST_TITLE' => $postTitle,
                            'URL' => $arFields['postUrl'],
                        ]
                    );
                }
            }
        }

        if (
            'COMMENT' === strtoupper($arFields['type'])
            && Loader::includeModule('crm')
        ) {
            CCrmLiveFeedComponent::processCrmBlogComment([
                'AUTHOR' => isset($arAuthor) ? $arAuthor : false,
                'POST_ID' => (int) $arFields['postId'],
                'COMMENT_ID' => (int) $arFields['commentId'],
                'USER_ID' => array_keys($arEmail),
            ]);
        }

        return true;
    }

    public static function DeleteSocNetPostPerms($postId)
    {
        global $DB;
        $postId = (int) $postId;
        if ($postId <= 0) {
            return;
        }

        $DB->Query('DELETE FROM b_blog_socnet_rights WHERE POST_ID = '.$postId, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
    }

    public static function GetMentionedUserID($arFields)
    {
        global $USER_FIELD_MANAGER;
        $arMentionedUserID = [];

        if (isset($arFields['DETAIL_TEXT'])) {
            preg_match_all('/\\[user\\s*=\\s*([^\\]]*)\\](.+?)\\[\\/user\\]/is'.BX_UTF_PCRE_MODIFIER, $arFields['DETAIL_TEXT'], $arMention);
            if (!empty($arMention)) {
                $arMentionedUserID = array_merge($arMentionedUserID, $arMention[1]);
            }
        }

        $arPostUF = $USER_FIELD_MANAGER->GetUserFields('BLOG_POST', $arFields['ID'], LANGUAGE_ID);

        if (
            is_array($arPostUF)
            && isset($arPostUF['UF_GRATITUDE'])
            && is_array($arPostUF['UF_GRATITUDE'])
            && isset($arPostUF['UF_GRATITUDE']['VALUE'])
            && (int) $arPostUF['UF_GRATITUDE']['VALUE'] > 0
            && CModule::IncludeModule('iblock')
        ) {
            if (
                !is_array($GLOBALS['CACHE_HONOUR'])
                || !array_key_exists('honour_iblock_id', $GLOBALS['CACHE_HONOUR'])
                || (int) $GLOBALS['CACHE_HONOUR']['honour_iblock_id'] <= 0
            ) {
                $rsIBlock = CIBlock::GetList([], ['=CODE' => 'honour', '=TYPE' => 'structure']);
                if ($arIBlock = $rsIBlock->Fetch()) {
                    $GLOBALS['CACHE_HONOUR']['honour_iblock_id'] = $arIBlock['ID'];
                }
            }

            if ((int) $GLOBALS['CACHE_HONOUR']['honour_iblock_id'] > 0) {
                $rsElementProperty = CIBlockElement::GetProperty(
                    $GLOBALS['CACHE_HONOUR']['honour_iblock_id'],
                    $arPostUF['UF_GRATITUDE']['VALUE']
                );
                while ($arElementProperty = $rsElementProperty->GetNext()) {
                    if (
                        'USERS' === $arElementProperty['CODE']
                        && (int) $arElementProperty['VALUE'] > 0
                    ) {
                        $arMentionedUserID[] = $arElementProperty['VALUE'];
                    }
                }
            }
        }

        return $arMentionedUserID;
    }
}
