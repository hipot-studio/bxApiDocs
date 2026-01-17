<?php

use Bitrix\Main\EventManager;
use Bitrix\Main\Config\Option;
use Bitrix\Main\HttpResponse;
use Bitrix\Main\Application;
use Bitrix\Main\Authentication;
use Bitrix\Socialservices\UserTable;
use Bitrix\Socialservices\EncryptedToken\CryptoField;

//base class for auth services
class CSocServAuth
{
	protected static $settingsSuffix = false;

	protected $checkRestrictions = true;
	protected $allowChangeOwner = true;

	protected $userId = null;

	public const OPENER_MODE = 'opener';
	public const MOBILE_MODE = 'bx_mobile';

	function __construct($userId = null)
	{
		global $USER;

		if($userId === null)
		{
			if(is_object($USER) && $USER->IsAuthorized())
			{
				$this->userId = $USER->GetID();
			}
		}
		else
		{
			$this->userId = $userId;
		}
	}

	public static function getControllerUrl()
	{
		return 'https://www.bitrix24.com/controller';

		// this may be needed later
/*
		static $controllerUrl = '';
		if(
			$controllerUrl === ''
			&& \Bitrix\Main\Loader::includeModule('bitrix24')
		)
		{
			$controllerUrl = 'https://www.bitrix24.com/controller';
			$controllerUrlList = array(
				'de' => 'https://www.bitrix24.de/controller',
				'ua' => 'https://www.bitrix24.ua/controller',
				'ru' => 'https://www.bitrix24.ru/controller',
				'eu' => 'https://www.bitrix24.eu/controller',
				'la' => 'https://www.bitrix24.es/controller',
				'br' => 'https://www.bitrix24.com.br/controller',
				'in' => 'https://www.bitrix24.in/controller',
				'cn' => 'https://www.bitrix24.cn/controller',
				'kz' => 'https://www.bitrix24.kz/controller',
				'by' => 'https://www.bitrix24.by/controller',
				'fr' => 'https://www.bitrix24.fr/controller',
				'pl' => 'https://www.bitrix24.pl/controller',
			);

			$lang = \CBitrix24::getLicensePrefix();
			if(array_key_exists($lang, $controllerUrlList))
			{
				$controllerUrl = $controllerUrlList[$lang];
			}
		}

		return $controllerUrl;
*/
	}

	public function GetSettings()
	{
		return false;
	}

	protected static function CheckFields($action, &$arFields)
	{
		global $USER;

		if($action === 'ADD')
		{
			if(isset($arFields["EXTERNAL_AUTH_ID"]) && $arFields["EXTERNAL_AUTH_ID"] == '')
			{
				return false;
			}

			if(isset($arFields["SITE_ID"]) && $arFields["SITE_ID"] == '')
			{
				$arFields["SITE_ID"] = SITE_ID;
			}

			if(!isset($arFields["USER_ID"]))
			{
				$arFields["USER_ID"] = $USER->GetID();
			}

			$dbCheck = UserTable::getList([
				'filter' => [
					'=USER_ID' => $arFields["USER_ID"],
					'=EXTERNAL_AUTH_ID' => $arFields["EXTERNAL_AUTH_ID"],
				],
				'select' => ["ID"]
			]);
			if($dbCheck->fetch())
			{
				return false;
			}
		}

		if(is_set($arFields, "PERSONAL_PHOTO"))
		{
			$res = CFile::CheckImageFile($arFields["PERSONAL_PHOTO"]);
			if($res <> '')
			{
				unset($arFields["PERSONAL_PHOTO"]);
			}
			else
			{
				$arFields["PERSONAL_PHOTO"]["MODULE_ID"] = "socialservices";
				CFile::SaveForDB($arFields, "PERSONAL_PHOTO", "socialservices");
			}
		}

		return true;
	}

	public static function Update($id, $arFields)
	{
		global $DB;
		$id = intval($id);

		if($id <= 0)
		{
			return false;
		}

		foreach(GetModuleEvents("socialservices", "OnBeforeSocServUserUpdate", true) as $arEvent)
		{
			if(ExecuteModuleEventEx($arEvent, array($id, &$arFields)) === false)
			{
				return false;
			}
		}

		if (is_set($arFields, "PERSONAL_PHOTO"))
		{
			if ($arFields["PERSONAL_PHOTO"]["name"] == '' && $arFields["PERSONAL_PHOTO"]["del"] == '')
			{
				unset($arFields["PERSONAL_PHOTO"]);
			}
			else
			{
				$rsPersonalPhoto = $DB->Query("SELECT PERSONAL_PHOTO FROM b_socialservices_user WHERE ID=".$id);
				if ($personalPhoto = $rsPersonalPhoto->Fetch())
				{
					$arFields["PERSONAL_PHOTO"]["old_file"] = $personalPhoto["PERSONAL_PHOTO"];
				}
			}
		}

		if(!self::CheckFields('UPDATE', $arFields))
		{
			return false;
		}

		$arDbFields = $arFields;
		if (static::hasEncryptedFields(array_keys($arDbFields)))
			static::encryptFields($arDbFields);

		$strUpdate = $DB->PrepareUpdate("b_socialservices_user", $arDbFields);

		$strSql = "UPDATE b_socialservices_user SET ".$strUpdate." WHERE ID = ".$id." ";
		$DB->Query($strSql);

		$cache_id = 'socserv_ar_user';
		$obCache = new CPHPCache;
		$cache_dir = '/bx/socserv_ar_user';
		$obCache->Clean($cache_id, $cache_dir);

		$arFields['ID'] = $id;
		foreach(GetModuleEvents("socialservices", "OnAfterSocServUserUpdate", true) as $arEvent)
			ExecuteModuleEventEx($arEvent, array(&$arFields));

		return $id;
	}

	public static function Delete($id)
	{
		global $DB;
		$id = intval($id);
		if ($id > 0)
		{
			$rsUser = $DB->Query("SELECT ID, PERSONAL_PHOTO FROM b_socialservices_user WHERE ID=".$id);
			$arUser = $rsUser->Fetch();
			if (!$arUser)
			{
				return false;
			}

			foreach (GetModuleEvents("socialservices", "OnBeforeSocServUserDelete", true) as $arEvent)
			{
				ExecuteModuleEventEx($arEvent, array($id));
			}

			CFile::Delete($arUser["PERSONAL_PHOTO"]);

			$DB->Query("DELETE FROM b_socialservices_user WHERE ID = ".$id." ", true);

			$cache_id = 'socserv_ar_user';
			$obCache = new CPHPCache;
			$cache_dir = '/bx/socserv_ar_user';
			$obCache->Clean($cache_id, $cache_dir);

			return true;
		}
		return false;
	}

	public static function OnUserDelete($id)
	{
		global $DB;
		$id = intval($id);
		if ($id > 0)
		{
			$rsUsers = $DB->Query("SELECT ID FROM b_socialservices_user WHERE USER_ID = ".$id." ", true);
			while ($arUserLink = $rsUsers->Fetch())
			{
				self::Delete($arUserLink["ID"]);
			}
			return true;
		}
		return false;
	}

	public static function OnAfterTMReportDailyAdd()
	{
		if(COption::GetOptionString("socialservices", "allow_send_user_activity", "Y") != 'Y')
			return;
		global $USER;
		$arIntranetData = $arResult = $arData = array();
		$eventCounter = $taskCounter = 0;
		if(CModule::IncludeModule('intranet'))
		{
			$arIntranetData = CIntranetPlanner::getData(SITE_ID, true);
		}
		if(isset($arIntranetData['DATA']))
		{
			$arData = $arIntranetData['DATA'];
		}
		if(isset($arData['EVENTS']) && is_array($arData['EVENTS']))
		{
			$eventCounter = count($arData['EVENTS']);
		}
		if(isset($arData['TASKS']) && is_array($arData['TASKS']))
		{
			$taskCounter = count($arData['TASKS']);
		}

		$arResult['USER_ID'] = intval($USER?->GetID());
		if($arResult['USER_ID'] > 0)
		{
			$enabledSendMessage = CUserOptions::GetOption("socialservices", "user_socserv_enable", "N", $arResult['USER_ID']);
			if($enabledSendMessage == 'Y')
			{
				$enabledEndDaySend = CUserOptions::GetOption("socialservices", "user_socserv_end_day", "N", $arResult['USER_ID']);
				if($enabledEndDaySend == 'Y')
				{
					$arResult['MESSAGE'] = str_replace('#event#', $eventCounter, str_replace('#task#', $taskCounter, CUserOptions::GetOption("socialservices", "user_socserv_end_text", GetMessage("JS_CORE_SS_WORKDAY_END"), $arResult['USER_ID'])));

					$socServArray = CUserOptions::GetOption("socialservices", "user_socserv_array", "a:0:{}", $arResult['USER_ID']);
					if(!CheckSerializedData($socServArray))
					{
						$socServArray = "a:0:{}";
					}

					$arSocServUser['SOCSERVARRAY'] = unserialize($socServArray, ["allowed_classes" => false]);

					if(is_array($arSocServUser['SOCSERVARRAY']) && count($arSocServUser['SOCSERVARRAY']) > 0)
					{
						foreach($arSocServUser['SOCSERVARRAY'] as $id => $providerName)
						{
							$arResult['SOCSERV_USER_ID'] = $id;
							$arResult['PROVIDER'] = $providerName;
							CSocServMessage::Add($arResult);
						}
					}
				}
			}
		}
	}

	public static function OnAfterTMDayStart()
	{
		if(COption::GetOptionString("socialservices", "allow_send_user_activity", "Y") != 'Y')
			return;
		global $USER;
		$arResult = array();
		$arResult['USER_ID'] = intval($USER->GetID());
		if($arResult['USER_ID'] > 0)
		{
			$enabledSendMessage = CUserOptions::GetOption("socialservices", "user_socserv_enable", "N", $arResult['USER_ID']);
			if($enabledSendMessage == 'Y')
			{
				$enabledEndDaySend = CUserOptions::GetOption("socialservices", "user_socserv_start_day", "N", $arResult['USER_ID']);
				if($enabledEndDaySend == 'Y')
				{
					$arResult['MESSAGE'] = CUserOptions::GetOption("socialservices", "user_socserv_start_text", GetMessage("JS_CORE_SS_WORKDAY_START"), $arResult['USER_ID']);

					$socServArray = CUserOptions::GetOption("socialservices", "user_socserv_array", "a:0:{}", $arResult['USER_ID']);
					if(!CheckSerializedData($socServArray))
					{
						$socServArray = "a:0:{}";
					}

					$arSocServUser['SOCSERVARRAY'] = unserialize($socServArray, ["allowed_classes" => false]);

					if(is_array($arSocServUser['SOCSERVARRAY']) && count($arSocServUser['SOCSERVARRAY']) > 0)
					{
						foreach($arSocServUser['SOCSERVARRAY'] as $id => $providerName)
						{
							$arResult['SOCSERV_USER_ID'] = $id;
							$arResult['PROVIDER'] = $providerName;
							CSocServMessage::Add($arResult);
						}
					}
				}
			}
		}
	}

	public function CheckSettings()
	{
		$arSettings = $this->GetSettings();
		if(is_array($arSettings))
		{
			foreach($arSettings as $sett)
				if(is_array($sett) && !array_key_exists("note", $sett))
					if(self::GetOption($sett[0]) == '')
						return false;
		}
		return true;
	}

	public function CheckPhotoURI($photoURI)
	{
		if(preg_match("|^http[s]?://|i", $photoURI))
			return true;
		return false;
	}

	public static function OptionsSuffix()
	{
		//settings depend on current site
		$arUseOnSites = unserialize(COption::GetOptionString("socialservices", "use_on_sites", ""), ["allowed_classes" => false]);
		return (isset($arUseOnSites[SITE_ID]) && $arUseOnSites[SITE_ID] === "Y"? '_bx_site_'.SITE_ID : '');
	}

	public static function GetOption($opt)
	{
		if(self::$settingsSuffix === false)
			self::$settingsSuffix = self::OptionsSuffix();

		return COption::GetOptionString("socialservices", $opt.self::$settingsSuffix);
	}

	public static function SetOption($opt, $value)
	{
		if(self::$settingsSuffix === false)
			self::$settingsSuffix = self::OptionsSuffix();

		return COption::SetOptionString("socialservices", $opt.self::$settingsSuffix, $value);
	}

	public static function getGroupsDenyAuth()
	{
		return explode(',', (COption::GetOptionString("socialservices", "group_deny_auth", "")));
	}

	public static function getGroupsDenySplit()
	{
		return explode(',', (COption::GetOptionString("socialservices", "group_deny_split", "")));
	}

	public static function setGroupsDenyAuth($value)
	{
		COption::SetOptionString('socialservices', 'group_deny_auth', is_array($value) ? implode(',', $value) : '');
	}

	public static function setGroupsDenySplit($value)
	{
		COption::SetOptionString('socialservices', 'group_deny_split', is_array($value) ? implode(',', $value) : '');
	}

	public static function isSplitDenied($arGroups = null)
	{
		global $USER;

		if($arGroups === null)
		{
			return $USER->IsAuthorized()
				&& count(array_intersect(self::getGroupsDenySplit(), $USER->GetUserGroupArray())) > 0;
		}
		else
		{
			return count(array_intersect(self::getGroupsDenySplit(), $arGroups)) > 0;
		}
	}

	public static function isAuthDenied($arGroups)
	{
		return count(array_intersect(self::getGroupsDenyAuth(), $arGroups)) > 0;
	}

	public function AuthorizeUser($socservUserFields, $bSave = false)
	{
		global $USER, $APPLICATION;

		foreach(GetModuleEvents("socialservices", "OnBeforeSocServUserAuthorize", true) as $arEvent)
		{
			$errorCode = SOCSERV_AUTHORISATION_ERROR;
			if(ExecuteModuleEventEx($arEvent, array($this, &$socservUserFields, &$errorCode)) === false)
			{
				return $errorCode;
			}
		}

		if (empty($socservUserFields['XML_ID']))
		{
			return false;
		}

		if (empty($socservUserFields['EXTERNAL_AUTH_ID']))
		{
			return false;
		}

		$oauthKeys = array();
		if(isset($socservUserFields["OATOKEN"]))
		{
			$oauthKeys["OATOKEN"] = $socservUserFields["OATOKEN"];
		}
		if(isset($socservUserFields["REFRESH_TOKEN"]) && $socservUserFields["REFRESH_TOKEN"] !== '')
		{
			$oauthKeys["REFRESH_TOKEN"] = $socservUserFields["REFRESH_TOKEN"];
		}
		if(isset($socservUserFields["OATOKEN_EXPIRES"]))
		{
			$oauthKeys["OATOKEN_EXPIRES"] = $socservUserFields["OATOKEN_EXPIRES"];
		}

		$errorCode = SOCSERV_AUTHORISATION_ERROR;

		$dbSocUser = UserTable::getList(array(
			'filter' => array(
				'=XML_ID'=>$socservUserFields['XML_ID'],
				'=EXTERNAL_AUTH_ID'=>$socservUserFields['EXTERNAL_AUTH_ID']
			),
			'select' => array("ID", "USER_ID", "ACTIVE" => "USER.ACTIVE", "PERSONAL_PHOTO"),
		));
		$socservUser = $dbSocUser->fetch();

		if($USER->IsAuthorized())
		{
			if(!$this->checkRestrictions || !self::isSplitDenied())
			{
				if(!$socservUser)
				{
					$socservUserFields["USER_ID"] = $USER->GetID();
					$result = UserTable::add(UserTable::filterFields($socservUserFields));
					$id = $result->getId();
				}
				else
				{
					$id = $socservUser['ID'];

					// socservice link split
					if($socservUser['USER_ID'] != $USER->GetID())
					{
						if($this->allowChangeOwner)
						{
							$dbSocUser = UserTable::getList(array(
									'filter' => array(
											'=USER_ID' => $USER->GetID(),
											'=EXTERNAL_AUTH_ID' => $socservUserFields['EXTERNAL_AUTH_ID']
									),
									'select' => array("ID")
							));
							if($dbSocUser->fetch())
							{
								return SOCSERV_AUTHORISATION_ERROR;
							}
							else
							{
								$oauthKeys['USER_ID'] = $USER->GetID();
								$oauthKeys['CAN_DELETE'] = 'Y';
							}
						}
						else
						{
							return SOCSERV_AUTHORISATION_ERROR;
						}
					}
				}

				if($_SESSION["OAUTH_DATA"] && is_array($_SESSION["OAUTH_DATA"]))
				{
					$oauthKeys = array_merge($oauthKeys, $_SESSION['OAUTH_DATA']);
					unset($_SESSION["OAUTH_DATA"]);
				}

				UserTable::update($id, $oauthKeys);
			}
			else
			{
				return SOCSERV_REGISTRATION_DENY;
			}
		}
		else
		{
			$entryId = 0;
			$USER_ID = 0;

			if($socservUser)
			{
				$entryId = $socservUser['ID'];
				if($socservUser["ACTIVE"] === 'Y')
				{
					$USER_ID = $socservUser["USER_ID"];
				}
			}
			else
			{
				foreach(GetModuleEvents('socialservices', 'OnFindSocialservicesUser', true) as $event)
				{
					$eventResult = ExecuteModuleEventEx($event, array(&$socservUserFields));
					if($eventResult > 0)
					{
						$USER_ID = $eventResult;
						break;
					}
				}

				if(!$USER_ID)
				{
					if ($this->isAllowedRegisterNewUser())
					{
						$socservUserFields['PASSWORD'] = randString(30); //not necessary but...
						$socservUserFields['LID'] = SITE_ID;

						$def_group = Option::get('main', 'new_user_registration_def_group');
						if($def_group <> '')
						{
							$socservUserFields['GROUP_ID'] = explode(',', $def_group);
						}


						if(
							$this->checkRestrictions
							&& !empty($socservUserFields['GROUP_ID'])
							&& self::isAuthDenied($socservUserFields['GROUP_ID'])
						)
						{
							$errorCode = SOCSERV_REGISTRATION_DENY;
						}
						else
						{
							$userFields = $socservUserFields;
							$userFields["EXTERNAL_AUTH_ID"] = "socservices";

							if(isset($userFields['PERSONAL_PHOTO']) && is_array($userFields['PERSONAL_PHOTO']))
							{
								$res = CFile::CheckImageFile($userFields["PERSONAL_PHOTO"]);
								if($res <> '')
								{
									unset($userFields['PERSONAL_PHOTO']);
								}
							}

							$USER_ID = $USER->Add($userFields);
							if($USER_ID <= 0)
							{
								$errorCode = SOCSERV_AUTHORISATION_ERROR;
							}
						}
					}
					elseif(Option::get("main", "new_user_registration", "N") == "N")
					{
						$errorCode = SOCSERV_REGISTRATION_DENY;
					}

					$socservUserFields['CAN_DELETE'] = 'N';
				}
			}

			if(isset($_SESSION["OAUTH_DATA"]) && is_array($_SESSION["OAUTH_DATA"]))
			{
				foreach ($_SESSION['OAUTH_DATA'] as $key => $value)
				{
					$socservUserFields[$key] = $value;
				}
				unset($_SESSION["OAUTH_DATA"]);
			}

			if($USER_ID > 0)
			{
				$arGroups = $USER->GetUserGroup($USER_ID);
				if($this->checkRestrictions && self::isAuthDenied($arGroups))
				{
					return SOCSERV_AUTHORISATION_ERROR;
				}

				if($entryId > 0)
				{
					UserTable::update($entryId, UserTable::filterFields($socservUserFields, $socservUser));
				}
				else
				{
					$socservUserFields['USER_ID'] = $USER_ID;
					UserTable::add(UserTable::filterFields($socservUserFields));

					foreach (EventManager::getInstance()->findEventHandlers("socialservices", "OnUserInitialize") as $arEvent)
					{
						ExecuteModuleEventEx($arEvent, array($USER_ID));
					}
				}

				$context = (new Authentication\Context())
					->setUserId($USER_ID)
					->setExternalAuthId($socservUserFields['EXTERNAL_AUTH_ID'])
					->setExternalId($socservUserFields['XML_ID'])
					->setMethod(Authentication\Method::External)
				;

				$USER->AuthorizeWithOtp($context, $bSave);

				if($USER->IsJustAuthorized())
				{
					foreach(GetModuleEvents("socialservices", "OnUserLoginSocserv", true) as $arEvent)
					{
						ExecuteModuleEventEx($arEvent, array($socservUserFields));
					}
				}
			}
			else
			{
				return $errorCode;
			}

			// possible redirect after authorization, so no spreading. Store cookies in the session for next hit
			$APPLICATION->StoreCookies();
		}

		return true;
	}

	public static function OnFindExternalUser($login)
	{
		$userRow = \Bitrix\Main\UserTable::getRow([
			'select' => ['ID'],
			'filter' => [
				'=ACTIVE' => 'Y',
				'=EXTERNAL_AUTH_ID' => 'socservices',
				'=LOGIN' => $login,
			],
		]);

		if (isset($userRow['ID']))
		{
			return $userRow['ID'];
		}

		$socialserviceRow = UserTable::getRow([
			'select' => ['USER_ID'],
			'filter' => [
				'=USER.ACTIVE' => 'Y',
				'=LOGIN' => $login,
			],
		]);

		return $socialserviceRow['USER_ID'] ?? 0;
	}

	public function setAllowChangeOwner($value)
	{
		$this->allowChangeOwner = (bool)$value;
	}

	protected static function hasEncryptedFields($arFields)
	{
		if (!CryptoField::cryptoAvailable())
			return false;

		return (
			!$arFields
			|| in_array('*', $arFields)
			|| in_array('OATOKEN', $arFields)
			|| in_array('OASECRET', $arFields)
			|| in_array('REFRESH_TOKEN', $arFields)
		);
	}

	protected static function encryptFields(&$arFields)
	{
		$cryptoField = new CryptoField('OATOKEN');

		if (array_key_exists('OATOKEN', $arFields))
			$arFields['OATOKEN'] = $cryptoField->encrypt($arFields['OATOKEN']);

		if (array_key_exists('OASECRET', $arFields))
			$arFields['OASECRET'] = $cryptoField->encrypt($arFields['OASECRET']);

		if (array_key_exists('REFRESH_TOKEN', $arFields))
			$arFields['REFRESH_TOKEN'] = $cryptoField->encrypt($arFields['REFRESH_TOKEN']);
	}

	protected function isAllowedRegisterNewUser(): bool
	{
		return COption::GetOptionString("main", "new_user_registration", "N") === "Y"
			&& COption::GetOptionString("socialservices", "allow_registration", "Y") === "Y";
	}

	protected function onAfterMobileAuth()
	{
		$params = http_build_query([
			'mode' => self::MOBILE_MODE,
		]);

		$httpResponse = new HttpResponse();
		$httpResponse->addHeader('Location', 'bitrix24://?' . $params);
		Application::getInstance()->end(0, $httpResponse);
	}

	protected function onAfterWebAuth($addParams, $mode, $url)
	{
		if($addParams)
		{
			$location = ($mode === self::OPENER_MODE) ? 'if(window.opener) window.opener.location = \''.$url.'\'; window.close();' : ' window.location = \''.$url.'\';';
		}
		else
		{
			//fix for chrome
			$location = ($mode === self::OPENER_MODE) ? 'if(window.opener) window.opener.location = window.opener.location.href + \''.$url.'\'; window.close();' : ' window.location = window.location.href + \''.$url.'\';';
		}

		$JSScript = '
			<script>
			'.$location.'
			</script>
			';

		echo $JSScript;
	}
}
