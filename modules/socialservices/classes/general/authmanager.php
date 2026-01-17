<?php

use Bitrix\Main\Application;
use Bitrix\Socialservices\OAuth\StateService;
use Bitrix\Socialservices\UserTable;
use Bitrix\Main\Type\Collection;

IncludeModuleLangFile(__FILE__);

//manager to operate with services
class CSocServAuthManager
{
	/** @var array  */
	protected static $arAuthServices = false;

	protected $userId = null;

	public function __construct($userId = null)
	{
		global $USER;

		if(!is_array(self::$arAuthServices))
		{
			AddEventHandler("socialservices", "OnAuthServicesBuildList", array("CSocServDescription", "GetDescription"));

			self::$arAuthServices = array();

			foreach(GetModuleEvents("socialservices", "OnAuthServicesBuildList", true) as $arEvent)
			{
				$res = ExecuteModuleEventEx($arEvent);
				if(is_array($res))
				{
					if(!is_array($res[0]))
					{
						$res = array($res);
					}
					foreach($res as $serv)
					{
						self::$arAuthServices[$serv["ID"]] = $serv;
					}
				}
			}
			//services depend on current site
			$suffix = CSocServAuth::OptionsSuffix();
			self::$arAuthServices = self::AppyUserSettings($suffix);
		}

		$this->userId = $userId;
		if($this->userId === null && is_object($USER))
		{
			$this->userId = $USER->GetID();
		}
	}

	protected static function AppyUserSettings($suffix)
	{
		$arAuthServices = self::$arAuthServices;

		//user settings: sorting, active
		$arServices = unserialize(COption::GetOptionString("socialservices", "auth_services".$suffix, ""), ["allowed_classes" => false]);
		if(is_array($arServices))
		{
			$i = 0;
			foreach($arServices as $serv=>$active)
			{
				if(isset($arAuthServices[$serv]))
				{
					$arAuthServices[$serv]["__sort"] = $i++;
					$arAuthServices[$serv]["__active"] = ($active == "Y");
				}
			}
			Collection::sortByColumn($arAuthServices, "__sort");
		}
		return $arAuthServices;
	}

	public function GetAuthServices($suffix)
	{
		//$suffix indicates site specific or common options
		return self::AppyUserSettings($suffix);
	}

	public static function listServicesBlockedByZone(string $zone): array
	{
		if (!$zone)
		{
			return [];
		}

		if (in_array($zone, ['ru', 'kz', 'by'], true))
		{
			return [];
		}

		return [
			CSocServMyMailRu::ID,
			'MailRuOpenID',
			'Livejournal',
			'Liveinternet',
			CSocServMailRu2::ID,
			CSocServVKontakte::ID,
			CSocServYandexAuth::ID,
			CSocServOdnoklassniki::ID,
		];
	}

	public function isActiveAuthService(string $code): bool
	{
		if (!isset(self::$arAuthServices[$code]))
		{
			return false;
		}

		$service = self::$arAuthServices[$code];
		if (
			isset($service["__active"])
			&& $service["__active"] === true
			&& empty($service["DISABLED"])
		)
		{
			$serviceObject = new $service["CLASS"];
			if (is_callable([$serviceObject, "CheckSettings"]))
			{
				if (!call_user_func_array([$serviceObject, "CheckSettings"], []))
				{
					return false;
				}
			}

			return true;
		}

		return false;
	}

	public function GetActiveAuthServices($arParams)
	{
		$aServ = array();
		// self::SetUniqueKey();

		foreach(self::$arAuthServices as $key=>$service)
		{
			$isDisabled = $service["DISABLED"] ?? null;
			if(isset($service["__active"]) && $service["__active"] === true && $isDisabled !== true)
			{
				$cl = new $service["CLASS"];
				if(is_callable(array($cl, "CheckSettings")))
					if(!call_user_func_array(array($cl, "CheckSettings"), array()))
						continue;

				if(is_callable(array($cl, "GetFormHtml")))
					$service["FORM_HTML"] = call_user_func_array(array($cl, "GetFormHtml"), array($arParams));

				if(is_callable(array($cl, "GetOnClickJs")))
					$service["ONCLICK"] = call_user_func_array(array($cl, "GetOnClickJs"), array($arParams));

				$aServ[$key] = $service;
			}
		}
		return $aServ;
	}

	public function GetProfileUrl($service, $uid, $arService = false)
	{
		if(isset(self::$arAuthServices[$service]))
		{
			if(!is_array($arService))
			{
				$dbSocservUser = UserTable::getList([
					'filter' => [
						'=USER_ID' => $this->userId,
						'=EXTERNAL_AUTH_ID' => $service,
					],
					'select' => ['ID']
				]);
				$arService = $dbSocservUser->fetch();
			}

			if(
				is_array($arService)
				&& self::$arAuthServices[$service]["__active"] === true
				&& self::$arAuthServices[$service]["DISABLED"] !== true
			)
			{
				/** @var CSocServFacebook $cl */
				$cl = new self::$arAuthServices[$service]["CLASS"];
				if(is_callable(array($cl, "getProfileUrl")))
				{
					return $cl->getProfileUrl($uid);
				}
			}
		}

		return false;
	}

	public function GetFriendsList($service, $limit, &$next)
	{
		if(isset(self::$arAuthServices[$service]))
		{
			$dbSocservUser = UserTable::getList([
				'filter' => [
					'=USER_ID' => $this->userId,
					'=EXTERNAL_AUTH_ID' => $service,
				],
				'select' => ['ID']
			]);
			$arService = $dbSocservUser->fetch();
			if(
				is_array($arService)
				&& self::$arAuthServices[$service]["__active"] === true
				&& self::$arAuthServices[$service]["DISABLED"] !== true
			)
			{
				/** @var CSocServFacebook $cl */
				$cl = new self::$arAuthServices[$service]["CLASS"];

				if(is_callable(array($cl, "setUser")))
				{
					$cl->setUser($this->userId);
				}

				if(is_callable(array($cl, "getFriendsList")))
				{
					$result = $cl->getFriendsList($limit, $next);

					if($next === "__finish__")
					{
						$next = null;
					}

					return $result;
				}
			}
		}

		return false;
	}

	public function GetSettings()
	{
		$arOptions = array();
		foreach(self::$arAuthServices as $service)
		{
			$serviceInstance = new $service["CLASS"]();
			if(is_callable(array($serviceInstance, "GetSettings")))
			{
				$arOptions[] = htmlspecialcharsbx($service["NAME"]);
				$options = call_user_func_array(array($serviceInstance, "GetSettings"), array());
				if(is_array($options))
					foreach($options as $opt)
						$arOptions[] = $opt;
			}
		}

		return $arOptions;
	}

	public function GetSettingByServiceId(string $serviceId): ?array
	{
		$settings = [];
		if (!isset(self::$arAuthServices[$serviceId]))
		{
			return null;
		}

		$service = self::$arAuthServices[$serviceId];
		$serviceInstance = new $service["CLASS"]();
		if (is_callable([$serviceInstance, "GetSettings"]))
		{
			$options = call_user_func_array([$serviceInstance, "GetSettings"], []);
			if (is_array($options))
			{
				foreach ($options as $opt)
				{
					$settings[] = $opt;
				}
			}
		}

		return $settings;
	}

	public function Authorize($service_id, $arParams = array())
	{
		if($service_id === 'Bitrix24OAuth')
		{
			CSocServBitrixOAuth::gadgetAuthorize();
		}

		if(isset(self::$arAuthServices[$service_id]))
		{
			$service = self::$arAuthServices[$service_id];

			$isDisabled = $service["DISABLED"] ?? null;
			if(
				(
					$service["__active"] === true
					&& $isDisabled !== true
				)
				|| (
					$service_id == CSocServBitrix24Net::ID
					&& defined('ADMIN_SECTION')
					&& ADMIN_SECTION
				)
			)
			{
				$cl = new $service["CLASS"];
				if(is_callable(array($cl, "Authorize")))
				{
					return call_user_func_array(array($cl, "Authorize"), array
						($arParams));
				}
			}
		}

		return false;
	}

	public function GetError($service_id, $error_code)
	{
		if(isset(self::$arAuthServices[$service_id]))
		{
			$service = self::$arAuthServices[$service_id];
			if(is_callable(array($service["CLASS"], "GetError")))
				return call_user_func_array(array($service["CLASS"], "GetError"), array($error_code));
			$error = ($error_code == 2) ? "socserv_error_new_user" : "socserv_controller_error";
			return GetMessage($error, array("#SERVICE_NAME#"=>$service["NAME"]));
		}
		return '';
	}

	public static function GetUniqueKey()
	{
		if(!isset($_SESSION["UNIQUE_KEY"]))
		{
			self::SetUniqueKey();
		}

		return $_SESSION["UNIQUE_KEY"];
	}

	public static function SetUniqueKey()
	{
		if(!isset($_SESSION["UNIQUE_KEY"]))
			$_SESSION["UNIQUE_KEY"] = md5(bitrix_sessid_get().uniqid(rand(), true));
	}

	public static function CheckUniqueKey($bUnset = true)
	{
		$arState = array();

		if(isset($_REQUEST["state"]))
		{
			$arState = StateService::getInstance()->getPayload((string)$_REQUEST['state']);
			if (is_array($arState))
			{
				if (!isset($_REQUEST['check_key']) && isset($arState['check_key']))
				{
					$_REQUEST['check_key'] = $arState['check_key'];
				}

				if (isset($arState['backurl']))
				{
					InitURLParam($arState['backurl']);
				}

				if (isset($arState['redirect_url']))
				{
					InitURLParam($arState['redirect_url']);
				}
			}
			else
			{
				parse_str($_REQUEST["state"], $arState);

				if (isset($arState['backurl']))
				{
					InitURLParam($arState['backurl']);
				}
			}
		}

		if(!isset($_REQUEST['check_key']) && isset($_REQUEST['backurl']))
		{
			InitURLParam($_REQUEST['backurl']);
		}

		$checkKey = '';
		if(isset($_REQUEST['check_key']))
		{
			$checkKey = $_REQUEST['check_key'];
		}
		elseif(isset($arState['check_key']))
		{
			$checkKey = $arState['check_key'];
		}

		if(!empty($_SESSION["UNIQUE_KEY"]) && $checkKey && ($checkKey === $_SESSION["UNIQUE_KEY"]))
		{
			if($bUnset)
			{
				unset($_SESSION["UNIQUE_KEY"]);
			}

			return true;
		}
		return false;
	}

	public static function SetAuthorizedServiceId($service_id)
	{
		$session = Application::getInstance()->getKernelSession();
		$session["AUTH_SERVICE_ID"] = $service_id;
	}

	public static function UnsetAuthorizedServiceId()
	{
		$session = Application::getInstance()->getKernelSession();
		unset($session["AUTH_SERVICE_ID"]);
	}

	public static function GetAuthorizedServiceId()
	{
		$session = Application::getInstance()->getKernelSession();
		return $session["AUTH_SERVICE_ID"];
	}

	function CleanParam()
	{
		global $APPLICATION;

		$redirect_url = $APPLICATION->GetCurPageParam('', array("auth_service_id", "check_key"), false);
		LocalRedirect($redirect_url);
	}

	public static function GetUserArrayForSendMessages($userId)
	{
		$arUserOauth = array();
		$userId = intval($userId);
		if($userId > 0)
		{
			$dbSocservUser = UserTable::getList([
				'filter' => [
					'=USER_ID' => $userId
				],
				'select' => ["ID", "EXTERNAL_AUTH_ID", "OATOKEN"]
			]);
			while($arOauth = $dbSocservUser->fetch())
			{
				if($arOauth["OATOKEN"] <> '' && ($arOauth["EXTERNAL_AUTH_ID"] == "Twitter" || $arOauth["EXTERNAL_AUTH_ID"] == "Facebook"))
					$arUserOauth[$arOauth["ID"]] = $arOauth["EXTERNAL_AUTH_ID"];
			}
		}
		if(!empty($arUserOauth))
			return $arUserOauth;
		return false;
	}

	public static function SendUserMessage($socServUserId, $providerName, $message, $messageId)
	{
		$result = false;
		$socServUserId = intval($socServUserId);
		if($providerName != '' && $socServUserId > 0)
		{
			switch($providerName)
			{
				case 'Twitter':
					$className = "CSocServTwitter";
					break;
				case 'Facebook':
					$className = "CSocServFacebook";
					break;
				case 'Odnoklassniki':
					$className = "CSocServOdnoklassniki";
					break;
				default:
					$className = "";
			}
			if($className != "")
				$result = call_user_func($className.'::SendUserFeed', $socServUserId, $message, $messageId);
		}
		return $result;
	}

	/**
	 * Publishes messages from Twitter in Buzz corporate portal.
	 * @static
	 * @param $arUserTwit
	 * @param $lastTwitId
	 * @param $arSiteId
	 * @return int|null
	 */
	public static function PostIntoBuzz($arUserTwit, $lastTwitId, $arSiteId=array())
	{
		if(isset($arUserTwit['statuses']) && !empty($arUserTwit['statuses']))
		{
			foreach($arUserTwit['statuses'] as $userTwit)
			{
				if(isset($userTwit["id_str"]))
					$lastTwitId = ($userTwit["id_str"].'/' > $lastTwitId.'/') ? $userTwit["id_str"] : $lastTwitId;
				if(IsModuleInstalled('bitrix24') && defined('BX24_HOST_NAME'))
				{
					$userId = $userTwit['kp_user_id'];
					$rsUser = CUser::GetByID($userId);
					$arUser = $rsUser->Fetch();
					foreach(GetModuleEvents("socialservices", "OnPublishSocServMessage", true) as $arEvent)
						ExecuteModuleEventEx($arEvent, array($arUser, $userTwit, $arSiteId));
				}
				else
					self::PostIntoBuzzAsBlog($userTwit, $lastTwitId, $arSiteId);
			}
			return $lastTwitId;
		}
		return null;
	}

	public static function PostIntoBuzzAsBlog($userTwit, $arSiteId=array(), $userLogin = '')
	{
		global $DB;
		if(!CModule::IncludeModule("blog") || !CModule::IncludeModule("socialnetwork"))
			return;
		$arParams = array();
		if((IsModuleInstalled('bitrix24') && defined('BX24_HOST_NAME')) && $userLogin != '')
		{
			if($arUserTwit = unserialize(base64_decode($userTwit), ["allowed_classes" => false]))
				$userTwit = $arUserTwit;
			if($arSiteIdCheck = unserialize(base64_decode($arSiteId), ["allowed_classes" => false]))
				$arSiteId = $arSiteIdCheck;
			$dbUser = CUser::GetByLogin($userLogin);
			if($arUser = $dbUser->Fetch())
				$arParams["USER_ID"] = $arUser["ID"];
		}
		else
			$arParams["USER_ID"] = $userTwit['kp_user_id'];
		$siteId = null;
		if(isset($arSiteId[$userTwit['kp_user_id']]))
			$siteId = $arSiteId[$userTwit['kp_user_id']];
		if($siteId == '')
			$siteId = SITE_ID;
		if(isset($userTwit['text']))
		{
			$arParams["GROUP_ID"] = COption::GetOptionString("socialnetwork", "userbloggroup_id", false, $siteId);
			$arParams["PATH_TO_BLOG"] = COption::GetOptionString("socialnetwork", "userblogpost_page", false, $siteId);
			$arParams["PATH_TO_SMILE"] = COption::GetOptionString("socialnetwork", "smile_page", false, $siteId);
			$arParams["NAME_TEMPLATE"] = COption::GetOptionString("main", "TOOLTIP_NAME_TEMPLATE", false, $siteId);
			$arParams["SHOW_LOGIN"] = 'Y';
			$arParams["PATH_TO_POST"] = $arParams["PATH_TO_BLOG"];

			$arFilterblg = Array(
				"ACTIVE" => "Y",
				"USE_SOCNET" => "Y",
				"GROUP_ID" => $arParams["GROUP_ID"],
				"GROUP_SITE_ID" => $siteId,
				"OWNER_ID" => $arParams["USER_ID"],
			);
			$groupId = (is_array($arParams["GROUP_ID"]) ? intval($arParams["GROUP_ID"][0]) : intval($arParams["GROUP_ID"]));
			if (isset($GLOBALS["BLOG_POST"]["BLOG_P_".$groupId."_".$arParams["USER_ID"]]) && !empty($GLOBALS["BLOG_POST"]["BLOG_P_".$groupId."_".$arParams["USER_ID"]]))
			{
				$arBlog = $GLOBALS["BLOG_POST"]["BLOG_P_".$groupId."_".$arParams["USER_ID"]];
			}
			else
			{
				$dbBl = CBlog::GetList(Array(), $arFilterblg);
				$arBlog = $dbBl ->Fetch();
				if (!$arBlog && IsModuleInstalled("intranet"))
					$arBlog = CBlog::GetByOwnerID($arParams["USER_ID"]);

				$GLOBALS["BLOG_POST"]["BLOG_P_".$groupId."_".$arParams["USER_ID"]] = $arBlog;
			}

			$arResult["Blog"] = $arBlog;

			if(empty($arBlog))
			{
				if(!empty($arParams["GROUP_ID"]))
				{
					$arFields = array(
						"=DATE_UPDATE" => $DB->CurrentTimeFunction(),
						"GROUP_ID" => (is_array($arParams["GROUP_ID"])) ? intval($arParams["GROUP_ID"][0]) : intval($arParams["GROUP_ID"]),
						"ACTIVE" => "Y",
						"ENABLE_COMMENTS" => "Y",
						"ENABLE_IMG_VERIF" => "Y",
						"EMAIL_NOTIFY" => "Y",
						"ENABLE_RSS" => "Y",
						"ALLOW_HTML" => "N",
						"ENABLE_TRACKBACK" => "N",
						"SEARCH_INDEX" => "Y",
						"USE_SOCNET" => "Y",
						"=DATE_CREATE" => $DB->CurrentTimeFunction(),
						"PERMS_POST" => Array(
							1 => "I",
							2 => "I" ),
						"PERMS_COMMENT" => Array(
							1 => "P",
							2 => "P" ),
					);

					$bRights = false;
					$rsUser = CUser::GetByID($arParams["USER_ID"]);
					$arUser = $rsUser->Fetch();
					if($arUser["NAME"]."".$arUser["LAST_NAME"] == '')
						$arFields["NAME"] = GetMessage("BLG_NAME")." ".$arUser["LOGIN"];
					else
						$arFields["NAME"] = GetMessage("BLG_NAME")." ".$arUser["NAME"]." ".$arUser["LAST_NAME"];

					$arFields["URL"] = str_replace(" ", "_", $arUser["LOGIN"])."-blog-".SITE_ID;
					$arFields["OWNER_ID"] = $arParams["USER_ID"];

					$urlCheck = preg_replace("/[^a-zA-Z0-9_-]/is", "", $arFields["URL"]);
					if ($urlCheck != $arFields["URL"])
					{
						$arFields["URL"] = "u".$arParams["USER_ID"]."-blog-".SITE_ID;
					}
					if(CBlog::GetByUrl($arFields["URL"]))
					{
						$uind = 0;
						do
						{
							$uind++;
							$arFields["URL"] = $arFields["URL"].$uind;
						}
						while (CBlog::GetByUrl($arFields["URL"]));
					}

					$featureOperationPerms = CSocNetFeaturesPerms::GetOperationPerm(SONET_ENTITY_USER, $arFields["OWNER_ID"], "blog", "view_post");
					if ($featureOperationPerms == SONET_RELATIONS_TYPE_ALL)
						$bRights = true;

					$arFields["PATH"] = CComponentEngine::MakePathFromTemplate($arParams["PATH_TO_BLOG"], array("blog" => $arFields["URL"], "user_id" => $arFields["OWNER_ID"], "group_id" => $arFields["SOCNET_GROUP_ID"]));

					$blogID = CBlog::Add($arFields);
					if($bRights)
						CBlog::AddSocnetRead($blogID);
					$arBlog = CBlog::GetByID($blogID, $arParams["GROUP_ID"]);
				}
			}

			//	$DATE_PUBLISH = "";
			//	if(strlen($_POST["DATE_PUBLISH_DEF"]) > 0)
			//		$DATE_PUBLISH = $_POST["DATE_PUBLISH_DEF"];
			//	elseif (strlen($_POST["DATE_PUBLISH"])<=0)

			$DATE_PUBLISH = ConvertTimeStamp(time() + CTimeZone::GetOffset(), "FULL");

			//	else
			//		$DATE_PUBLISH = $_POST["DATE_PUBLISH"];

			$arFields=array(
				"DETAIL_TEXT"       => $userTwit['text'],
				"DETAIL_TEXT_TYPE"	=> "text",
				"DATE_PUBLISH"		=> $DATE_PUBLISH,
				"PUBLISH_STATUS"	=> BLOG_PUBLISH_STATUS_PUBLISH,
				"PATH" 				=> CComponentEngine::MakePathFromTemplate(htmlspecialcharsBack($arParams["PATH_TO_POST"]), array("post_id" => "#post_id#", "user_id" => $arBlog["OWNER_ID"])),
				"URL" 				=> $arBlog["URL"],
				"SOURCE_TYPE"       => "twitter",
			);

			$arFields["PERMS_POST"] = array();
			$arFields["PERMS_COMMENT"] = array();
			$arFields["MICRO"] = "N";
			if($arFields["TITLE"] == '')
			{
				$arFields["MICRO"] = "Y";
				$arFields["TITLE"] = trim(blogTextParser::killAllTags($arFields["DETAIL_TEXT"]));
				if($arFields["TITLE"] == '')
					$arFields["TITLE"] = GetMessage("BLOG_EMPTY_TITLE_PLACEHOLDER");
			}

			$arFields["SOCNET_RIGHTS"] = Array();
			if(!empty($userTwit['user_perms']))
			{
				$bOne = true;
				foreach($userTwit['user_perms'] as $v => $k)
				{
					if($v <> '' && is_array($k) && !empty($k))
					{
						foreach($k as $vv)
						{
							if($vv <> '')
							{
								$arFields["SOCNET_RIGHTS"][] = $vv;
								if($v != "SG")
									$bOne = false;

							}
						}
					}
				}

				if($bOne && !empty($userTwit['user_perms']["SG"]))
				{
					$bOnesg = false;
					$bFirst = true;
					$oGrId = 0;
					foreach($userTwit['user_perms']["SG"] as $v)
					{
						if($v <> '')
						{
							if($bFirst)
							{
								$bOnesg = true;
								$bFirst = false;
								$v = str_replace("SG", "", $v);
								$oGrId = intval($v);
							}
							else
							{
								$bOnesg = false;
							}
						}
					}
					if($bOnesg)
					{
						if (!CSocNetFeaturesPerms::CanPerformOperation($arParams["USER_ID"], SONET_ENTITY_GROUP, $oGrId, "blog", "write_post") && !CSocNetFeaturesPerms::CanPerformOperation($arParams["USER_ID"], SONET_ENTITY_GROUP, $oGrId, "blog", "moderate_post") && !CSocNetFeaturesPerms::CanPerformOperation($arParams["USER_ID"], SONET_ENTITY_GROUP, $oGrId, "blog", "full_post"))
							$arFields["PUBLISH_STATUS"] = BLOG_PUBLISH_STATUS_READY;
					}
				}
			}
			$bError = false;
			/*	if (CModule::IncludeModule('extranet') && !CExtranet::IsIntranetUser())
				{
					if(empty($arFields["SOCNET_RIGHTS"]) || in_array("UA", $arFields["SOCNET_RIGHTS"]))
					{
						$bError = true;
						$arResult["ERROR_MESSAGE"] = GetMessage("BLOG_BPE_EXTRANET_ERROR");
					}
				}*/

			$newID = null;
			$socnetRightsOld = Array("U" => Array());
			if(!$bError)
			{
				preg_match_all("/\\[user\\s*=\\s*([^\\]]*)\\](.+?)\\[\\/user\\]/iesu", $userTwit['text'], $arMention);

				$arFields["=DATE_CREATE"] = $DB->GetNowFunction();
				$arFields["AUTHOR_ID"] = $arParams["USER_ID"];
				$arFields["BLOG_ID"] = $arBlog["ID"];

				$newID = CBlogPost::Add($arFields);

				if($newID)
				{
					$arFields["ID"] = $newID;
					$arParamsNotify = Array(
						"bSoNet" => true,
						"UserID" => $arParams["USER_ID"],
						"allowVideo" => $arResult["allowVideo"],
						//"bGroupMode" => $arResult["bGroupMode"],
						"PATH_TO_SMILE" => $arParams["PATH_TO_SMILE"],
						"PATH_TO_POST" => $arParams["PATH_TO_POST"],
						"SOCNET_GROUP_ID" => $arParams["GROUP_ID"],
						"user_id" => $arParams["USER_ID"],
						"NAME_TEMPLATE" => $arParams["NAME_TEMPLATE"],
						"SHOW_LOGIN" => $arParams["SHOW_LOGIN"],
					);
					CBlogPost::Notify($arFields, $arBlog, $arParamsNotify);
				}
			}
			if ($newID > 0 && $arResult["ERROR_MESSAGE"] == '' && $arFields["PUBLISH_STATUS"] == BLOG_PUBLISH_STATUS_PUBLISH) // Record saved successfully
			{
				BXClearCache(true, "/".SITE_ID."/blog/last_messages_list/");

				$arFieldsIM = Array(
					"TYPE" => "POST",
					"TITLE" => $arFields["TITLE"],
					"URL" => CComponentEngine::MakePathFromTemplate(htmlspecialcharsBack($arParams["PATH_TO_POST"]), array("post_id" => $newID, "user_id" => $arBlog["OWNER_ID"])),
					"ID" => $newID,
					"FROM_USER_ID" => $arParams["USER_ID"],
					"TO_USER_ID" => array(),
					"TO_SOCNET_RIGHTS" => $arFields["SOCNET_RIGHTS"],
					"TO_SOCNET_RIGHTS_OLD" => $socnetRightsOld["U"],
				);
				if(!empty($arMentionOld))
					$arFieldsIM["MENTION_ID_OLD"] = $arMentionOld[1];
				if(!empty($arMention))
					$arFieldsIM["MENTION_ID"] = $arMention[1];

				CBlogPost::NotifyIm($arFieldsIM);

				$arParams["ID"] = $newID;
				if(!empty($_POST["SPERM"]["SG"]))
				{
					foreach($_POST["SPERM"]["SG"] as $v)
					{
						$group_id_tmp = mb_substr($v, 2);
						if(intval($group_id_tmp) > 0)
							CSocNetGroup::SetLastActivity(intval($group_id_tmp));
					}
				}
			}
		}
	}

	public static function GetTwitMessages($lastTwitId = "1", $counter = 1)
	{
		$oAuthManager = new CSocServAuthManager();
		if(!$oAuthManager->isActiveAuthService('Twitter') || !function_exists("hash_hmac"))
			return false;
		if(!CModule::IncludeModule("socialnetwork"))
			return "CSocServAuthManager::GetTwitMessages(\"$lastTwitId\", $counter);";
		global $USER;
		$bTmpUserCreated = false;
		if(!isset($USER) || !(($USER instanceof CUser) && ('CUser' == get_class($USER))))
		{
			$bTmpUserCreated = true;
			if(isset($USER))
			{
				$USER_TMP = $USER;
				unset($USER);
			}

			$USER = new CUser();
		}
		if(intval($lastTwitId) <= 1 || $counter == 1)
			$lastTwitId = COption::GetOptionString('socialservices', 'last_twit_id', '1');
		$socServUserArray = self::GetUserArray('Twitter');
		$arSiteId = array();
		if(isset($socServUserArray[3]) && is_array($socServUserArray[3]))
			$arSiteId = $socServUserArray[3];
		$twitManager = new CSocServTwitter();
		$arUserTwit = $twitManager->GetUserMessage($socServUserArray, $lastTwitId);
		if(is_array($arUserTwit))
		{
			if(isset($arUserTwit["statuses"]) && !empty($arUserTwit["statuses"]))
				$lastTwitId = self::PostIntoBuzz($arUserTwit, $lastTwitId, $arSiteId);
			elseif((is_array($arUserTwit["search_metadata"]) && isset($arUserTwit["search_metadata"]["max_id_str"])) &&	($arUserTwit["search_metadata"]["max_id_str"] <> ''))
				$lastTwitId = $arUserTwit["search_metadata"]["max_id_str"];
		}
		$counter++;
		if($counter >= 20)
		{
			// $oldLastId = COption::GetOptionString('socialservices', 'last_twit_id', '1');
			// if((strlen($lastTwitId) > strlen($oldLastId)) && $oldLastId[0] != 9)
			// 	$lastTwitId = substr($lastTwitId, 1);
			COption::SetOptionString('socialservices', 'last_twit_id', $lastTwitId);
			$counter = 1;
		}
		$lastTwitId = preg_replace("|\D|", '', $lastTwitId);
		if($bTmpUserCreated)
		{
			unset($USER);
			if(isset($USER_TMP))
			{
				$USER = $USER_TMP;
				unset($USER_TMP);
			}
		}
		return "CSocServAuthManager::GetTwitMessages(\"$lastTwitId\", $counter);";
	}

	public static function SendSocialservicesMessages()
	{
		$oAuthManager = new CSocServAuthManager();
		if(!$oAuthManager->isActiveAuthService('Twitter') || !function_exists("hash_hmac"))
			return false;

		$ttl = 86400;
		$cache_id = 'socserv_mes_user';
		$obCache = new CPHPCache;
		$cache_dir = '/bx/socserv_mes_user';

		$arSocServMessage = array();
		if($obCache->InitCache($ttl, $cache_id, $cache_dir))
			$arSocServMessage = $obCache->GetVars();
		else
		{
			$dbSocServMessage = CSocServMessage::GetList(array(), array('SUCCES_SENT' => 'N'), false, array("nTopCount" => 5), array("ID", "SOCSERV_USER_ID", "PROVIDER", "MESSAGE"));

			while($arSocMessage = $dbSocServMessage->Fetch())
				$arSocServMessage[] = $arSocMessage;
			if(empty($arSocServMessage))
				if($obCache->StartDataCache())
					$obCache->EndDataCache($arSocServMessage);
		}
		if(is_array($arSocServMessage) && !empty($arSocServMessage))
			foreach($arSocServMessage as $arSocMessage)
			{
				$arResult = CSocServAuthManager::SendUserMessage($arSocMessage['SOCSERV_USER_ID'], $arSocMessage['PROVIDER'], $arSocMessage['MESSAGE'], $arSocMessage['ID']);
				if($arResult !== false && is_array($arResult) && !preg_match("/error/i", join(",", array_keys($arResult))))
					self::MarkMessageAsSent($arSocMessage['ID']);
			}
		return "CSocServAuthManager::SendSocialservicesMessages();";
	}

	private static function MarkMessageAsSent($id)
	{
		CSocServMessage::Update($id, array("SUCCES_SENT" => 'Y'));
	}

	public static function GetUserArray($authId)
	{
		$ttl = 10000;
		$cache_id = 'socserv_ar_user';
		$obCache = new CPHPCache;
		$cache_dir = '/bx/socserv_ar_user';

		if($obCache->InitCache($ttl, $cache_id, $cache_dir))
		{
			$arResult = $obCache->GetVars();
		}
		else
		{
			$arUserXmlId = $arOaToken = $arOaSecret = $arSiteId = array();
			$dbSocUser = UserTable::getList([
				'filter' => [
					'=EXTERNAL_AUTH_ID' => $authId,
					"=USER.ACTIVE" => 'Y'
				],
				'select' => ["XML_ID", "USER_ID", "OATOKEN", "OASECRET", "SITE_ID"]
			]);

			while($arSocUser = $dbSocUser->fetch())
			{
				$arUserXmlId[$arSocUser["USER_ID"]] = $arSocUser["XML_ID"];
				$arOaToken[$arSocUser["USER_ID"]] = $arSocUser["OATOKEN"];
				$arOaSecret[$arSocUser["USER_ID"]] = $arSocUser["OASECRET"];
				$arSiteId[$arSocUser["USER_ID"]] = $arSocUser["SITE_ID"];
			}
			$arResult = array($arUserXmlId, $arOaToken, $arOaSecret, $arSiteId);
			if($obCache->StartDataCache())
				$obCache->EndDataCache($arResult);
		}
		return $arResult;
	}

	public static function GetCachedUserOption($option)
	{
		global $USER;
		$result = '';
		if(is_object($USER))
		{
			$userId = $USER->GetID();
			$ttl = 10000;
			$cache_id = 'socserv_user_option_'.$userId;
			$obCache = new CPHPCache;
			$cache_dir = '/bx/socserv_user_option';

			if($obCache->InitCache($ttl, $cache_id, $cache_dir))
				$result = $obCache->GetVars();
			else
			{
				$result = CUtil::JSEscape(CUserOptions::GetOption("socialservices", $option, "N", $USER->GetID()));
				if($obCache->StartDataCache())
					$obCache->EndDataCache($result);
			}

		}

		return $result;
	}

	public static function checkOldUser(&$socservUserFields)
	{
		// check for user with old socialservices linking system (socservice ID in user's EXTERNAL_AUTH_ID)
		$dbUsersOld = CUser::GetList('ID', 'ASC', array('XML_ID' => $socservUserFields['XML_ID'], 'EXTERNAL_AUTH_ID' => $socservUserFields['EXTERNAL_AUTH_ID'], 'ACTIVE' => 'Y'), array('NAV_PARAMS' => array("nTopCount" => "1")));
		$socservUser = $dbUsersOld->Fetch();
		if($socservUser)
		{
			return $socservUser["ID"];
		}

		return false;
	}

	public static function checkAbandonedUser(&$socservUserFields)
	{
		// theoretically possible situation with abandoned external user w/o b_socialservices_user entry
		$dbUsersNew = CUser::GetList('ID', 'ASC', array('XML_ID' => $socservUserFields['XML_ID'], 'EXTERNAL_AUTH_ID' => 'socservices', 'ACTIVE' => 'Y'), array('NAV_PARAMS' => array("nTopCount" => "1")));
		$socservUser = $dbUsersNew->Fetch();

		if($socservUser)
		{
			return $socservUser["ID"];
		}

		return false;
	}
}
