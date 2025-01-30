<?php

IncludeModuleLangFile(__FILE__);

class blog_metaweblog
{
    public static function Authorize($user, $password)
    {
        global $USER, $APPLICATION;

        $arAuthResult = $USER->Login($user, $password, 'Y');
        $APPLICATION->arAuthResult = $arAuthResult;
        if ($USER->IsAuthorized() && '' === $arAuthResult['MESSAGE']) {
            return true;
        }

        return false;
    }

    public static function DecodeParams($value)
    {
        foreach ($value as $t => $v) {
            if ('base64' === $t) {
                return base64_decode($v[0]['#'], true);
            }

            return $v[0]['#'];
        }
    }

    public static function GetUsersBlogs($params, $arPath)
    {
        global $USER;
        $blog = CBlogMetaWeblog::DecodeParams($params[0]['#']['value'][0]['#']);
        $user = CBlogMetaWeblog::DecodeParams($params[1]['#']['value'][0]['#']);
        $password = CBlogMetaWeblog::DecodeParams($params[2]['#']['value'][0]['#']);

        if (CBlogMetaWeblog::Authorize($user, $password)) {
            $result = '';
            $userId = $USER->GetID();

            $dbBlog = CBlog::GetList([], ['OWNER_ID' => $userId, 'GROUP_SITE_ID' => SITE_ID, 'ACTIVE' => 'Y'], false, false, ['ID', 'URL', 'NAME', 'OWNER_ID']);
            while ($arBlog = $dbBlog->GetNext()) {
                if ('' !== $arPath['PATH_TO_BLOG']) {
                    if (defined('SITE_SERVER_NAME') && SITE_SERVER_NAME !== '') {
                        $serverName = SITE_SERVER_NAME;
                    } else {
                        $serverName = COption::GetOptionString('main', 'server_name', '');
                    }
                    if ('' === $serverName) {
                        $serverName = $_SERVER['SERVER_NAME'];
                    }

                    $path2Blog = 'http://'.$serverName.CComponentEngine::MakePathFromTemplate($arPath['PATH_TO_BLOG'], ['blog' => $arBlog['URL'], 'user_id' => $arBlog['OWNER_ID']]);
                } else {
                    $path2Blog = $arBlog['URL'];
                }
                $result .= '
						<value>
							<struct>
								<member>
									<name>url</name>
									<value>'.$path2Blog.'</value>
								</member>
								<member>
									<name>blogid</name>
									<value>'.$arBlog['ID'].'</value>
								</member>
								<member>
									<name>blogName</name>
									<value>'.$arBlog['NAME'].'</value>
								</member>
							</struct>
						</value>
					';
            }

            if (CModule::IncludeModule('socialnetwork')) {
                $arGroupFilter = [
                    'USER_ID' => $userId,
                    '<=ROLE' => SONET_ROLES_USER,
                    'GROUP_SITE_ID' => SITE_ID,
                    'GROUP_ACTIVE' => 'Y',
                ];

                $dbGroups = CSocNetUserToGroup::GetList(
                    ['GROUP_NAME' => 'ASC'],
                    $arGroupFilter,
                    false,
                    false,
                    ['ID', 'GROUP_ID', 'GROUP_OWNER_ID', 'GROUP_NAME', 'GROUP_SITE_ID']
                );
                while ($arGroups = $dbGroups->GetNext()) {
                    $perms = BLOG_PERMS_DENY;

                    if (CSocNetFeaturesPerms::CanPerformOperation($userId, SONET_ENTITY_GROUP, $arGroups['GROUP_ID'], 'blog', 'write_post', CSocNetUser::IsCurrentUserModuleAdmin($arGroups['GROUP_SITE_ID']))) {
                        $perms = BLOG_PERMS_WRITE;
                    } elseif (CSocNetFeaturesPerms::CanPerformOperation($userId, SONET_ENTITY_GROUP, $arGroups['GROUP_ID'], 'blog', 'full_post')) {
                        $perms = BLOG_PERMS_FULL;
                    }

                    if ($perms >= BLOG_PERMS_WRITE) {
                        $dbBlog = CBlog::GetList([], ['SOCNET_GROUP_ID' => $arGroups['GROUP_ID'], 'GROUP_SITE_ID' => SITE_ID, 'ACTIVE' => 'Y'], false, false, ['ID', 'URL', 'NAME']);
                        if ($arBlog = $dbBlog->GetNext()) {
                            $result .= '
									<value>
										<struct>
											<member>
												<name>url</name>
												<value>'.$arBlog['URL'].'</value>
											</member>
											<member>
												<name>blogid</name>
												<value>'.$arBlog['ID'].'</value>
											</member>
											<member>
												<name>blogName</name>
												<value>'.$arBlog['NAME'].'</value>
											</member>
										</struct>
									</value>
								';
                        }
                    }
                }
            }

            if ('' !== $result) {
                return '<params>
							<param>
								<value>
									<array>
										<data>'
                                            .$result.
                                        '</data>
									</array>
								</value>
							</param>
						</params>';
            }

            return '<fault>
					<value>
						<struct>
							<member>
								<name>faultCode</name>
								<value><int>4</int></value>
								</member>
							<member>
								<name>faultString</name>
								<value><string>User hasn\'t blog.</string></value>
								</member>
							</struct>
						</value>
					</fault>';
        }

        return '<fault>
				<value>
					<struct>
						<member>
							<name>faultCode</name>
							<value><int>3</int></value>
							</member>
						<member>
							<name>faultString</name>
							<value><string>'.$arAuthResult['MESSAGE'].'</string></value>
							</member>
						</struct>
					</value>
				</fault>';
    }

    public static function GetCategories($params)
    {
        global $USER;
        $blog = CBlogMetaWeblog::DecodeParams($params[0]['#']['value'][0]['#']);
        $user = CBlogMetaWeblog::DecodeParams($params[1]['#']['value'][0]['#']);
        $password = CBlogMetaWeblog::DecodeParams($params[2]['#']['value'][0]['#']);

        if (CBlogMetaWeblog::Authorize($user, $password)) {
            $result = '';
            $userId = $USER->GetID();

            $dbBlog = CBlog::GetList([], ['GROUP_SITE_ID' => SITE_ID, 'ACTIVE' => 'Y', 'ID' => $blog], false, false, ['ID', 'URL', 'NAME']);
            if ($arBlog = $dbBlog->GetNext()) {
                $dbCategory = CBlogCategory::GetList(['NAME' => 'ASC'], ['BLOG_ID' => $arBlog['ID']]);
                while ($arCategory = $dbCategory->GetNext()) {
                    $result .= '
							<value>
								<struct>
									<member>
										<name>description</name>
										<value>'.$arCategory['NAME'].'</value>
									</member>
									<member>
										<name>title</name>
										<value>'.$arCategory['NAME'].'</value>
									</member>
								</struct>
							</value>
						';
                }
            }

            if ('' !== $result) {
                return '<params>
							<param>
								<value>
									<array>
										<data>'
                                            .$result.
                                        '</data>
									</array>
								</value>
							</param>
						</params>';
            }

            return '<fault>
					<value>
						<struct>
							<member>
								<name>faultCode</name>
								<value><int>4</int></value>
							</member>
							<member>
								<name>faultString</name>
								<value><string>No categories in blog.</string></value>
							</member>
							</struct>
						</value>
					</fault>';
        }

        return '<fault>
				<value>
					<struct>
						<member>
							<name>faultCode</name>
							<value><int>3</int></value>
							</member>
						<member>
							<name>faultString</name>
							<value><string>'.$arAuthResult['MESSAGE'].'</string></value>
							</member>
						</struct>
					</value>
				</fault>';
    }

    public static function GetRecentPosts($params, $arPath)
    {
        global $USER;
        $blogId = (int) CBlogMetaWeblog::DecodeParams($params[0]['#']['value'][0]['#']);
        $user = CBlogMetaWeblog::DecodeParams($params[1]['#']['value'][0]['#']);
        $password = CBlogMetaWeblog::DecodeParams($params[2]['#']['value'][0]['#']);
        $numPosts = (int) CBlogMetaWeblog::DecodeParams($params[3]['#']['value'][0]['#']);
        if ($numPosts <= 0) {
            $numPosts = 1;
        } elseif ($numPosts > 20) {
            $numPosts = 20;
        }

        if (CBlogMetaWeblog::Authorize($user, $password)) {
            $result = '';
            $userId = $USER->GetID();

            if ((int) $blogId > 0) {
                $dbBlog = CBlog::GetList([], ['GROUP_SITE_ID' => SITE_ID, 'ACTIVE' => 'Y', 'ID' => $blogId], false, false, ['ID', 'URL', 'NAME']);
                if ($arBlog = $dbBlog->GetNext()) {
                    $parser = new blogTextParser();
                    $arSelectedFields = ['ID', 'BLOG_ID', 'TITLE', 'DATE_PUBLISH', 'AUTHOR_ID', 'DETAIL_TEXT', 'DETAIL_TEXT_TYPE'];
                    $dbPost = CBlogPost::GetList(['DATE_PUBLISH' => 'DESC', 'ID' => 'DESC'], ['BLOG_ID' => $blogId], false, ['nTopCount' => $numPosts], $arSelectedFields);
                    while ($arPost = $dbPost->GetNext()) {
                        $dateISO = date('Y-m-d\\TH:i:s', MakeTimeStamp($arPost['DATE_PUBLISH']));
                        $title = htmlspecialcharsEx($arPost['TITLE']);
                        $arImages = [];
                        $res = CBlogImage::GetList(['ID' => 'ASC'], ['POST_ID' => $arPost['ID'], 'BLOG_ID' => $arPost['BLOG_ID'], 'IS_COMMENT' => 'N']);
                        while ($arImage = $res->Fetch()) {
                            $arImages[$arImage['ID']] = $arImage['FILE_ID'];
                        }

                        if ('html' === $arPost['DETAIL_TEXT_TYPE']) {
                            $arAllow = ['HTML' => 'Y', 'ANCHOR' => 'Y', 'IMG' => 'Y', 'SMILES' => 'Y', 'NL2BR' => 'N', 'VIDEO' => 'N', 'QUOTE' => 'N', 'CODE' => 'N'];

                            $text = $parser->convert_to_rss($arPost['DETAIL_TEXT'], $arImages, $arAllow, false);
                        } else {
                            $arAllow = ['HTML' => 'N', 'ANCHOR' => 'Y', 'BIU' => 'Y', 'IMG' => 'Y', 'QUOTE' => 'N', 'CODE' => 'N', 'FONT' => 'Y', 'LIST' => 'Y', 'SMILES' => 'Y', 'NL2BR' => 'N', 'VIDEO' => 'N'];
                            $text = $parser->convert_to_rss(htmlspecialcharsEx($arPost['DETAIL_TEXT']), $arImages, $arAllow, false);
                        }
                        $text = '<![CDATA['.$text.']]>';

                        $category = '';
                        $dbCategory = CBlogPostCategory::GetList([], ['BLOG_ID' => $arPost['BLOG_ID'], 'POST_ID' => $arPost['ID']]);
                        while ($arCategory = $dbCategory->Fetch()) {
                            $category .= '<value>'.htmlspecialcharsEx($arCategory['NAME']).'</value>';
                        }

                        $path2Post = '';
                        if ('' !== $arPath['PATH_TO_POST']) {
                            if (defined('SITE_SERVER_NAME') && SITE_SERVER_NAME !== '') {
                                $serverName = SITE_SERVER_NAME;
                            } else {
                                $serverName = COption::GetOptionString('main', 'server_name', 'www.bitrixsoft.com');
                            }
                            $path2Post = 'http://'.$serverName.CComponentEngine::MakePathFromTemplate($arPath['PATH_TO_POST'], ['blog' => $arBlog['URL'], 'user_id' => $arBlog['OWNER_ID'], 'post_id' => $arPost['ID']]);
                        }

                        $result .= '
							<value>
								<struct>';
                        if ('' !== $category) {
                            $result .= '<member>
										<name>categories</name>
										<value>
										<array>
										<data>
										'.$category.'
										</data>
										</array>
										</value>
									</member>
								';
                        }
                        $result .= '
									<member>
										<name>dateCreated</name>
										<value>
										<dateTime.iso8601>'.$dateISO.'</dateTime.iso8601>
										</value>
									</member>
									<member>
										<name>description</name>
										<value>'.$text.'</value>
									</member>
									<member>
										<name>link</name>
										<value>'.htmlspecialcharsEx($path2Post).'</value>
									</member>
									<member>
										<name>postid</name>
										<value>
										<i4>'.$arPost['ID'].'</i4>
										</value>
									</member>
									<member>
										<name>title</name>
										<value>'.$title.'</value>
									</member>
									<member>
										<name>publish</name>
										<value>
										<boolean>'.(('D' === $arPost['PUBLISH_STATUS']) ? '0' : '1').'</boolean>
										</value>
									</member>
								</struct>
							</value>
							';
                    }
                }
            }

            return '<params>
						<param>
							<value>
								<array>
									<data>'
                                        .$result.
                                    '</data>
								</array>
							</value>
						</param>
					</params>';
        }

        return '<fault>
				<value>
					<struct>
						<member>
							<name>faultCode</name>
							<value><int>3</int></value>
							</member>
						<member>
							<name>faultString</name>
							<value><string>'.$arAuthResult['MESSAGE'].'</string></value>
							</member>
						</struct>
					</value>
				</fault>';
    }

    public static function NewMediaObject($params)
    {
        global $USER, $DB;
        $blogId = (int) CBlogMetaWeblog::DecodeParams($params[0]['#']['value'][0]['#']);
        $user = CBlogMetaWeblog::DecodeParams($params[1]['#']['value'][0]['#']);
        $password = CBlogMetaWeblog::DecodeParams($params[2]['#']['value'][0]['#']);
        $arImage = $params[3]['#']['value'][0]['#']['struct'][0]['#']['member'];

        foreach ($arImage as $val) {
            $arImageInfo[$val['#']['name'][0]['#']] = CBlogMetaWeblog::DecodeParams($val['#']['value'][0]['#']);
        }

        if (CBlogMetaWeblog::Authorize($user, $password)) {
            $result = '';
            $userId = $USER->GetID();

            if ((int) $blogId > 0) {
                $dbBlog = CBlog::GetList([], ['GROUP_SITE_ID' => SITE_ID, 'ACTIVE' => 'Y', 'ID' => $blogId], false, false, ['ID', 'URL', 'NAME']);
                if ($arBlog = $dbBlog->GetNext()) {
                    $filename = trim(str_replace('\\', '/', trim($arImageInfo['name'])), '/');
                    if ('' !== $filename) {
                        $TEMP_FILE_NAME = CTempFile::GetFileName(md5($filename).'.'.GetFileExtension($filename));
                        CheckDirPath($TEMP_FILE_NAME);
                    } else {
                        $TEMP_FILE_NAME = '';
                    }

                    if (('' !== $TEMP_FILE_NAME) && ($fp = fopen($TEMP_FILE_NAME, 'a'))) {
                        $result = fwrite($fp, $arImageInfo['bits']);
                        if ($result !== CUtil::BinStrlen($arImageInfo['bits'])) {
                            return '<fault>
									<value>
										<struct>
											<member>
												<name>faultCode</name>
												<value><int>3</int></value>
											</member>
											<member>
												<name>faultString</name>
												<value><string>Error on saving media object</string></value>
											</member>
										</struct>
									</value>
								</fault>';
                        }
                        fclose($fp);
                    } else {
                        return '<fault>
								<value>
									<struct>
										<member>
											<name>faultCode</name>
											<value><int>3</int></value>
										</member>
										<member>
											<name>faultString</name>
											<value><string>Error on saving media object</string></value>
										</member>
									</struct>
								</value>
							</fault>';
                    }

                    $arFields = [
                        'BLOG_ID' => $arBlog['ID'],
                        'USER_ID' => $userId,
                        '=TIMESTAMP_X' => $DB->GetNowFunction(),
                        'FILE_ID' => [
                            'name' => $arImageInfo['name'],
                            'tmp_name' => $TEMP_FILE_NAME,
                            'MODULE_ID' => 'blog',
                            'type' => $arImageInfo['type'],
                        ],
                    ];
                    $imageId = CBlogImage::Add($arFields);
                    $arImg = CBlogImage::GetByID($imageId);
                    $arFile = CFile::GetFileArray($arImg['FILE_ID']);
                    $path = $arFile['SRC'];

                    $dbSite = CSite::GetByID(SITE_ID);
                    $arSite = $dbSite->Fetch();
                    $serverName = htmlspecialcharsEx($arSite['SERVER_NAME']);
                    if ('' === $serverName) {
                        if (defined('SITE_SERVER_NAME') && SITE_SERVER_NAME !== '') {
                            $serverName = SITE_SERVER_NAME;
                        } else {
                            $serverName = COption::GetOptionString('main', 'server_name', 'www.bitrixsoft.com');
                        }
                    }

                    if ('' !== $path) {
                        return '<params>
							<param>
								<value>
									<struct>
									<member>
									<name>url</name>
									<value>
									<string>'.CHTTP::URN2URI($path, $serverName).'</string>
									</value>
									</member>
									</struct>
								</value>
							</param>
							</params>';
                    }
                }
            }

            return '<fault>
				<value>
					<struct>
						<member>
							<name>faultCode</name>
							<value><int>3</int></value>
						</member>
						<member>
							<name>faultString</name>
							<value><string>Error on saving media object</string></value>
						</member>
					</struct>
				</value>
				</fault>';
        }

        return '<fault>
				<value>
					<struct>
						<member>
							<name>faultCode</name>
							<value><int>3</int></value>
						</member>
						<member>
							<name>faultString</name>
							<value><string>'.$arAuthResult['MESSAGE'].'</string></value>
						</member>
					</struct>
				</value>
				</fault>';
    }

    public static function NewPost($params)
    {
        global $USER, $DB;
        $blogId = (int) CBlogMetaWeblog::DecodeParams($params[0]['#']['value'][0]['#']);
        $user = CBlogMetaWeblog::DecodeParams($params[1]['#']['value'][0]['#']);
        $password = CBlogMetaWeblog::DecodeParams($params[2]['#']['value'][0]['#']);
        $arPostInfo = $params[3]['#']['value'][0]['#']['struct'][0]['#']['member'];
        $publish = $params[4]['#']['value'][0]['#']['boolean'][0]['#'];

        foreach ($arPostInfo as $val) {
            ${$val['#']['name'][0]['#']} = CBlogMetaWeblog::DecodeParams($val['#']['value'][0]['#']);
        }

        $arCategory = [];
        if (is_array($categories['data'][0]['#']['value'])) {
            foreach ($categories['data'][0]['#']['value'] as $val) {
                $catTmp = CBlogMetaWeblog::DecodeParams($val['#']);
                if ('' !== $catTmp) {
                    $arCategory[] = $catTmp;
                }
            }
        }

        if (CBlogMetaWeblog::Authorize($user, $password)) {
            $result = '';
            $userId = $USER->GetID();

            if ((int) $blogId > 0) {
                $dbBlog = CBlog::GetList([], ['GROUP_SITE_ID' => SITE_ID, 'ACTIVE' => 'Y', 'ID' => $blogId], false, false, ['ID', 'URL', 'NAME', 'GROUP_ID', 'SOCNET_GROUP_ID']);
                if ($arBlog = $dbBlog->GetNext()) {
                    $CATEGORYtmp = [];
                    $dbCategory = CBlogCategory::GetList([], ['BLOG_ID' => $blogId]);
                    while ($arCat = $dbCategory->Fetch()) {
                        $arCatBlog[ToLower($arCat['NAME'])] = $arCat['ID'];
                    }

                    if ((int) $arBlog['SOCNET_GROUP_ID'] > 0 && CModule::IncludeModule('socialnetwork') && method_exists('CSocNetGroup', 'GetSite')) {
                        $arSites = [];
                        $rsGroupSite = CSocNetGroup::GetSite($arBlog['SOCNET_GROUP_ID']);
                        while ($arGroupSite = $rsGroupSite->Fetch()) {
                            $arSites[] = $arGroupSite['LID'];
                        }
                    } else {
                        $arSites = [SITE_ID];
                    }

                    foreach ($arCategory as $tg) {
                        $tg = trim($tg);
                        if (!in_array($arCatBlog[ToLower($tg)], $CATEGORYtmp, true)) {
                            if ((int) $arCatBlog[ToLower($tg)] > 0) {
                                $CATEGORYtmp[] = $arCatBlog[ToLower($tg)];
                            } else {
                                $CATEGORYtmp[] = CBlogCategory::Add(['BLOG_ID' => $blogId, 'NAME' => $tg]);

                                foreach ($arSites as $site_id_tmp) {
                                    BXClearCache(true, '/'.$site_id_tmp.'/blog/'.$arBlog['URL'].'/category/');
                                }
                            }
                        }
                    }

                    $dbSite = CSite::GetByID(SITE_ID);
                    $arSite = $dbSite->Fetch();
                    $serverName = htmlspecialcharsEx($arSite['SERVER_NAME']);
                    if ('' === $serverName) {
                        if (defined('SITE_SERVER_NAME') && SITE_SERVER_NAME !== '') {
                            $serverName = SITE_SERVER_NAME;
                        } else {
                            $serverName = COption::GetOptionString('main', 'server_name', 'www.bitrixsoft.com');
                        }
                    }

                    $arImgRepl = [];
                    $dbImage = CBlogImage::GetList([], ['POST_ID' => false, 'BLOG_ID' => $blogId, 'IS_COMMENT' => 'N']);
                    while ($arImage = $dbImage->Fetch()) {
                        $path = '';
                        $path = CFile::GetPath($arImage['FILE_ID']);
                        $path = 'http://'.$serverName.$path;

                        if (str_contains($description, $path)) {
                            $description = str_replace('<img src="'.$path.'" alt=""/>', '[IMG ID='.$arImage['ID'].']', $description);
                            $arImgRepl[] = $arImage['ID'];
                        }
                    }

                    $arFields = [
                        'BLOG_ID' => $blogId,
                        'AUTHOR_ID' => $userId,
                        'TITLE' => $title,
                        'DETAIL_TEXT' => $description,
                        'DETAIL_TEXT_TYPE' => 'html',
                        '=DATE_PUBLISH' => $DB->GetNowFunction(),
                        '=DATE_CREATE' => $DB->GetNowFunction(),
                        'PUBLISH_STATUS' => ((1 === $publish) ? 'P' : 'D'),
                        'ENABLE_TRACKBACK' => 'N',
                        'ENABLE_COMMENTS' => 'Y',
                        'CATEGORY_ID' => implode(',', $CATEGORYtmp),
                        'PERMS_POST' => [],
                        'PERMS_COMMENT' => [],
                    ];
                    $postId = CBlogPost::Add($arFields);
                    if ((int) $postId > 0) {
                        foreach ($CATEGORYtmp as $v) {
                            CBlogPostCategory::Add(['BLOG_ID' => $arBlog['ID'], 'POST_ID' => $postId, 'CATEGORY_ID' => $v]);
                        }
                        foreach ($arImgRepl as $v) {
                            CBlogImage::Update($v, ['POST_ID' => $postId]);
                        }

                        foreach ($arSites as $site_id_tmp) {
                            BXClearCache(true, '/'.$site_id_tmp.'/blog/'.$arBlog['URL'].'/first_page/');
                            BXClearCache(true, '/'.$site_id_tmp.'/blog/'.$arBlog['URL'].'/calendar/');
                            BXClearCache(true, '/'.$site_id_tmp.'/blog/last_messages/');
                            BXClearCache(true, '/'.$site_id_tmp.'/blog/commented_posts/');
                            BXClearCache(true, '/'.$site_id_tmp.'/blog/popular_posts/');
                            BXClearCache(true, '/'.$site_id_tmp.'/blog/last_comments/');
                            BXClearCache(true, '/'.$site_id_tmp.'/blog/groups/'.$arBlog['GROUP_ID'].'/');
                            BXClearCache(true, '/'.$site_id_tmp.'/blog/'.$arBlog['URL'].'/rss_out/');
                            BXClearCache(true, '/'.$site_id_tmp.'/blog/'.$arBlog['URL'].'/rss_all/');
                            BXClearCache(true, '/'.$site_id_tmp.'/blog/rss_sonet/');
                            BXClearCache(true, '/'.$site_id_tmp.'/blog/rss_all/');
                            BXClearCache(true, '/'.$site_id_tmp.'/blog/'.$arBlog['URL'].'/favorite/');
                        }

                        return '<params>
									<param>
										<value>
											<i4>'.$postId.'</i4>
										</value>
									</param>
								</params>';
                    }

                    return '<fault>
							<value>
								<struct>
									<member>
										<name>faultCode</name>
										<value><int>3</int></value>
										</member>
									<member>
										<name>faultString</name>
										<value><string>Error on adding post</string></value>
										</member>
									</struct>
								</value>
							</fault>';
                }
            }
        } else {
            return '<fault>
				<value>
					<struct>
						<member>
							<name>faultCode</name>
							<value><int>3</int></value>
							</member>
						<member>
							<name>faultString</name>
							<value><string>'.$arAuthResult['MESSAGE'].'</string></value>
							</member>
						</struct>
					</value>
				</fault>';
        }
    }

    public static function EditPost($params)
    {
        global $USER, $DB;
        $postId = (int) CBlogMetaWeblog::DecodeParams($params[0]['#']['value'][0]['#']);
        $user = CBlogMetaWeblog::DecodeParams($params[1]['#']['value'][0]['#']);
        $password = CBlogMetaWeblog::DecodeParams($params[2]['#']['value'][0]['#']);
        $arPostInfo = $params[3]['#']['value'][0]['#']['struct'][0]['#']['member'];
        $publish = $params[4]['#']['value'][0]['#']['boolean'][0]['#'];

        foreach ($arPostInfo as $val) {
            ${$val['#']['name'][0]['#']} = CBlogMetaWeblog::DecodeParams($val['#']['value'][0]['#']);
        }

        $arCategory = [];
        if (is_array($categories['data'][0]['#']['value'])) {
            foreach ($categories['data'][0]['#']['value'] as $val) {
                $catTmp = CBlogMetaWeblog::DecodeParams($val['#']);
                if ('' !== $catTmp) {
                    $arCategory[] = $catTmp;
                }
            }
        }

        if (CBlogMetaWeblog::Authorize($user, $password)) {
            $result = '';
            $userId = $USER->GetID();

            if ((int) $postId > 0) {
                $arSelectedFields = ['ID', 'BLOG_ID', 'TITLE', 'DATE_PUBLISH', 'AUTHOR_ID', 'DETAIL_TEXT', 'DETAIL_TEXT_TYPE'];
                $dbPost = CBlogPost::GetList([], ['AUTHOR_ID' => $userId, 'ID' => $postId], false, ['nTopCount' => 1], $arSelectedFields);
                if ($arPost = $dbPost->Fetch()) {
                    $arBlog = CBlog::GetByID($arPost['BLOG_ID']);

                    if ((int) $arBlog['SOCNET_GROUP_ID'] > 0 && CModule::IncludeModule('socialnetwork') && method_exists('CSocNetGroup', 'GetSite')) {
                        $arSites = [];
                        $rsGroupSite = CSocNetGroup::GetSite($arBlog['SOCNET_GROUP_ID']);
                        while ($arGroupSite = $rsGroupSite->Fetch()) {
                            $arSites[] = $arGroupSite['LID'];
                        }
                    } else {
                        $arSites = [SITE_ID];
                    }

                    $CATEGORYtmp = [];
                    $dbCategory = CBlogCategory::GetList([], ['BLOG_ID' => $arPost['BLOG_ID']]);
                    while ($arCat = $dbCategory->Fetch()) {
                        $arCatBlog[ToLower($arCat['NAME'])] = $arCat['ID'];
                    }

                    foreach ($arCategory as $tg) {
                        $tg = trim($tg);
                        if (!in_array($arCatBlog[ToLower($tg)], $CATEGORYtmp, true)) {
                            if ((int) $arCatBlog[ToLower($tg)] > 0) {
                                $CATEGORYtmp[] = $arCatBlog[ToLower($tg)];
                            } else {
                                $CATEGORYtmp[] = CBlogCategory::Add(['BLOG_ID' => $arPost['BLOG_ID'], 'NAME' => $tg]);

                                foreach ($arSites as $site_id_tmp) {
                                    BXClearCache(true, '/'.$site_id_tmp.'/blog/'.$arBlog['URL'].'/category/');
                                }
                            }
                        }
                    }

                    $dbSite = CSite::GetByID(SITE_ID);
                    $arSite = $dbSite->Fetch();
                    $serverName = htmlspecialcharsEx($arSite['SERVER_NAME']);
                    if ('' === $serverName) {
                        if (defined('SITE_SERVER_NAME') && SITE_SERVER_NAME !== '') {
                            $serverName = SITE_SERVER_NAME;
                        } else {
                            $serverName = COption::GetOptionString('main', 'server_name', 'www.bitrixsoft.com');
                        }
                    }

                    $dbImage = CBlogImage::GetList([], ['POST_ID' => false, 'BLOG_ID' => $arBlog['ID'], 'IS_COMMENT' => 'N']);
                    while ($arImage = $dbImage->Fetch()) {
                        $path = '';
                        $path = CFile::GetPath($arImage['FILE_ID']);
                        $path = 'http://'.$serverName.$path;

                        if (str_contains($description, $path)) {
                            $description = str_replace('<img src="'.$path.'" alt=""/>', '[IMG ID='.$arImage['ID'].']', $description);
                            CBlogImage::Update($arImage['ID'], ['POST_ID' => $arPost['ID']]);
                        }
                    }

                    $arFields = [
                        'TITLE' => $title,
                        'DETAIL_TEXT' => $description,
                        'DETAIL_TEXT_TYPE' => 'html',
                        'PUBLISH_STATUS' => ((1 === $publish) ? 'P' : 'D'),
                        'CATEGORY_ID' => implode(',', $CATEGORYtmp),
                    ];
                    $postId = CBlogPost::Update($arPost['ID'], $arFields);

                    CBlogPostCategory::DeleteByPostID($arPost['ID']);
                    foreach ($CATEGORYtmp as $v) {
                        CBlogPostCategory::Add(['BLOG_ID' => $arPost['BLOG_ID'], 'POST_ID' => $arPost['ID'], 'CATEGORY_ID' => $v]);
                    }
                    if ((int) $postId > 0) {
                        foreach ($arSites as $site_id_tmp) {
                            BXClearCache(true, '/'.$site_id_tmp.'/blog/'.$arBlog['URL'].'/first_page/');
                            BXClearCache(true, '/'.$site_id_tmp.'/blog/'.$arBlog['URL'].'/calendar/');
                            BXClearCache(true, '/'.$site_id_tmp.'/blog/last_messages/');
                            BXClearCache(true, '/'.$site_id_tmp.'/blog/commented_posts/');
                            BXClearCache(true, '/'.$site_id_tmp.'/blog/popular_posts/');
                            BXClearCache(true, '/'.$site_id_tmp.'/blog/last_comments/');
                            BXClearCache(true, '/'.$site_id_tmp.'/blog/groups/'.$arBlog['GROUP_ID'].'/');
                            BXClearCache(true, '/'.$site_id_tmp.'/blog/'.$arBlog['URL'].'/trackback/'.$arPost['ID'].'/');
                            BXClearCache(true, '/'.$site_id_tmp.'/blog/'.$arBlog['URL'].'/comment/'.$arPost['ID'].'/');
                            BXClearCache(true, '/'.$site_id_tmp.'/blog/'.$arBlog['URL'].'/rss_out/');
                            BXClearCache(true, '/'.$site_id_tmp.'/blog/'.$arBlog['URL'].'/rss_all/');
                            BXClearCache(true, '/'.$site_id_tmp.'/blog/rss_sonet/');
                            BXClearCache(true, '/'.$site_id_tmp.'/blog/rss_all/');
                            BXClearCache(true, '/'.$site_id_tmp.'/blog/'.$arBlog['URL'].'/favorite/');
                            BXClearCache(true, '/'.$site_id_tmp.'/blog/'.$arBlog['URL'].'/post/'.$arPost['ID'].'/');
                        }

                        return '<params>
									<param>
										<value>
											<i4>'.$postId.'</i4>
										</value>
									</param>
								</params>';
                    }

                    return '<fault>
							<value>
								<struct>
									<member>
										<name>faultCode</name>
										<value><int>3</int></value>
										</member>
									<member>
										<name>faultString</name>
										<value><string>Error on saving post</string></value>
										</member>
									</struct>
								</value>
							</fault>';
                }
            }
        } else {
            return '<fault>
				<value>
					<struct>
						<member>
							<name>faultCode</name>
							<value><int>3</int></value>
							</member>
						<member>
							<name>faultString</name>
							<value><string>'.$arAuthResult['MESSAGE'].'</string></value>
							</member>
						</struct>
					</value>
				</fault>';
        }
    }

    public static function GetPost($params, $arPath)
    {
        global $USER;
        $postId = (int) CBlogMetaWeblog::DecodeParams($params[0]['#']['value'][0]['#']);
        $user = CBlogMetaWeblog::DecodeParams($params[1]['#']['value'][0]['#']);
        $password = CBlogMetaWeblog::DecodeParams($params[2]['#']['value'][0]['#']);

        if (CBlogMetaWeblog::Authorize($user, $password)) {
            $result = '';
            $userId = $USER->GetID();

            if ((int) $postId > 0) {
                $arSelectedFields = ['ID', 'BLOG_ID', 'TITLE', 'DATE_PUBLISH', 'AUTHOR_ID', 'DETAIL_TEXT', 'DETAIL_TEXT_TYPE', 'BLOG_URL', 'BLOG_OWNER_ID'];
                $dbPost = CBlogPost::GetList([], ['AUTHOR_ID' => $userId, 'ID' => $postId], false, ['nTopCount' => 1], $arSelectedFields);
                if ($arPost = $dbPost->Fetch()) {
                    $parser = new blogTextParser();
                    $dateISO = date('Y-m-d\\TH:i:s', MakeTimeStamp($arPost['DATE_PUBLISH']));
                    $title = htmlspecialcharsEx($arPost['TITLE']);
                    $arImages = [];
                    $res = CBlogImage::GetList(['ID' => 'ASC'], ['POST_ID' => $arPost['ID'], 'BLOG_ID' => $arPost['BLOG_ID'], 'IS_COMMENT' => 'N']);
                    while ($arImage = $res->Fetch()) {
                        $arImages[$arImage['ID']] = $arImage['FILE_ID'];
                    }

                    if ('html' === $arPost['DETAIL_TEXT_TYPE']) {
                        $arAllow = ['HTML' => 'Y', 'ANCHOR' => 'Y', 'IMG' => 'Y', 'SMILES' => 'Y', 'NL2BR' => 'N', 'VIDEO' => 'N', 'QUOTE' => 'N', 'CODE' => 'N'];

                        $text = $parser->convert_to_rss($arPost['DETAIL_TEXT'], $arImages, $arAllow, false);
                    } else {
                        $arAllow = ['HTML' => 'N', 'ANCHOR' => 'Y', 'BIU' => 'Y', 'IMG' => 'Y', 'QUOTE' => 'N', 'CODE' => 'N', 'FONT' => 'Y', 'LIST' => 'Y', 'SMILES' => 'Y', 'NL2BR' => 'N', 'VIDEO' => 'N'];
                        $text = $parser->convert_to_rss(htmlspecialcharsEx($arPost['DETAIL_TEXT']), $arImages, $arAllow, false);
                    }
                    $text = '<![CDATA['.$text.']]>';

                    $category = '';
                    $dbCategory = CBlogPostCategory::GetList([], ['BLOG_ID' => $arPost['BLOG_ID'], 'POST_ID' => $arPost['ID']]);
                    while ($arCategory = $dbCategory->Fetch()) {
                        $category .= '<value>'.htmlspecialcharsEx($arCategory['NAME']).'</value>';
                    }

                    $path2Post = '';
                    if ('' !== $arPath['PATH_TO_POST']) {
                        if (defined('SITE_SERVER_NAME') && SITE_SERVER_NAME !== '') {
                            $serverName = SITE_SERVER_NAME;
                        } else {
                            $serverName = COption::GetOptionString('main', 'server_name', 'www.bitrixsoft.com');
                        }
                        $path2Post = 'http://'.$serverName.CComponentEngine::MakePathFromTemplate($arPath['PATH_TO_POST'], ['blog' => $arPost['BLOG_URL'], 'user_id' => $arPost['BLOG_OWNER_ID'], 'post_id' => $arPost['ID']]);
                    }

                    $result .= '
						<value>
							<struct>';
                    if ('' !== $category) {
                        $result .= '<member>
									<name>categories</name>
									<value>
									<array>
									<data>
									'.$category.'
									</data>
									</array>
									</value>
								</member>
							';
                    }
                    $result .= '
								<member>
									<name>dateCreated</name>
									<value>
									<dateTime.iso8601>'.$dateISO.'</dateTime.iso8601>
									</value>
								</member>
								<member>
									<name>description</name>
									<value>'.$text.'</value>
								</member>
								<member>
									<name>link</name>
									<value>'.htmlspecialcharsEx($path2Post).'</value>
								</member>
								<member>
									<name>postid</name>
									<value>
									<i4>'.$arPost['ID'].'</i4>
									</value>
								</member>
								<member>
									<name>title</name>
									<value>'.$title.'</value>
								</member>
								<member>
									<name>publish</name>
									<value>
									<boolean>'.(('D' === $arPost['PUBLISH_STATUS']) ? '0' : '1').'</boolean>
									</value>
								</member>
							</struct>
						</value>
						';
                }
            }

            return '<params>
						<param>
							<value>
								<array>
									<data>'
                                        .$result.
                                    '</data>
								</array>
							</value>
						</param>
					</params>';
        }

        return '<fault>
				<value>
					<struct>
						<member>
							<name>faultCode</name>
							<value><int>3</int></value>
							</member>
						<member>
							<name>faultString</name>
							<value><string>'.$arAuthResult['MESSAGE'].'</string></value>
							</member>
						</struct>
					</value>
				</fault>';
    }

    public static function DeletePost($params)
    {
        global $USER;
        $postId = (int) CBlogMetaWeblog::DecodeParams($params[1]['#']['value'][0]['#']);
        $user = CBlogMetaWeblog::DecodeParams($params[2]['#']['value'][0]['#']);
        $password = CBlogMetaWeblog::DecodeParams($params[3]['#']['value'][0]['#']);

        if (CBlogMetaWeblog::Authorize($user, $password)) {
            $result = '';
            $userId = $USER->GetID();

            if ((int) $postId > 0) {
                $dbPost = CBlogPost::GetList([], ['AUTHOR_ID' => $userId, 'ID' => $postId], false, ['nTopCount' => 1], ['ID', 'BLOG_ID', 'AUTHOR_ID']);
                if ($arPost = $dbPost->Fetch()) {
                    CBlogPost::Delete($postId);

                    $arBlog = CBlog::GetByID($arPost['BLOG_ID']);

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
                        BXClearCache(true, '/'.$site_id_tmp.'/blog/'.$arBlog['URL'].'/first_page/');
                        BXClearCache(true, '/'.$site_id_tmp.'/blog/'.$arBlog['URL'].'/pages/');
                        BXClearCache(true, '/'.$site_id_tmp.'/blog/'.$arBlog['URL'].'/calendar/');
                        BXClearCache(true, '/'.$site_id_tmp.'/blog/'.$arBlog['URL'].'/post/'.$postId.'/');
                        BXClearCache(true, '/'.$site_id_tmp.'/blog/last_messages/');
                        BXClearCache(true, '/'.$site_id_tmp.'/blog/commented_posts/');
                        BXClearCache(true, '/'.$site_id_tmp.'/blog/popular_posts/');
                        BXClearCache(true, '/'.$site_id_tmp.'/blog/last_comments/');
                        BXClearCache(true, '/'.$site_id_tmp.'/blog/groups/'.$arResult['BLOG']['GROUP_ID'].'/');
                        BXClearCache(true, '/'.$site_id_tmp.'/blog/'.$arBlog['URL'].'/trackback/'.$postId.'/');
                        BXClearCache(true, '/'.$site_id_tmp.'/blog/'.$arBlog['URL'].'/rss_out/');
                        BXClearCache(true, '/'.$site_id_tmp.'/blog/'.$arBlog['URL'].'/rss_all/');
                        BXClearCache(true, '/'.$site_id_tmp.'/blog/rss_sonet/');
                        BXClearCache(true, '/'.$site_id_tmp.'/blog/rss_all/');
                    }
                }
            }

            return '<params>
						<param>
							<value>
								<boolean>1</boolean>
							</value>
						</param>
					</params>';
        }

        return '<fault>
				<value>
					<struct>
						<member>
							<name>faultCode</name>
							<value><int>3</int></value>
							</member>
						<member>
							<name>faultString</name>
							<value><string>'.$arAuthResult['MESSAGE'].'</string></value>
							</member>
						</struct>
					</value>
				</fault>';
    }

    public static function GetUserInfo($params)
    {
        global $USER;
        $user = CBlogMetaWeblog::DecodeParams($params[1]['#']['value'][0]['#']);
        $password = CBlogMetaWeblog::DecodeParams($params[2]['#']['value'][0]['#']);

        if (CBlogMetaWeblog::Authorize($user, $password)) {
            $result = '';
            $userId = $USER->GetID();

            $dbUser = CUser::GetByID($userId);
            if ($arUser = $dbUser->Fetch()) {
                $BlogUser = CBlogUser::GetByID($userId, BLOG_BY_USER_ID);
                if ('' !== $BlogUser['ALIAS']) {
                    $nick = htmlspecialcharsEx($BlogUser['ALIAS']);
                } else {
                    $nick = htmlspecialcharsEx($arUser['LOGIN']);
                }
                $result .= '
						<value>
							<struct>
								<member>
									<name>nickname</name>
									<value>'.$nick.'</value>
								</member>
								<member>
									<name>email</name>
									<value>'.htmlspecialcharsEx($arUser['EMAIL']).'</value>
								</member>
								<member>
									<name>lastname</name>
									<value>'.htmlspecialcharsEx($arUser['LAST_NAME']).'</value>
								</member>
								<member>
									<name>firstname</name>
									<value>'.htmlspecialcharsEx($arUser['NAME']).'</value>
								</member>
							</struct>
						</value>
					';
            }

            if ('' !== $result) {
                return '<params>
							<param>
								<value>
									<array>
										<data>'
                                            .$result.
                                        '</data>
									</array>
								</value>
							</param>
						</params>';
            }

            return '<fault>
					<value>
						<struct>
							<member>
								<name>faultCode</name>
								<value><int>4</int></value>
							</member>
							<member>
								<name>faultString</name>
								<value><string>User not found.</string></value>
							</member>
							</struct>
						</value>
					</fault>';
        }

        return '<fault>
				<value>
					<struct>
						<member>
							<name>faultCode</name>
							<value><int>3</int></value>
							</member>
						<member>
							<name>faultString</name>
							<value><string>'.$arAuthResult['MESSAGE'].'</string></value>
							</member>
						</struct>
					</value>
				</fault>';
    }
}
