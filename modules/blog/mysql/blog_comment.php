<?php

require_once $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/blog/general/blog_comment.php';

class blog_comment extends CAllBlogComment
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

        if (!CBlogComment::CheckFields('ADD', $arFields)) {
            return false;
        }
        if (!$GLOBALS['USER_FIELD_MANAGER']->CheckFields('BLOG_COMMENT', 0, $arFields, isset($arFields['AUTHOR_ID']) && (int) $arFields['AUTHOR_ID'] > 0 ? (int) ($arFields['AUTHOR_ID']) : false)) {
            return false;
        }

        foreach (GetModuleEvents('blog', 'OnBeforeCommentAdd', true) as $arEvent) {
            if (false === ExecuteModuleEventEx($arEvent, [&$arFields])) {
                return false;
            }
        }

        $arInsert = $DB->PrepareInsert('b_blog_comment', $arFields);

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
                'INSERT INTO b_blog_comment('.$arInsert[0].') '.
                'VALUES('.$arInsert[1].')';
            $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);

            $ID = (int) $DB->LastID();
        }

        if ($ID) {
            $GLOBALS['USER_FIELD_MANAGER']->Update('BLOG_COMMENT', $ID, $arFields, isset($arFields['AUTHOR_ID']) && (int) $arFields['AUTHOR_ID'] > 0 ? (int) ($arFields['AUTHOR_ID']) : false);

            $arComment = CBlogComment::GetByID($ID);
            if (BLOG_PUBLISH_STATUS_PUBLISH === $arComment['PUBLISH_STATUS']) {
                CBlogPost::Update($arComment['POST_ID'], ['=NUM_COMMENTS' => 'NUM_COMMENTS + 1'], false);
            }
        }

        $arBlog = CBlog::GetByID($arComment['BLOG_ID']);
        if ('Y' === $arBlog['USE_SOCNET']) {
            $arFields['SC_PERM'] = CBlogComment::GetSocNetCommentPerms($arComment['POST_ID']);
        }

        foreach (GetModuleEvents('blog', 'OnCommentAdd', true) as $arEvent) {
            ExecuteModuleEventEx($arEvent, [$ID, &$arFields]);
        }

        if (CModule::IncludeModule('search')) {
            if (CBlogUserGroup::GetGroupPerms(1, $arComment['BLOG_ID'], $arComment['POST_ID'], BLOG_PERMS_POST) >= BLOG_PERMS_READ) {
                if (
                    'Y' === $arBlog['SEARCH_INDEX']
                    && BLOG_PUBLISH_STATUS_PUBLISH === $arComment['PUBLISH_STATUS']
                ) {
                    $arGroup = CBlogGroup::GetByID($arBlog['GROUP_ID']);
                    if ('' !== $arFields['PATH']) {
                        $arFields['PATH'] = str_replace('#comment_id#', $ID, $arFields['PATH']);
                        $arCommentSite = [$arGroup['SITE_ID'] => $arFields['PATH']];
                    } else {
                        $arCommentSite = [
                            $arGroup['SITE_ID'] => CBlogPost::PreparePath(
                                $arBlog['URL'],
                                $arComment['POST_ID'],
                                $arGroup['SITE_ID'],
                                false,
                                $arBlog['OWNER_ID'],
                                $arBlog['SOCNET_GROUP_ID']
                            ),
                        ];
                    }

                    if (
                        'Y' === $arBlog['USE_SOCNET']
                        && CModule::IncludeModule('extranet')
                    ) {
                        $arPostSiteExt = CExtranet::GetSitesByLogDestinations($arFields['SC_PERM']);
                        foreach ($arPostSiteExt as $lid) {
                            if (!array_key_exists($lid, $arCommentSite)) {
                                $arCommentSite[$lid] = str_replace(
                                    ['#user_id#', '#post_id#'],
                                    [$arBlog['OWNER_ID'], $arComment['POST_ID']],
                                    COption::GetOptionString('socialnetwork', 'userblogpost_page', false, $lid)
                                );
                            }
                        }
                    }

                    $searchContent = blogTextParser::killAllTags($arComment['POST_TEXT']);
                    $searchContent .= "\r\n".$GLOBALS['USER_FIELD_MANAGER']->OnSearchIndex('BLOG_COMMENT', $arComment['ID']);

                    $arSearchIndex = [
                        'SITE_ID' => $arCommentSite,
                        'LAST_MODIFIED' => $arComment['DATE_CREATE'],
                        'PARAM1' => 'COMMENT',
                        'PARAM2' => $arComment['BLOG_ID'].'|'.$arComment['POST_ID'],
                        'PERMISSIONS' => [2],
                        'TITLE' => CSearch::KillTags($arComment['TITLE']),
                        'BODY' => CSearch::KillTags($searchContent),
                        'INDEX_TITLE' => false,
                        'USER_ID' => ((int) $arComment['AUTHOR_ID'] > 0) ? $arComment['AUTHOR_ID'] : false,
                        'ENTITY_TYPE_ID' => 'BLOG_COMMENT',
                        'ENTITY_ID' => $arComment['ID'],
                    ];

                    if ('Y' === $arBlog['USE_SOCNET']) {
                        if (is_array($arFields['SC_PERM'])) {
                            $arSearchIndex['PERMISSIONS'] = $arFields['SC_PERM'];
                            $sgId = [];
                            foreach ($arFields['SC_PERM'] as $perm) {
                                if (str_contains($perm, 'SG')) {
                                    $sgIdTmp = str_replace('SG', '', substr($perm, 0, strpos($perm, '_')));
                                    if (!in_array($sgIdTmp, $sgId, true) && (int) $sgIdTmp > 0) {
                                        $sgId[] = $sgIdTmp;
                                    }
                                }
                            }

                            if (!empty($sgId)) {
                                $arSearchIndex['PARAMS'] = [
                                    'socnet_group' => $sgId,
                                    'entity' => 'socnet_group',
                                ];
                            }

                            if (!in_array('U'.$arComment['AUTHOR_ID'], $arSearchIndex['PERMISSIONS'], true)) {
                                $arSearchIndex['PERMISSIONS'][] = 'U'.$arComment['AUTHOR_ID'];
                            }
                        }
                    }

                    if (
                        'Y' === $arBlog['USE_SOCNET']
                        || str_starts_with($arBlog['URL'], 'idea_')
                    ) {
                        // get mentions
                        $arMentionedUserID = CBlogComment::GetMentionedUserID($arComment);
                        if (!empty($arMentionedUserID)) {
                            if (!isset($arSearchIndex['PARAMS'])) {
                                $arSearchIndex['PARAMS'] = [];
                            }
                            $arSearchIndex['PARAMS']['mentioned_user_id'] = $arMentionedUserID;
                        }
                    }

                    if ('' === $arComment['TITLE']) {
                        $arSearchIndex['TITLE'] = substr($arSearchIndex['BODY'], 0, 100);
                    }

                    CSearch::Index('blog', 'C'.$ID, $arSearchIndex);
                }
            }
        }

        return $ID;
    }

    public static function Update($ID, $arFields, $bSearchIndex = true)
    {
        global $DB;

        $ID = (int) $ID;

        if ('' !== $arFields['PATH']) {
            $arFields['PATH'] = str_replace('#comment_id#', $ID, $arFields['PATH']);
        }

        $arFields1 = [];
        foreach ($arFields as $key => $value) {
            if ('=' === substr($key, 0, 1)) {
                $arFields1[substr($key, 1)] = $value;
                unset($arFields[$key]);
            }
        }

        if (!CBlogComment::CheckFields('UPDATE', $arFields, $ID)) {
            return false;
        }
        if (!$GLOBALS['USER_FIELD_MANAGER']->CheckFields('BLOG_COMMENT', $ID, $arFields, isset($arFields['AUTHOR_ID']) && (int) $arFields['AUTHOR_ID'] > 0 ? (int) ($arFields['AUTHOR_ID']) : false)) {
            return false;
        }

        foreach (GetModuleEvents('blog', 'OnBeforeCommentUpdate', true) as $arEvent) {
            if (false === ExecuteModuleEventEx($arEvent, [$ID, &$arFields])) {
                return false;
            }
        }

        $strUpdate = $DB->PrepareUpdate('b_blog_comment', $arFields);

        foreach ($arFields1 as $key => $value) {
            if ('' !== $strUpdate) {
                $strUpdate .= ', ';
            }
            $strUpdate .= $key.'='.$value.' ';
        }

        if ('' !== $strUpdate) {
            if (is_set($arFields['PUBLISH_STATUS']) && '' !== $arFields['PUBLISH_STATUS']) {
                $arComment = CBlogComment::GetByID($ID);
                if (BLOG_PUBLISH_STATUS_PUBLISH === $arComment['PUBLISH_STATUS'] && BLOG_PUBLISH_STATUS_PUBLISH !== $arFields['PUBLISH_STATUS']) {
                    CBlogPost::Update($arComment['POST_ID'], ['=NUM_COMMENTS' => 'NUM_COMMENTS - 1'], false);
                } elseif (BLOG_PUBLISH_STATUS_PUBLISH !== $arComment['PUBLISH_STATUS'] && BLOG_PUBLISH_STATUS_PUBLISH === $arFields['PUBLISH_STATUS']) {
                    CBlogPost::Update($arComment['POST_ID'], ['=NUM_COMMENTS' => 'NUM_COMMENTS + 1'], false);
                }
            }

            $strSql =
                'UPDATE b_blog_comment SET '.
                '	'.$strUpdate.' '.
                'WHERE ID = '.$ID.' ';
            $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
            unset($GLOBALS['BLOG_COMMENT']['BLOG_COMMENT_CACHE_'.$ID]);

            $GLOBALS['USER_FIELD_MANAGER']->Update('BLOG_COMMENT', $ID, $arFields, isset($arFields['AUTHOR_ID']) && (int) $arFields['AUTHOR_ID'] > 0 ? (int) ($arFields['AUTHOR_ID']) : false);

            $arComment = CBlogComment::GetByID($ID);
            $arBlog = CBlog::GetByID($arComment['BLOG_ID']);
            if ('Y' === $arBlog['USE_SOCNET']) {
                $arFields['SC_PERM'] = CBlogComment::GetSocNetCommentPerms($arComment['POST_ID']);
            }

            foreach (GetModuleEvents('blog', 'OnCommentUpdate', true) as $arEvent) {
                ExecuteModuleEventEx($arEvent, [$ID, &$arFields]);
            }

            if ($bSearchIndex && CModule::IncludeModule('search')) {
                $newPostPerms = CBlogUserGroup::GetGroupPerms(1, $arComment['BLOG_ID'], $arComment['POST_ID'], BLOG_PERMS_POST);

                if ('Y' !== $arBlog['SEARCH_INDEX'] || BLOG_PUBLISH_STATUS_PUBLISH !== $arComment['PUBLISH_STATUS']) {
                    CSearch::Index(
                        'blog',
                        'C'.$ID,
                        [
                            'TITLE' => '',
                            'BODY' => '',
                        ]
                    );
                } else {
                    $arGroup = CBlogGroup::GetByID($arBlog['GROUP_ID']);

                    if ('' !== $arFields['PATH']) {
                        $arFields['PATH'] = str_replace('#comment_id#', $ID, $arFields['PATH']);
                        $arPostSite = [$arGroup['SITE_ID'] => $arFields['PATH']];
                    } elseif ('' !== $arComment['PATH']) {
                        $arComment['PATH'] = str_replace('#comment_id#', $ID, $arComment['PATH']);
                        $arPostSite = [$arGroup['SITE_ID'] => $arComment['PATH']];
                    } else {
                        $arPostSite = [
                            $arGroup['SITE_ID'] => CBlogPost::PreparePath(
                                $arBlog['URL'],
                                $arComment['POST_ID'],
                                $arGroup['SITE_ID'],
                                false,
                                $arBlog['OWNER_ID'],
                                $arBlog['SOCNET_GROUP_ID']
                            ),
                        ];
                    }

                    $searchContent = blogTextParser::killAllTags($arComment['POST_TEXT']);
                    $searchContent .= "\r\n".$GLOBALS['USER_FIELD_MANAGER']->OnSearchIndex('BLOG_COMMENT', $arComment['ID']);

                    $arSearchIndex = [
                        'SITE_ID' => $arPostSite,
                        'LAST_MODIFIED' => $arComment['DATE_CREATE'],
                        'PARAM1' => 'COMMENT',
                        'PARAM2' => $arComment['BLOG_ID'].'|'.$arComment['POST_ID'],
                        'PERMISSIONS' => [2],
                        'TITLE' => CSearch::KillTags($arComment['TITLE']),
                        'BODY' => CSearch::KillTags($searchContent),
                        'USER_ID' => ((int) $arComment['AUTHOR_ID'] > 0) ? $arComment['AUTHOR_ID'] : false,
                        'ENTITY_TYPE_ID' => 'BLOG_COMMENT',
                        'ENTITY_ID' => $arComment['ID'],
                    ];

                    if ('Y' === $arBlog['USE_SOCNET']) {
                        if (is_array($arFields['SC_PERM'])) {
                            $arSearchIndex['PERMISSIONS'] = $arFields['SC_PERM'];
                            $sgId = [];
                            foreach ($arFields['SC_PERM'] as $perm) {
                                if (str_contains($perm, 'SG')) {
                                    $sgIdTmp = str_replace('SG', '', substr($perm, 0, strpos($perm, '_')));
                                    if (!in_array($sgIdTmp, $sgId, true) && (int) $sgIdTmp > 0) {
                                        $sgId[] = $sgIdTmp;
                                    }
                                }
                            }

                            if (!empty($sgId)) {
                                $arSearchIndex['PARAMS'] = [
                                    'socnet_group' => $sgId,
                                    'entity' => 'socnet_group',
                                ];
                            }
                            if (!in_array('U'.$arComment['AUTHOR_ID'], $arSearchIndex['PERMISSIONS'], true)) {
                                $arSearchIndex['PERMISSIONS'][] = 'U'.$arComment['AUTHOR_ID'];
                            }
                        }
                    }

                    if (
                        'Y' === $arBlog['USE_SOCNET']
                        || str_starts_with($arBlog['URL'], 'idea_')
                    ) {
                        // get mentions
                        $arMentionedUserID = CBlogComment::GetMentionedUserID($arComment);
                        if (!empty($arMentionedUserID)) {
                            if (!isset($arSearchIndex['PARAMS'])) {
                                $arSearchIndex['PARAMS'] = [];
                            }
                            $arSearchIndex['PARAMS']['mentioned_user_id'] = $arMentionedUserID;
                        }
                    }

                    if ('' === $arComment['TITLE']) {
                        // $arPost = CBlogPost::GetByID($arComment["POST_ID"]);
                        $arSearchIndex['TITLE'] = substr($arSearchIndex['BODY'], 0, 100);
                    }

                    CSearch::Index('blog', 'C'.$ID, $arSearchIndex, true);
                }
            }

            return $ID;
        }

        return false;
    }

    // *************** SELECT *********************/
    public static function GetList($arOrder = ['ID' => 'DESC'], $arFilter = [], $arGroupBy = false, $arNavStartParams = false, $arSelectFields = [])
    {
        global $DB, $USER_FIELD_MANAGER;

        $obUserFieldsSql = new CUserTypeSQL();
        $obUserFieldsSql->SetEntity('BLOG_COMMENT', 'C.ID');
        $obUserFieldsSql->SetSelect($arSelectFields);
        $obUserFieldsSql->SetFilter($arFilter);
        $obUserFieldsSql->SetOrder($arOrder);

        if (count($arSelectFields) <= 0) {
            $arSelectFields = ['ID', 'BLOG_ID', 'POST_ID', 'PARENT_ID', 'AUTHOR_ID', 'AUTHOR_NAME', 'AUTHOR_EMAIL', 'AUTHOR_IP', 'AUTHOR_IP1', 'TITLE', 'POST_TEXT'];
        }
        if (in_array('*', $arSelectFields, true)) {
            $arSelectFields = ['ID', 'BLOG_ID', 'POST_ID', 'PARENT_ID', 'AUTHOR_ID', 'AUTHOR_NAME', 'AUTHOR_EMAIL', 'AUTHOR_IP', 'AUTHOR_IP1', 'TITLE', 'POST_TEXT', 'DATE_CREATE', 'USER_LOGIN', 'USER_NAME', 'USER_LAST_NAME', 'USER_SECOND_NAME', 'USER_EMAIL', 'USER', 'BLOG_USER_ALIAS', 'BLOG_USER_AVATAR', 'BLOG_URL', 'BLOG_OWNER_ID', 'BLOG_SOCNET_GROUP_ID', 'BLOG_ACTIVE', 'BLOG_GROUP_ID', 'BLOG_GROUP_SITE_ID', 'BLOG_USE_SOCNET', 'PERMS', 'PUBLISH_STATUS'];
        }
        if ((array_key_exists('BLOG_GROUP_SITE_ID', $arFilter) || in_array('BLOG_GROUP_SITE_ID', $arSelectFields, true)) && !in_array('BLOG_URL', $arSelectFields, true)) {
            $arSelectFields[] = 'BLOG_URL';
        }

        // FIELDS -->
        $arFields = [
            'ID' => ['FIELD' => 'C.ID', 'TYPE' => 'int'],
            'BLOG_ID' => ['FIELD' => 'C.BLOG_ID', 'TYPE' => 'int'],
            'POST_ID' => ['FIELD' => 'C.POST_ID', 'TYPE' => 'int'],
            'PARENT_ID' => ['FIELD' => 'C.PARENT_ID', 'TYPE' => 'int'],
            'AUTHOR_ID' => ['FIELD' => 'C.AUTHOR_ID', 'TYPE' => 'int'],
            'AUTHOR_NAME' => ['FIELD' => 'C.AUTHOR_NAME', 'TYPE' => 'string'],
            'AUTHOR_EMAIL' => ['FIELD' => 'C.AUTHOR_EMAIL', 'TYPE' => 'string'],
            'AUTHOR_IP' => ['FIELD' => 'C.AUTHOR_IP', 'TYPE' => 'string'],
            'AUTHOR_IP1' => ['FIELD' => 'C.AUTHOR_IP1', 'TYPE' => 'string'],
            'TITLE' => ['FIELD' => 'C.TITLE', 'TYPE' => 'string'],
            'POST_TEXT' => ['FIELD' => 'C.POST_TEXT', 'TYPE' => 'string'],
            'DATE_CREATE' => ['FIELD' => 'C.DATE_CREATE', 'TYPE' => 'datetime'],
            'DATE_CREATE_TS' => ['FIELD' => 'UNIX_TIMESTAMP(C.DATE_CREATE)', 'TYPE' => 'int'],
            'PATH' => ['FIELD' => 'C.PATH', 'TYPE' => 'string'],
            'PUBLISH_STATUS' => ['FIELD' => 'C.PUBLISH_STATUS', 'TYPE' => 'string'],
            'HAS_PROPS' => ['FIELD' => 'C.HAS_PROPS', 'TYPE' => 'string'],
            'SHARE_DEST' => ['FIELD' => 'C.SHARE_DEST', 'TYPE' => 'string'],

            'USER_LOGIN' => ['FIELD' => 'U.LOGIN', 'TYPE' => 'string', 'FROM' => 'LEFT JOIN b_user U ON (C.AUTHOR_ID = U.ID)'],
            'USER_NAME' => ['FIELD' => 'U.NAME', 'TYPE' => 'string', 'FROM' => 'LEFT JOIN b_user U ON (C.AUTHOR_ID = U.ID)'],
            'USER_LAST_NAME' => ['FIELD' => 'U.LAST_NAME', 'TYPE' => 'string', 'FROM' => 'LEFT JOIN b_user U ON (C.AUTHOR_ID = U.ID)'],
            'USER_SECOND_NAME' => ['FIELD' => 'U.SECOND_NAME', 'TYPE' => 'string', 'FROM' => 'LEFT JOIN b_user U ON (C.AUTHOR_ID = U.ID)'],
            'USER_EMAIL' => ['FIELD' => 'U.EMAIL', 'TYPE' => 'string', 'FROM' => 'LEFT JOIN b_user U ON (C.AUTHOR_ID = U.ID)'],
            'USER' => ['FIELD' => 'U.LOGIN,U.NAME,U.LAST_NAME,U.EMAIL,U.ID', 'WHERE_ONLY' => 'Y', 'TYPE' => 'string', 'FROM' => 'LEFT JOIN b_user U ON (C.AUTHOR_ID = U.ID)'],

            'BLOG_USER_ALIAS' => ['FIELD' => 'BU.ALIAS', 'TYPE' => 'string', 'FROM' => 'LEFT JOIN b_blog_user BU ON (C.AUTHOR_ID = BU.USER_ID)'],
            'BLOG_USER_AVATAR' => ['FIELD' => 'BU.AVATAR', 'TYPE' => 'int', 'FROM' => 'LEFT JOIN b_blog_user BU ON (C.AUTHOR_ID = BU.USER_ID)'],

            'BLOG_URL' => ['FIELD' => 'B.URL', 'TYPE' => 'string', 'FROM' => 'INNER JOIN b_blog B ON (C.BLOG_ID = B.ID)'],
            'BLOG_OWNER_ID' => ['FIELD' => 'B.OWNER_ID', 'TYPE' => 'string', 'FROM' => 'INNER JOIN b_blog B ON (C.BLOG_ID = B.ID)'],
            'BLOG_SOCNET_GROUP_ID' => ['FIELD' => 'B.SOCNET_GROUP_ID', 'TYPE' => 'string', 'FROM' => 'INNER JOIN b_blog B ON (C.BLOG_ID = B.ID)'],
            'BLOG_ACTIVE' => ['FIELD' => 'B.ACTIVE', 'TYPE' => 'string', 'FROM' => 'INNER JOIN b_blog B ON (C.BLOG_ID = B.ID)'],
            'BLOG_GROUP_ID' => ['FIELD' => 'B.GROUP_ID', 'TYPE' => 'int', 'FROM' => 'INNER JOIN b_blog B ON (C.BLOG_ID = B.ID)'],
            'BLOG_USE_SOCNET' => ['FIELD' => 'B.USE_SOCNET', 'TYPE' => 'string', 'FROM' => 'INNER JOIN b_blog B ON (C.BLOG_ID = B.ID)'],
            'BLOG_NAME' => ['FIELD' => 'B.NAME', 'TYPE' => 'string', 'FROM' => 'INNER JOIN b_blog B ON (C.BLOG_ID = B.ID)'],

            'BLOG_GROUP_SITE_ID' => ['FIELD' => 'BG.SITE_ID', 'TYPE' => 'string', 'FROM' => '
						INNER JOIN b_blog BGS ON (C.BLOG_ID = BGS.ID)
						INNER JOIN b_blog_group BG ON (BGS.GROUP_ID = BG.ID)'],
            'PERMS' => [],

            'SOCNET_BLOG_READ' => ['FIELD' => 'BSR.BLOG_ID', 'TYPE' => 'int', 'FROM' => 'INNER JOIN b_blog_socnet BSR ON (C.BLOG_ID = BSR.BLOG_ID)'],

            'POST_CODE' => ['FIELD' => 'BP.CODE', 'TYPE' => 'string', 'FROM' => 'INNER JOIN b_blog_post BP ON (C.POST_ID = BP.ID)'],
            'POST_TITLE' => ['FIELD' => 'BP.TITLE', 'TYPE' => 'string', 'FROM' => 'INNER JOIN b_blog_post BP ON (C.POST_ID = BP.ID)'],
            'BLOG_POST_PUBLISH_STATUS' => ['FIELD' => 'BP.PUBLISH_STATUS', 'TYPE' => 'string', 'FROM' => 'INNER JOIN b_blog_post BP ON (C.POST_ID = BP.ID)'],
            'BLOG_POST_MICRO' => ['FIELD' => 'BP.MICRO', 'TYPE' => 'string', 'FROM' => 'INNER JOIN b_blog_post BP ON (C.POST_ID = BP.ID)'],
        ];

        if (isset($arFilter['GROUP_CHECK_PERMS'])) {
            if (is_array($arFilter['GROUP_CHECK_PERMS'])) {
                foreach ($arFilter['GROUP_CHECK_PERMS'] as $val) {
                    if ((int) $val > 0) {
                        $arFields['POST_PERM_'.$val] = [
                            'FIELD' => 'BUGP'.$val.'.PERMS',
                            'TYPE' => 'string',
                            'FROM' => 'LEFT JOIN b_blog_user_group_perms BUGP'.$val.'
											ON (C.BLOG_ID = BUGP'.$val.'.BLOG_ID
												AND C.POST_ID = BUGP'.$val.'.POST_ID
												AND BUGP'.$val.'.USER_GROUP_ID = '.$val.'
												AND BUGP'.$val.".PERMS_TYPE = '".BLOG_PERMS_COMMENT."')",
                        ];
                        $arSelectFields[] = 'POST_PERM_'.$val;
                    }
                }
            } else {
                if ((int) $arFilter['GROUP_CHECK_PERMS'] > 0) {
                    $arFields['POST_PERM_'.$arFilter['GROUP_CHECK_PERMS']] = [
                        'FIELD' => 'BUGP.PERMS',
                        'TYPE' => 'string',
                        'FROM' => 'LEFT JOIN b_blog_user_group_perms BUGP
										ON (C.BLOG_ID = BUGP.BLOG_ID
											AND C.POST_ID = BUGP.POST_ID
											AND BUGP.USER_GROUP_ID = '.$arFilter['GROUP_CHECK_PERMS']."
											AND BUGP.PERMS_TYPE = '".BLOG_PERMS_COMMENT."')",
                    ];
                    $arSelectFields[] = 'POST_PERM_'.$arFilter['GROUP_CHECK_PERMS'];
                }
            }
            unset($arFilter['GROUP_CHECK_PERMS']);
        }

        // rating variable
        if (
            in_array('RATING_TOTAL_VOTES', $arSelectFields, true)
            || in_array('RATING_TOTAL_POSITIVE_VOTES', $arSelectFields, true)
            || in_array('RATING_TOTAL_NEGATIVE_VOTES', $arSelectFields, true)
            || array_key_exists('RATING_TOTAL_VALUE', $arOrder)
            || array_key_exists('RATING_TOTAL_VOTES', $arOrder)
        ) {
            $arFields['RATING_TOTAL_VALUE'] = ['FIELD' => $DB->IsNull('RV.TOTAL_VALUE', '0'), 'TYPE' => 'double', 'FROM' => "LEFT JOIN b_rating_voting RV ON ( RV.ENTITY_TYPE_ID = 'BLOG_COMMENT' AND RV.ENTITY_ID = C.ID )"];
            $arFields['RATING_TOTAL_VOTES'] = ['FIELD' => $DB->IsNull('RV.TOTAL_VOTES', '0'), 'TYPE' => 'int', 'FROM' => "LEFT JOIN b_rating_voting RV ON ( RV.ENTITY_TYPE_ID = 'BLOG_COMMENT' AND RV.ENTITY_ID = C.ID )"];
            $arFields['RATING_TOTAL_POSITIVE_VOTES'] = ['FIELD' => $DB->IsNull('RV.TOTAL_POSITIVE_VOTES', '0'), 'TYPE' => 'int', 'FROM' => "LEFT JOIN b_rating_voting RV ON ( RV.ENTITY_TYPE_ID = 'BLOG_COMMENT' AND RV.ENTITY_ID = C.ID )"];
            $arFields['RATING_TOTAL_NEGATIVE_VOTES'] = ['FIELD' => $DB->IsNull('RV.TOTAL_NEGATIVE_VOTES', '0'), 'TYPE' => 'int', 'FROM' => "LEFT JOIN b_rating_voting RV ON ( RV.ENTITY_TYPE_ID = 'BLOG_COMMENT' AND RV.ENTITY_ID = C.ID )"];
        }

        $bNeedDistinct = false;
        $blogModulePermissions = $GLOBALS['APPLICATION']->GetGroupRight('blog');
        if ($blogModulePermissions < 'W') {
            $arUserGroups = CBlogUser::GetUserGroups($GLOBALS['USER']->IsAuthorized() ? $GLOBALS['USER']->GetID() : 0, 0, 'Y', BLOG_BY_USER_ID);
            $strUserGroups = '0';
            foreach ($arUserGroups as $v) {
                $strUserGroups .= ','.(int) $v;
            }

            $arFields['PERMS'] = ['FIELD' => 'UGP.PERMS', 'TYPE' => 'char', 'FROM' => 'INNER JOIN b_blog_user_group_perms UGP ON (C.POST_ID = UGP.POST_ID AND C.BLOG_ID = UGP.BLOG_ID AND UGP.USER_GROUP_ID IN ('.$strUserGroups.") AND UGP.PERMS_TYPE = '".BLOG_PERMS_COMMENT."')"];
            $bNeedDistinct = true;
        } else {
            $arFields['PERMS'] = ['FIELD' => "'W'", 'TYPE' => 'string'];
        }

        $arSqls = CBlog::PrepareSql($arFields, $arOrder, $arFilter, $arGroupBy, $arSelectFields, $obUserFieldsSql);
        if (array_key_exists('FOR_USER', $arFilter)) {
            if ((int) $arFilter['FOR_USER'] > 0) { // authorized user
                $arSqls['FROM'] .=
                                    ' INNER JOIN b_blog_socnet_rights SR ON (C.POST_ID = SR.POST_ID) '.
                                    ' LEFT JOIN b_user_access UA ON (UA.ACCESS_CODE = SR.ENTITY AND UA.USER_ID = '.(int) $arFilter['FOR_USER'].') ';
                if ('' !== $arSqls['WHERE']) {
                    $arSqls['WHERE'] .= ' AND ';
                }
                $arSqls['WHERE'] .= " (UA.USER_ID is not NULL OR SR.ENTITY = 'AU') ";
            } else {
                $arSqls['FROM'] .=
                            ' INNER JOIN b_blog_socnet_rights SR ON (C.POST_ID = SR.POST_ID) '.
                            ' INNER JOIN b_user_access UA ON (UA.ACCESS_CODE = SR.ENTITY AND UA.USER_ID = 0)';
            }
            $bNeedDistinct = true;
        }

        if ($bNeedDistinct) {
            $arSqls['SELECT'] = str_replace('%%_DISTINCT_%%', 'DISTINCT', $arSqls['SELECT']);
        } else {
            $arSqls['SELECT'] = str_replace('%%_DISTINCT_%%', '', $arSqls['SELECT']);
        }

        $r = $obUserFieldsSql->GetFilter();
        if ('' !== $r) {
            $strSqlUFFilter = ' ('.$r.') ';
        }

        if (is_array($arGroupBy) && 0 === count($arGroupBy)) {
            $strSql =
                'SELECT '.$arSqls['SELECT'].' '.
                    $obUserFieldsSql->GetSelect().' '.
                'FROM b_blog_comment C '.
                '	'.$arSqls['FROM'].' '.
                    $obUserFieldsSql->GetJoin('C.ID').' ';
            if ('' !== $arSqls['WHERE']) {
                $strSql .= 'WHERE '.$arSqls['WHERE'].' ';
            }
            if ('' !== $arSqls['WHERE'] && '' !== $strSqlUFFilter) {
                $strSql .= ' AND '.$strSqlUFFilter.' ';
            } elseif ('' === $arSqls['WHERE'] && '' !== $strSqlUFFilter) {
                $strSql .= ' WHERE '.$strSqlUFFilter.' ';
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
                $obUserFieldsSql->GetSelect().' '.
            'FROM b_blog_comment C '.
            '	'.$arSqls['FROM'].' '.
                $obUserFieldsSql->GetJoin('C.ID').' ';
        if ('' !== $arSqls['WHERE']) {
            $strSql .= 'WHERE '.$arSqls['WHERE'].' ';
        }
        if ('' !== $arSqls['WHERE'] && '' !== $strSqlUFFilter) {
            $strSql .= ' AND '.$strSqlUFFilter.' ';
        } elseif ('' === $arSqls['WHERE'] && '' !== $strSqlUFFilter) {
            $strSql .= ' WHERE '.$strSqlUFFilter.' ';
        }
        if ('' !== $arSqls['GROUPBY']) {
            $strSql .= 'GROUP BY '.$arSqls['GROUPBY'].' ';
        }
        if ('' !== $arSqls['ORDERBY']) {
            $strSql .= 'ORDER BY '.$arSqls['ORDERBY'].' ';
        }

        if (is_array($arNavStartParams) && (int) $arNavStartParams['nTopCount'] <= 0) {
            $strSql_tmp =
                'SELECT COUNT('.($bNeedDistinct ? 'DISTINCT ' : '').'C.ID) as CNT '.
                    $obUserFieldsSql->GetSelect().' '.
                'FROM b_blog_comment C '.
                '	'.$arSqls['FROM'].' '.
                $obUserFieldsSql->GetJoin('C.ID').' ';
            if ('' !== $arSqls['WHERE']) {
                $strSql_tmp .= 'WHERE '.$arSqls['WHERE'].' ';
            }
            if ('' !== $arSqls['WHERE'] && '' !== $strSqlUFFilter) {
                $strSql_tmp .= ' AND '.$strSqlUFFilter.' ';
            } elseif ('' === $arSqls['WHERE'] && '' !== $strSqlUFFilter) {
                $strSql_tmp .= ' WHERE '.$strSqlUFFilter.' ';
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
            $dbRes->SetUserFields($USER_FIELD_MANAGER->GetUserFields('BLOG_POST'));
            $dbRes->NavQuery($strSql, $cnt, $arNavStartParams);
        } else {
            if (is_array($arNavStartParams) && (int) $arNavStartParams['nTopCount'] > 0) {
                $strSql .= 'LIMIT '.(int) $arNavStartParams['nTopCount'];
            }

            // echo "!3!=".htmlspecialcharsbx($strSql)."<br>";

            $dbRes = $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
            $dbRes->SetUserFields($USER_FIELD_MANAGER->GetUserFields('BLOG_POST'));
        }

        return $dbRes;
    }
}
