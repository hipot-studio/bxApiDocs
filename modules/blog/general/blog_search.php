<?php

class blog_search
{
    public static function OnSearchReindex($NS = [], $oCallback = null, $callback_method = '')
    {
        global $DB;
        $arResult = [];

        // CBlogSearch::Trace('OnSearchReindex', 'NS', $NS);
        if ('blog' === $NS['MODULE'] && '' !== $NS['ID']) {
            $category = substr($NS['ID'], 0, 1);
            $id = (int) substr($NS['ID'], 1);
        } else {
            $category = 'B'; // start with blogs
            $id = 0; // very first id
        }
        // CBlogSearch::Trace('OnSearchReindex', 'category+id', array("CATEGORY"=>$category,"ID"=>$id));

        // Reindex blogs
        if ('B' === $category) {
            $strSql = '
				SELECT
					b.ID
					,bg.SITE_ID
					,b.REAL_URL
					,b.URL
					,'.$DB->DateToCharFunction('b.DATE_UPDATE')." as DATE_UPDATE
					,b.NAME
					,b.DESCRIPTION
					,b.OWNER_ID
					,b.SOCNET_GROUP_ID
					,b.USE_SOCNET
					,b.SEARCH_INDEX
				FROM
					b_blog b
					INNER JOIN b_blog_group bg ON (b.GROUP_ID = bg.ID)
				WHERE
					b.ACTIVE = 'Y'
					AND b.SEARCH_INDEX = 'Y'
					".('' !== $NS['SITE_ID'] ? "AND bg.SITE_ID='".$DB->ForSQL($NS['SITE_ID'])."'" : '').'
					AND b.ID > '.$id.'
				ORDER BY
					b.ID
			';
            // CBlogSearch::Trace('OnSearchReindex', 'strSql', $strSql);
            $rs = $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
            while ($ar = $rs->Fetch()) {
                if ('Y' === $ar['USE_SOCNET']) {
                    $Result = [
                        'ID' => 'B'.$ar['ID'],
                        'BODY' => '',
                        'TITLE' => '',
                    ];
                } else {
                    // CBlogSearch::Trace('OnSearchReindex', 'ar', $ar);
                    $arSite = [
                        $ar['SITE_ID'] => CBlog::PreparePath($ar['URL'], $ar['SITE_ID'], false, $ar['OWNER_ID'], $ar['SOCNET_GROUP_ID']),
                    ];
                    // CBlogSearch::Trace('OnSearchReindex', 'arSite', $arSite);
                    $Result = [
                        'ID' => 'B'.$ar['ID'],
                        'LAST_MODIFIED' => $ar['DATE_UPDATE'],
                        'TITLE' => $ar['NAME'],
                        'BODY' => blogTextParser::killAllTags($ar['DESCRIPTION']),
                        'SITE_ID' => $arSite,
                        'PARAM1' => 'BLOG',
                        'PARAM2' => $ar['OWNER_ID'],
                        'PERMISSIONS' => [2],
                    ];
                    // CBlogSearch::Trace('OnSearchReindex', 'Result', $Result);
                }
                if ($oCallback) {
                    $res = call_user_func([$oCallback, $callback_method], $Result);
                    if (!$res) {
                        return $Result['ID'];
                    }
                } else {
                    $arResult[] = $Result;
                }
            }
            // all blogs indexed so let's start index posts
            $category = 'P';
            $id = 0;
        }
        if ('P' === $category) {
            $arUser2Blog = [];
            if ('N' === COption::GetOptionString('blog', 'socNetNewPerms', 'N')) {
                $dbB = CBlog::GetList([], ['USE_SOCNET' => 'Y', '!OWNER_ID' => false], false, false, ['ID', 'OWNER_ID', 'USE_SOCNET', 'GROUP_ID']);
                while ($arB = $dbB->Fetch()) {
                    $arUser2Blog[$arB['OWNER_ID']][$arB['GROUP_ID']] = $arB['ID'];
                }
            }

            $bSonet = false;
            if (IsModuleInstalled('socialnetwork')) {
                $bSonet = true;
            }

            $parserBlog = new blogTextParser(false, '/bitrix/images/blog/smile/');

            $strSql = '
				SELECT
					bp.ID
					,bg.SITE_ID
					,b.REAL_URL
					,b.URL
					,'.$DB->DateToCharFunction('bp.DATE_PUBLISH').' as DATE_PUBLISH
					,bp.TITLE
					,bp.DETAIL_TEXT
					,bp.BLOG_ID
					,b.OWNER_ID
					,bp.CATEGORY_ID
					,b.SOCNET_GROUP_ID
					,b.USE_SOCNET
					,b.SEARCH_INDEX
					,b.GROUP_ID
					,bp.PATH
					,bp.MICRO
					,bp.PUBLISH_STATUS
					,bp.AUTHOR_ID '.
                    ($bSonet ? ', BSL.ID as SLID' : '').
                ' FROM
					b_blog_post bp
					INNER JOIN b_blog b ON (bp.BLOG_ID = b.ID)
					INNER JOIN b_blog_group bg ON (b.GROUP_ID = bg.ID) '.
                    ($bSonet ? "LEFT JOIN b_sonet_log BSL ON (BSL.EVENT_ID in ('blog_post', 'blog_post_micro', 'blog_post_important') AND BSL.SOURCE_ID = bp.ID) " : '').
                ' WHERE
					bp.DATE_PUBLISH <= '.$DB->CurrentTimeFunction()."
					AND b.ACTIVE = 'Y'
					".('' !== $NS['SITE_ID'] ? "AND bg.SITE_ID='".$DB->ForSQL($NS['SITE_ID'])."'" : '').'
					AND bp.ID > '.$id.'

				ORDER BY
					bp.ID
			';
            // AND bp.PUBLISH_STATUS = '".$DB->ForSQL(BLOG_PUBLISH_STATUS_PUBLISH)."'
            // AND b.SEARCH_INDEX = 'Y'
            // CBlogSearch::Trace('OnSearchReindex', 'strSql', $strSql);
            $rs = $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
            while ($ar = $rs->Fetch()) {
                // Check permissions
                $tag = '';
                if ('Y' !== $ar['USE_SOCNET']) {
                    $PostPerms = CBlogUserGroup::GetGroupPerms(1, $ar['BLOG_ID'], $ar['ID'], BLOG_PERMS_POST);
                    if ($PostPerms < BLOG_PERMS_READ) {
                        continue;
                    }
                }
                // CBlogSearch::Trace('OnSearchReindex', 'ar', $ar);
                if ('' !== $ar['PATH']) {
                    $arSite = [
                        $ar['SITE_ID'] => str_replace('#post_id#', $ar['ID'], $ar['PATH']),
                    ];
                } else {
                    $arSite = [
                        $ar['SITE_ID'] => CBlogPost::PreparePath($ar['URL'], $ar['ID'], $ar['SITE_ID'], false, $ar['OWNER_ID'], $ar['SOCNET_GROUP_ID']),
                    ];
                }

                if ('' !== $ar['CATEGORY_ID']) {
                    $arC = explode(',', $ar['CATEGORY_ID']);
                    $tag = '';
                    $arTag = [];
                    foreach ($arC as $v) {
                        $arCategory = CBlogCategory::GetByID($v);
                        $arTag[] = $arCategory['NAME'];
                    }
                    $tag = implode(',', $arTag);
                }

                // CBlogSearch::Trace('OnSearchReindex', 'arSite', $arSite);
                $Result = [
                    'ID' => 'P'.$ar['ID'],
                    'LAST_MODIFIED' => $ar['DATE_PUBLISH'],
                    'TITLE' => CSearch::KillTags(blogTextParser::killAllTags($ar['TITLE'])),
                    'BODY' => CSearch::KillTags(blogTextParser::killAllTags($ar['DETAIL_TEXT'])),
                    'SITE_ID' => $arSite,
                    'PARAM1' => 'POST',
                    'PARAM2' => $ar['BLOG_ID'],
                    'PERMISSIONS' => [2], // public
                    'TAGS' => $tag,
                    'USER_ID' => $ar['AUTHOR_ID'],
                    'ENTITY_TYPE_ID' => 'BLOG_POST',
                    'ENTITY_ID' => $ar['ID'],
                ];

                if ('Y' === $ar['USE_SOCNET'] && CModule::IncludeModule('socialnetwork')) {
                    $arF = [];
                    if ('N' === COption::GetOptionString('blog', 'socNetNewPerms', 'N')) {
                        if ((int) $ar['SOCNET_GROUP_ID'] > 0) {
                            $newBlogId = 0;
                            if ((int) $arUser2Blog[$ar['AUTHOR_ID']][$ar['GROUP_ID']] > 0) {
                                $newBlogId = (int) $arUser2Blog[$ar['AUTHOR_ID']][$ar['GROUP_ID']];
                            } else {
                                $arFields = [
                                    '=DATE_UPDATE' => $DB->CurrentTimeFunction(),
                                    'GROUP_ID' => $ar['GROUP_ID'],
                                    'ACTIVE' => 'Y',
                                    'ENABLE_COMMENTS' => 'Y',
                                    'ENABLE_IMG_VERIF' => 'Y',
                                    'EMAIL_NOTIFY' => 'Y',
                                    'ENABLE_RSS' => 'Y',
                                    'ALLOW_HTML' => 'N',
                                    'ENABLE_TRACKBACK' => 'N',
                                    'SEARCH_INDEX' => 'Y',
                                    'USE_SOCNET' => 'Y',
                                    '=DATE_CREATE' => $DB->CurrentTimeFunction(),
                                    'PERMS_POST' => [
                                        1 => 'I',
                                        2 => 'I'],
                                    'PERMS_COMMENT' => [
                                        1 => 'P',
                                        2 => 'P'],
                                ];

                                $bRights = false;
                                $rsUser = CUser::GetByID($ar['AUTHOR_ID']);
                                $arUser = $rsUser->Fetch();
                                if (($arUser['NAME'].''.$arUser['LAST_NAME']) === '') {
                                    $arFields['NAME'] = GetMessage('BLG_NAME').' '.$arUser['LOGIN'];
                                } else {
                                    $arFields['NAME'] = GetMessage('BLG_NAME').' '.$arUser['NAME'].' '.$arUser['LAST_NAME'];
                                }

                                $arFields['URL'] = str_replace(' ', '_', $arUser['LOGIN']).'-blog-'.$ar['SITE_ID'];
                                $arFields['OWNER_ID'] = $ar['AUTHOR_ID'];

                                $urlCheck = preg_replace('/[^a-zA-Z0-9_-]/is', '', $arFields['URL']);
                                if ($urlCheck !== $arFields['URL']) {
                                    $arFields['URL'] = 'u'.$arUser['ID'].'-blog-'.$ar['SITE_ID'];
                                }

                                if (CBlog::GetByUrl($arFields['URL'])) {
                                    $uind = 0;
                                    do {
                                        ++$uind;
                                        $arFields['URL'] .= $uind;
                                    } while (CBlog::GetByUrl($arFields['URL']));
                                }

                                $featureOperationPerms = CSocNetFeaturesPerms::GetOperationPerm(SONET_ENTITY_USER, $ar['AUTHOR_ID'], 'blog', 'view_post');
                                if (SONET_RELATIONS_TYPE_ALL === $featureOperationPerms) {
                                    $bRights = true;
                                }

                                $blogID = CBlog::Add($arFields);

                                if ($bRights) {
                                    CBlog::AddSocnetRead($blogID);
                                }

                                $newBlogId = $blogID;
                                $arUser2Blog[$arFields['OWNER_ID']][$arFields['GROUP_ID']] = $newBlogId;
                            }

                            if ((int) $newBlogId > 0) {
                                $arF = [
                                    'BLOG_ID' => $newBlogId,
                                    'SOCNET_RIGHTS' => ['SG'.$ar['SOCNET_GROUP_ID']],
                                ];
                            }

                            if ((int) $ar['SLID'] > 0) {
                                CSocNetLog::Delete($ar['SLID']);
                                $ar['SLID'] = 0;
                            }

                            $arSites = [];
                            $rsGroupSite = CSocNetGroup::GetSite($ar['SOCNET_GROUP_ID']);
                            while ($arGroupSite = $rsGroupSite->Fetch()) {
                                $arSites[] = $arGroupSite['LID'];
                            }
                        } else {
                            $newBlogId = 0;
                            if ($ar['OWNER_ID'] !== $ar['AUTHOR_ID']) {
                                if ((int) $arUser2Blog[$ar['AUTHOR_ID']][$ar['GROUP_ID']] > 0) {
                                    $newBlogId = (int) $arUser2Blog[$ar['AUTHOR_ID']][$ar['GROUP_ID']];
                                } else {
                                    $arFields = [
                                        '=DATE_UPDATE' => $DB->CurrentTimeFunction(),
                                        'GROUP_ID' => $ar['GROUP_ID'],
                                        'ACTIVE' => 'Y',
                                        'ENABLE_COMMENTS' => 'Y',
                                        'ENABLE_IMG_VERIF' => 'Y',
                                        'EMAIL_NOTIFY' => 'Y',
                                        'ENABLE_RSS' => 'Y',
                                        'ALLOW_HTML' => 'N',
                                        'ENABLE_TRACKBACK' => 'N',
                                        'SEARCH_INDEX' => 'Y',
                                        'USE_SOCNET' => 'Y',
                                        '=DATE_CREATE' => $DB->CurrentTimeFunction(),
                                        'PERMS_POST' => [
                                            1 => 'I',
                                            2 => 'I'],
                                        'PERMS_COMMENT' => [
                                            1 => 'P',
                                            2 => 'P'],
                                    ];

                                    $bRights = false;
                                    $rsUser = CUser::GetByID($ar['AUTHOR_ID']);
                                    $arUser = $rsUser->Fetch();
                                    if (($arUser['NAME'].''.$arUser['LAST_NAME']) === '') {
                                        $arFields['NAME'] = GetMessage('BLG_NAME').' '.$arUser['LOGIN'];
                                    } else {
                                        $arFields['NAME'] = GetMessage('BLG_NAME').' '.$arUser['NAME'].' '.$arUser['LAST_NAME'];
                                    }

                                    $arFields['URL'] = str_replace(' ', '_', $arUser['LOGIN']).'-blog-'.$ar['SITE_ID'];
                                    $arFields['OWNER_ID'] = $ar['AUTHOR_ID'];

                                    $urlCheck = preg_replace('/[^a-zA-Z0-9_-]/is', '', $arFields['URL']);
                                    if ($urlCheck !== $arFields['URL']) {
                                        $arFields['URL'] = 'u'.$arUser['ID'].'-blog-'.$ar['SITE_ID'];
                                    }

                                    if (CBlog::GetByUrl($arFields['URL'])) {
                                        $uind = 0;
                                        do {
                                            ++$uind;
                                            $arFields['URL'] .= $uind;
                                        } while (CBlog::GetByUrl($arFields['URL']));
                                    }

                                    $featureOperationPerms = CSocNetFeaturesPerms::GetOperationPerm(SONET_ENTITY_USER, $ar['AUTHOR_ID'], 'blog', 'view_post');
                                    if (SONET_RELATIONS_TYPE_ALL === $featureOperationPerms) {
                                        $bRights = true;
                                    }

                                    $blogID = CBlog::Add($arFields);
                                    if ($bRights) {
                                        CBlog::AddSocnetRead($blogID);
                                    }
                                    $newBlogId = $blogID;

                                    $arUser2Blog[$arFields['OWNER_ID']][$arFields['GROUP_ID']] = $newBlogId;
                                }
                                if ((int) $ar['SLID'] > 0) {
                                    CSocNetLog::Delete($ar['SLID']);
                                    $ar['SLID'] = 0;
                                }
                            }
                            $arF = ['SOCNET_RIGHTS' => []];
                            if ((int) $newBlogId > 0) {
                                $arF['BLOG_ID'] = $newBlogId;
                            }

                            $arSites = [$ar['SITE_ID']];
                        }

                        if (!empty($arF)) {
                            if ((int) $arF['BLOG_ID'] > 0) {
                                $Result['PARAM2'] = $ar['BLOG_ID'];
                                $sqlR = 'UPDATE b_blog_post SET BLOG_ID='.(int) $arF['BLOG_ID'].' WHERE ID='.(int) $ar['ID'];
                                $DB->Query($sqlR, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
                                $sqlR = 'UPDATE b_blog_post_category SET BLOG_ID='.(int) $arF['BLOG_ID'].' WHERE POST_ID='.(int) $ar['ID'];
                                $DB->Query($sqlR, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
                                $sqlR = 'UPDATE b_blog_image SET BLOG_ID='.(int) $arF['BLOG_ID'].' WHERE POST_ID='.(int) $ar['ID'];
                                $DB->Query($sqlR, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
                                $sqlR = 'UPDATE b_blog_comment SET BLOG_ID='.(int) $arF['BLOG_ID'].' WHERE POST_ID='.(int) $ar['ID'];
                                $DB->Query($sqlR, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
                            }
                            $sqlR = 'SELECT * FROM b_blog_socnet_rights where POST_ID='.(int) $ar['ID'];
                            $dbBB = $DB->Query($sqlR);
                            if (!$dbBB->Fetch()) {
                                $arF['SC_PERM'] = CBlogPost::UpdateSocNetPerms($ar['ID'], $arF['SOCNET_RIGHTS'], ['AUTHOR_ID' => $ar['AUTHOR_ID']]);
                            }
                            if ((int) $arF['BLOG_ID'] > 0 && BLOG_PUBLISH_STATUS_PUBLISH === $ar['PUBLISH_STATUS']) {
                                $dbComment = CBlogComment::GetList([], ['POST_ID' => $ar['ID']], false, false, ['ID', 'POST_ID', 'BLOG_ID', 'PATH']);
                                if ($arComment = $dbComment->Fetch()) {
                                    $arParamsComment = [
                                        'BLOG_ID' => $arF['BLOG_ID'],
                                        'POST_ID' => $ar['ID'],
                                        'SITE_ID' => $ar['SITE_ID'],
                                        'PATH' => $arPostSite[$arGroup['SITE_ID']].'?commentId=#comment_id##com#comment_id#',
                                        'USE_SOCNET' => 'Y',
                                    ];
                                    CBlogComment::_IndexPostComments($arParamsComment);
                                }
                            }
                        }
                    }

                    if (BLOG_PUBLISH_STATUS_PUBLISH === $ar['PUBLISH_STATUS']) {
                        if (empty($arF['SC_PERM'])) {
                            $arF['SC_PERM'] = CBlogPost::GetSocNetPermsCode($ar['ID']);
                        }
                        $Result['PERMISSIONS'] = $arF['SC_PERM'];
                        if (!in_array('U'.$ar['AUTHOR_ID'], $Result['PERMISSIONS'], true)) {
                            $Result['PERMISSIONS'][] = 'U'.$ar['AUTHOR_ID'];
                        }

                        if (is_array($arF['SC_PERM'])) {
                            $sgId = [];
                            foreach ($arF['SC_PERM'] as $perm) {
                                if (str_contains($perm, 'SG')) {
                                    $sgIdTmp = str_replace('SG', '', substr($perm, 0, strpos($perm, '_')));
                                    if (!in_array($sgIdTmp, $sgId, true) && (int) $sgIdTmp > 0) {
                                        $sgId[] = $sgIdTmp;
                                    }
                                }
                            }

                            if (!empty($sgId)) {
                                $Result['PARAMS'] = [
                                    'socnet_group' => $sgId,
                                    'entity' => 'socnet_group',
                                ];
                            }
                        }

                        // get mentions and grats
                        $arMentionedUserID = CBlogPost::GetMentionedUserID($ar);

                        if (!empty($arMentionedUserID)) {
                            if (!isset($Result['PARAMS'])) {
                                $Result['PARAMS'] = [];
                            }
                            $Result['PARAMS']['mentioned_user_id'] = $arMentionedUserID;
                        }

                        $socnetPerms = false;

                        if ((int) $ar['SLID'] <= 0) {
                            $arAllow = ['HTML' => 'N', 'ANCHOR' => 'N', 'BIU' => 'N', 'IMG' => 'N', 'QUOTE' => 'N', 'CODE' => 'N', 'FONT' => 'N', 'TABLE' => 'N', 'LIST' => 'N', 'SMILES' => 'N', 'NL2BR' => 'N', 'VIDEO' => 'N'];
                            $text4message = $parserBlog->convert($ar['DETAIL_TEXT'], false, [], $arAllow, ['isSonetLog' => true]);

                            $arSoFields = [
                                'EVENT_ID' => 'blog_post',
                                '=LOG_DATE' => $DB->CharToDateFunction($ar['DATE_PUBLISH'], 'FULL', SITE_ID),
                                'LOG_UPDATE' => $DB->CharToDateFunction($ar['DATE_PUBLISH'], 'FULL', SITE_ID),
                                'TITLE_TEMPLATE' => '#USER_NAME# add post',
                                'TITLE' => $ar['TITLE'],
                                'MESSAGE' => $text4message,
                                'MODULE_ID' => 'blog',
                                'CALLBACK_FUNC' => false,
                                'SOURCE_ID' => $ar['ID'],
                                'ENABLE_COMMENTS' => 'Y',
                                'ENTITY_TYPE' => SONET_ENTITY_USER,
                                'ENTITY_ID' => $ar['AUTHOR_ID'],
                                'USER_ID' => $ar['AUTHOR_ID'],
                                'URL' => $arSite[$ar['SITE_ID']],
                                'SITE_ID' => $arSites,
                            ];
                            $logID = CSocNetLog::Add($arSoFields, false);
                            if ((int) $logID > 0) {
                                $socnetPerms = $arF['SC_PERM'];

                                if (!in_array('U'.$ar['AUTHOR_ID'], $socnetPerms, true)) {
                                    $socnetPerms[] = 'U'.$ar['AUTHOR_ID'];
                                }
                                $socnetPerms[] = 'SA'; // socnet admin
                                CSocNetLog::Update($logID, ['TMP_ID' => $logID, '=LOG_UPDATE' => $arSoFields['LOG_UPDATE']]);
                            }
                        } else {
                            $socnetPerms = $arF['SC_PERM'];

                            if (!in_array('U'.$ar['AUTHOR_ID'], $socnetPerms, true)) {
                                $socnetPerms[] = 'U'.$ar['AUTHOR_ID'];
                            }
                            $socnetPerms[] = 'SA'; // socnet admin
                            $logID = $ar['SLID'];
                        }

                        if (
                            (int) $logID > 0
                            && is_array($socnetPerms)
                        ) {
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

                            CSocNetLogRights::DeleteByLogID($logID);
                            CSocNetLogRights::Add($logID, $socnetPerms, false, false);
                        }
                    }
                }

                if (BLOG_PUBLISH_STATUS_PUBLISH === $ar['PUBLISH_STATUS'] && 'Y' === $ar['SEARCH_INDEX']) {
                    // CBlogSearch::Trace('OnSearchReindex', 'Result', $Result);
                    if ($oCallback) {
                        $res = call_user_func([$oCallback, $callback_method], $Result);
                        if (!$res) {
                            return $Result['ID'];
                        }
                    } else {
                        $arResult[] = $Result;
                    }
                }
            }
            // all blog posts indexed so let's start index users
            $category = 'C';
            $id = 0;
            COption::SetOptionString('blog', 'socNetNewPerms', 'Y');
        }
        if ('C' === $category) {
            $strSql = '
				SELECT
					bc.ID
					,bg.SITE_ID
					,bp.ID as POST_ID
					,b.URL
					,bp.TITLE as POST_TITLE
					,b.OWNER_ID
					,b.SOCNET_GROUP_ID
					,bc.TITLE
					,bc.POST_TEXT
					,bc.POST_ID
					,bc.BLOG_ID
					,b.USE_SOCNET
					,b.SEARCH_INDEX
					,bc.PATH
					,'.$DB->DateToCharFunction('bc.DATE_CREATE').' as DATE_CREATE
					,bc.AUTHOR_ID
				FROM
					b_blog_comment bc
					INNER JOIN b_blog_post bp ON (bp.ID = bc.POST_ID)
					INNER JOIN b_blog b ON (bc.BLOG_ID = b.ID)
					INNER JOIN b_blog_group bg ON (b.GROUP_ID = bg.ID)
				WHERE
					bc.ID > '.$id.'
					'.('' !== $NS['SITE_ID'] ? " AND bg.SITE_ID='".$DB->ForSQL($NS['SITE_ID'])."'" : '')."
					AND b.SEARCH_INDEX = 'Y'
				ORDER BY
					bc.ID
			";
            // CBlogSearch::Trace('OnSearchReindex', 'strSql', $strSql);
            $rs = $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
            while ($ar = $rs->Fetch()) {
                // Check permissions
                $tag = '';
                $PostPerms = CBlogUserGroup::GetGroupPerms(1, $ar['BLOG_ID'], $ar['POST_ID'], BLOG_PERMS_POST);
                if ($PostPerms < BLOG_PERMS_READ) {
                    continue;
                }
                // CBlogSearch::Trace('OnSearchReindex', 'ar', $ar);
                if ('' !== $ar['PATH']) {
                    $arSite = [
                        $ar['SITE_ID'] => str_replace('#comment_id#', $ar['ID'], $ar['PATH']),
                    ];
                } else {
                    $arSite = [
                        $ar['SITE_ID'] => CBlogPost::PreparePath($ar['URL'], $ar['POST_ID'], $ar['SITE_ID'], false, $ar['OWNER_ID'], $ar['SOCNET_GROUP_ID']),
                    ];
                }

                $Result = [
                    'ID' => 'C'.$ar['ID'],
                    'SITE_ID' => $arSite,
                    'LAST_MODIFIED' => $ar['DATE_CREATE'],
                    'PARAM1' => 'COMMENT',
                    'PARAM2' => $ar['BLOG_ID'].'|'.$ar['POST_ID'],
                    'PERMISSIONS' => [2],
                    'TITLE' => CSearch::KillTags($ar['TITLE']),
                    'BODY' => CSearch::KillTags(blogTextParser::killAllTags($ar['POST_TEXT'])),
                    'INDEX_TITLE' => false,
                    'USER_ID' => ((int) $ar['AUTHOR_ID'] > 0) ? $ar['AUTHOR_ID'] : false,
                    'ENTITY_TYPE_ID' => 'BLOG_COMMENT',
                    'ENTITY_ID' => $ar['ID'],
                ];
                if ('Y' === $ar['USE_SOCNET']) {
                    $arSp = CBlogComment::GetSocNetCommentPerms($ar['POST_ID']);
                    if (is_array($arSp)) {
                        $Result['PERMISSIONS'] = $arSp;
                    }

                    if (!in_array('U'.$ar['AUTHOR_ID'], $Result['PERMISSIONS'], true)) {
                        $Result['PERMISSIONS'][] = 'U'.$ar['AUTHOR_ID'];
                    }

                    if (is_array($arSp)) {
                        $sgId = [];
                        foreach ($arSp as $perm) {
                            if (str_contains($perm, 'SG')) {
                                $sgIdTmp = str_replace('SG', '', substr($perm, 0, strpos($perm, '_')));
                                if (
                                    !in_array($sgIdTmp, $sgId, true)
                                    && (int) $sgIdTmp > 0
                                ) {
                                    $sgId[] = $sgIdTmp;
                                }
                            }
                        }

                        if (!empty($sgId)) {
                            $Result['PARAMS'] = [
                                'socnet_group' => $sgId,
                                'entity' => 'socnet_group',
                            ];
                        }
                    }

                    // get mentions
                    $arMentionedUserID = CBlogComment::GetMentionedUserID($ar);

                    if (!empty($arMentionedUserID)) {
                        if (!isset($Result['PARAMS'])) {
                            $Result['PARAMS'] = [];
                        }
                        $Result['PARAMS']['mentioned_user_id'] = $arMentionedUserID;
                    }
                }

                if ('' === $ar['TITLE']) {
                    $Result['TITLE'] = substr($Result['BODY'], 0, 100);
                }

                if ($oCallback) {
                    $res = call_user_func([$oCallback, $callback_method], $Result);
                    if (!$res) {
                        return $Result['ID'];
                    }
                } else {
                    $arResult[] = $Result;
                }
            }
            // all blog posts indexed so let's start index users
            $category = 'U';
            $id = 0;
        }

        if ('U' === $category) {
            $strSql = '
				SELECT
					bu.ID
					,bg.SITE_ID
					,'.$DB->DateToCharFunction('bu.LAST_VISIT').' as LAST_VISIT
					,'.$DB->DateToCharFunction('u.DATE_REGISTER')." as DATE_REGISTER
					,bu.ALIAS
					,bu.DESCRIPTION
					,bu.INTERESTS
					,u.NAME
					,u.LAST_NAME
					,u.LOGIN
					,bu.USER_ID
					,b.OWNER_ID
					,b.USE_SOCNET
					,b.SEARCH_INDEX
				FROM
					b_blog_user bu
					INNER JOIN b_user u  ON (u.ID = bu.USER_ID)
					INNER JOIN b_blog b ON (u.ID = b.OWNER_ID)
					INNER JOIN b_blog_group bg ON (b.GROUP_ID = bg.ID)
				WHERE
					b.ACTIVE = 'Y'
					".('' !== $NS['SITE_ID'] ? "AND bg.SITE_ID='".$DB->ForSQL($NS['SITE_ID'])."'" : '').'
					AND bu.ID > '.$id."
					AND b.SEARCH_INDEX = 'Y'
				ORDER BY
					bu.ID
			";
            // CBlogSearch::Trace('OnSearchReindex', 'strSql', $strSql);
            $rs = $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
            while ($ar = $rs->Fetch()) {
                if ('Y' === $ar['USE_SOCNET']) {
                    $Result = [
                        'ID' => 'U'.$ar['ID'],
                        'BODY' => '',
                        'TITLE' => '',
                    ];
                } else {
                    // CBlogSearch::Trace('OnSearchReindex', 'ar', $ar);
                    $arSite = [
                        $ar['SITE_ID'] => CBlogUser::PreparePath($ar['USER_ID'], $ar['SITE_ID']),
                    ];
                    // CBlogSearch::Trace('OnSearchReindex', 'arSite', $arSite);
                    $Result = [
                        'ID' => 'U'.$ar['ID'],
                        'LAST_MODIFIED' => $ar['LAST_VISIT'],
                        'TITLE' => CBlogUser::GetUserName($ar['ALIAS'], $ar['NAME'], $ar['LAST_NAME'], $ar['LOGIN']),
                        'BODY' => blogTextParser::killAllTags($ar['DESCRIPTION'].' '.$ar['INTERESTS']),
                        'SITE_ID' => $arSite,
                        'PARAM1' => 'USER',
                        'PARAM2' => $ar['ID'],
                        'PERMISSIONS' => [2], // public
                        // TODO????"URL"		=>$DETAIL_URL,
                    ];
                    if ('' === $Result['LAST_MODIFIED']) {
                        $Result['LAST_MODIFIED'] = $ar['DATE_REGISTER'];
                    }
                }

                // CBlogSearch::Trace('OnSearchReindex', 'Result', $Result);
                if ($oCallback) {
                    $res = call_user_func([$oCallback, $callback_method], $Result);
                    if (!$res) {
                        return $Result['ID'];
                    }
                } else {
                    $arResult[] = $Result;
                }
            }
        }
        if ($oCallback) {
            return false;
        }

        return $arResult;
    }

    public static function Trace($method, $varname, $var)
    {
        // return;
        ob_start();
        print_r($var);
        $m = ob_get_contents();
        ob_end_clean();
        $m = " CBlogSearch::{$method}:{$varname}:{$m}\n";
        $f = fopen($_SERVER['DOCUMENT_ROOT'].'/debug.log', 'a');
        fwrite($f, time().$m);
        fclose($f);
    }

    public static function SetSoNetFeatureIndexSearch($ID, $arFields)
    {
        if (CModule::IncludeModule('socialnetwork')) {
            $feature = CSocNetFeatures::GetByID($ID);
            if ('blog' === $feature['FEATURE']) {
                if ((int) $feature['ENTITY_ID'] > 0) {
                    $bRights = false;
                    $arFilter = ['USE_SOCNET' => 'Y'];

                    if ('U' === $feature['ENTITY_TYPE']) {
                        $arFilter['OWNER_ID'] = $feature['ENTITY_ID'];
                        $featureOperationPerms = CSocNetFeaturesPerms::GetOperationPerm(SONET_ENTITY_USER, $feature['ENTITY_ID'], 'blog', 'view_post');
                        if (SONET_RELATIONS_TYPE_ALL === $featureOperationPerms) {
                            $bRights = true;
                        }
                    } else {
                        $arFilter['SOCNET_GROUP_ID'] = $feature['ENTITY_ID'];
                        $featureOperationPerms = CSocNetFeaturesPerms::GetOperationPerm(SONET_ENTITY_GROUP, $feature['ENTITY_ID'], 'blog', 'view_post');
                        if (SONET_ROLES_ALL === $featureOperationPerms) {
                            $bRights = true;
                        }
                    }

                    $dbBlog = CBlog::GetList([], $arFilter, false, ['nTopCount' => 1], ['ID', 'SOCNET_GROUP_ID']);
                    if ($arBlog = $dbBlog->Fetch()) {
                        if ((int) $arBlog['SOCNET_GROUP_ID'] > 0 && CModule::IncludeModule('socialnetwork') && method_exists('CSocNetGroup', 'GetSite')) {
                            $arSites = [];
                            $rsGroupSite = CSocNetGroup::GetSite($arBlog['SOCNET_GROUP_ID']);
                            while ($arGroupSite = $rsGroupSite->Fetch()) {
                                $arSites[] = $arGroupSite['LID'];
                            }
                        } else {
                            $arSites = [SITE_ID];
                        }

                        foreach ($arSites as $site_id_tmp) {
                            BXClearCache(true, '/'.$site_id_tmp.'/blog/sonet/');
                        }

                        if ('N' === $arFields['ACTIVE']) {
                            CBlog::DeleteSocnetRead($arBlog['ID']);
                        } else {
                            if ($bRights) {
                                CBlog::AddSocnetRead($arBlog['ID']);
                            } else {
                                CBlog::DeleteSocnetRead($arBlog['ID']);
                            }
                        }
                    }
                }
            }
        }
    }

    public static function SetSoNetFeaturePermIndexSearch($ID, $arFields)
    {
        $featurePerm = CSocNetFeaturesPerms::GetByID($ID);
        if ('view_post' === $featurePerm['OPERATION_ID']) {
            if (CModule::IncludeModule('socialnetwork')) {
                $feature = CSocNetFeatures::GetByID($featurePerm['FEATURE_ID']);
                if ('blog' === $feature['FEATURE'] && (int) $feature['ENTITY_ID'] > 0) {
                    if ('Y' === $feature['ACTIVE'] && (('U' === $feature['ENTITY_TYPE'] && 'A' === $arFields['ROLE']) || ('G' === $feature['ENTITY_TYPE'] && 'N' === $arFields['ROLE']))) {
                        $arFilter = ['USE_SOCNET' => 'Y'];
                        if ('U' === $feature['ENTITY_TYPE']) {
                            $arFilter['OWNER_ID'] = $feature['ENTITY_ID'];
                        } else {
                            $arFilter['SOCNET_GROUP_ID'] = $feature['ENTITY_ID'];
                        }
                        $dbBlog = CBlog::GetList([], $arFilter, false, ['nTopCount' => 1], ['ID', 'SOCNET_GROUP_ID']);
                        if ($arBlog = $dbBlog->Fetch()) {
                            CBlog::AddSocnetRead($arBlog['ID']);
                        }
                    } else {
                        $arFilter = ['USE_SOCNET' => 'Y'];
                        if ('U' === $feature['ENTITY_TYPE']) {
                            $arFilter['OWNER_ID'] = $feature['ENTITY_ID'];
                        } else {
                            $arFilter['SOCNET_GROUP_ID'] = $feature['ENTITY_ID'];
                        }
                        $dbBlog = CBlog::GetList([], $arFilter, false, ['nTopCount' => 1], ['ID', 'SOCNET_GROUP_ID']);
                        if ($arBlog = $dbBlog->Fetch()) {
                            CBlog::DeleteSocnetRead($arBlog['ID']);
                        }
                    }

                    if ($arBlog && (int) $arBlog['SOCNET_GROUP_ID'] > 0 && CModule::IncludeModule('socialnetwork') && method_exists('CSocNetGroup', 'GetSite')) {
                        $arSites = [];
                        $rsGroupSite = CSocNetGroup::GetSite($arBlog['SOCNET_GROUP_ID']);
                        while ($arGroupSite = $rsGroupSite->Fetch()) {
                            $arSites[] = $arGroupSite['LID'];
                        }
                    } else {
                        $arSites = [SITE_ID];
                    }

                    foreach ($arSites as $site_id_tmp) {
                        BXClearCache(true, '/'.$site_id_tmp.'/blog/sonet/');
                    }
                }
            }
        }
    }
}
