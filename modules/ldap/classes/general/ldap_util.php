<?php

use Bitrix\Ldap\Internal\ImageType;
use Bitrix\Ldap\Internal\Networking\Ip;
use Bitrix\Ldap\Internal\Networking\Subnet;

IncludeModuleLangFile(__FILE__);

class CLdapUtil
{
	public static function GetSynFields()
	{
		static $arSyncFields =	false;
		if(!is_array($arSyncFields))
		{
			// "Field in CUser"=>Array("NAME" => "Name in Bitrix CMS", "AD"=>"Default attribute in AD", "LDAP"=>"Default Attribute in LDAP")
			$arSyncFields = Array(
				"ACTIVE"				=>Array("NAME" => GetMessage("LDAP_FIELD_ACTIVE"), "AD"=>"UserAccountControl&2"),
				"EMAIL"					=>Array("NAME" => GetMessage("LDAP_FIELD_EMAIIL"), "AD"=>"mail", "LDAP"=>"email"),
				"NAME"					=>Array("NAME" => GetMessage("LDAP_FIELD_NAME"), "AD"=>"givenName", "LDAP"=>"cn"),
				"LAST_NAME"				=>Array("NAME" => GetMessage("LDAP_FIELD_LAST_NAME"), "AD"=>"sn", "LDAP"=>"sn"),
				"SECOND_NAME"			=>Array("NAME" => GetMessage("LDAP_FIELD_SECOND_NAME")),
				"PERSONAL_GENDER"		=>Array("NAME" => GetMessage("LDAP_FIELD_GENDER")),
				"PERSONAL_BIRTHDAY"		=>Array("NAME" => GetMessage("LDAP_FIELD_BIRTHDAY")),
				"PERSONAL_PROFESSION"	=>Array("NAME" => GetMessage("LDAP_FIELD_PROF")),
				"PERSONAL_PHOTO"		=>Array("NAME" => GetMessage("LDAP_FIELD_PHOTO"), "AD"=>"thumbnailPhoto", "LDAP"=>"jpegPhoto"),
				"PERSONAL_WWW"			=>Array("NAME" => GetMessage("LDAP_FIELD_WWW"), "AD"=>"wWWHomePage"),
				"PERSONAL_ICQ"			=>Array("NAME" => "ICQ"),
				"PERSONAL_PHONE"		=>Array("NAME" => GetMessage("LDAP_FIELD_PHONE"), "AD"=>"homePhone"),
				"PERSONAL_FAX"			=>Array("NAME" => GetMessage("LDAP_FIELD_FAX")),
				"PERSONAL_MOBILE"		=>Array("NAME" => GetMessage("LDAP_FIELD_MOB"), "AD"=>"mobile"),
				"PERSONAL_PAGER"		=>Array("NAME" => GetMessage("LDAP_FIELD_PAGER")),
				"PERSONAL_STREET"		=>Array("NAME" => GetMessage("LDAP_FIELD_STREET"), "AD"=>"streetAddress"),
				"PERSONAL_MAILBOX"		=>Array("NAME" => GetMessage("LDAP_FIELD_MAILBOX"), "AD"=>"postOfficeBox"),
				"PERSONAL_CITY"			=>Array("NAME" => GetMessage("LDAP_FIELD_CITY"), "AD"=>"l"),
				"PERSONAL_STATE"		=>Array("NAME" => GetMessage("LDAP_FIELD_STATE"), "AD"=>"st"),
				"PERSONAL_ZIP"			=>Array("NAME" => GetMessage("LDAP_FIELD_ZIP"), "AD"=>"postalCode"),
				"PERSONAL_COUNTRY"		=>Array("NAME" => GetMessage("LDAP_FIELD_COUNTRY"), "AD"=>"c"),
				//"PERSONAL_NOTES"		=>Array("NAME" => "Personal notes"),
				"WORK_COMPANY"			=>Array("NAME" => GetMessage("LDAP_FIELD_COMPANY"), "AD"=>"company"),
				"WORK_DEPARTMENT"		=>Array("NAME" => GetMessage("LDAP_FIELD_DEP"), "AD"=>"department"),
				"WORK_POSITION"			=>Array("NAME" => GetMessage("LDAP_FIELD_POS"), "AD"=>"title"),
				//"WORK_WWW"			=>Array("NAME" => "Company web page"),
				"WORK_PHONE"			=>Array("NAME" => GetMessage("LDAP_FIELD_WORK_PHONE"), "AD"=>"telephoneNumber"),
				"WORK_FAX"				=>Array("NAME" => GetMessage("LDAP_FIELD_WORK_FAX"), "AD"=>"facsimileTelephoneNumber"),
				"WORK_PAGER"			=>Array("NAME" => GetMessage("LDAP_FIELD_WORK_PAGER")),
				//"WORK_STREET"			=>Array("NAME" => "Work address"),
				//"WORK_MAILBOX"		=>Array("NAME" => ""),
				//"WORK_CITY"			=>Array("NAME" => ""),
				//"WORK_STATE"			=>Array("NAME" => ""),
				//"WORK_ZIP"			=>Array("NAME" => ""),
				//"WORK_COUNTRY"		=>Array("NAME" => ""),
				//"WORK_PROFILE"		=>Array("NAME" => ""),
				//"WORK_NOTES"			=>Array("NAME" => "Additional notes"),
				"ADMIN_NOTES"			=>Array("NAME" => GetMessage("LDAP_FIELD_ADMIN_NOTES"), "AD"=>"description"),
			);

			$arRes = $GLOBALS["USER_FIELD_MANAGER"]->GetUserFields("USER", 0, LANGUAGE_ID);
			foreach($arRes as $pr_id=>$pr_v)
				if($pr_v["EDIT_FORM_LABEL"]!='')
					$arSyncFields[$pr_id] = Array("NAME"=>$pr_v["EDIT_FORM_LABEL"]);
		}

		return $arSyncFields;
	}

	// gets department list from system (iblock) for displaying in select box
	public static function getDepartmentListFromSystem($arFilter = Array())
	{
		if (!IsModuleInstalled('intranet'))
		{
			return false;
		}

		$l=false;
		if (CModule::IncludeModule('iblock'))
		{
			$iblockId=COption::GetOptionInt("intranet","iblock_structure",false,false);
			if ($iblockId)
			{
				$arFilter["IBLOCK_ID"] = $iblockId;
				$arFilter["CHECK_PERMISSIONS"]="N";
				$l = CIBlockSection::GetTreeList($arFilter);
			}
		}
		return $l;
	}

	public static function SetDepartmentHead($userId, $sectionId)
	{
		//echo "Setting ".$userId." as head of ".$sectionId;

		$iblockId=COption::GetOptionInt("intranet","iblock_structure",false,false);

		if ($iblockId && $sectionId && $userId && CModule::IncludeModule('iblock'))
		{
			/*$perm = CIBlock::GetPermission($iblockId);
			if ($perm >= 'W')
			{*/
				$obS = new CIBlockSection();
				if ($obS->Update($sectionId, array('UF_HEAD' => $userId), false, false))
				{
					return true;
				}
				else //if ($obS->LAST_ERROR)
				{
					// update error
					return false;
				}
			/*}
			else
			{
				// access denied
				return false;
			}*/
		}
		else
		{
			// bad data
			return false;
		}
	}

	public static function OnAfterUserAuthorizeHandler()
	{
		if (defined('LDAP_NO_PORT_REDIRECTION'))
		{
			return false;
		}

		global $USER;

		if ($USER->IsAuthorized())
		{
			$subnet = Subnet::forAuthentication();
			if ($subnet && Ip::current()->outsideOf($subnet))
			{
				return false;
			}

			$backUrl = $_GET['back_url'] ?? '/';

			if ($_SERVER['SERVER_PORT'] === '8890')
			{
				LocalRedirect('http://' . $_SERVER['SERVER_NAME'] . $backUrl);
			}
			elseif ($_SERVER['SERVER_PORT'] === '8891')
			{
				LocalRedirect('https://' . $_SERVER['SERVER_NAME'] . $backUrl);
			}
		}

		return true;
	}

	public static function OnEpilogHandler()
	{
		return self::bitrixVMAuthorize();
	}

	public static function bitrixVMAuthorize()
	{
		if (defined('LDAP_NO_PORT_REDIRECTION'))
		{
			return false;
		}

		global $USER, $APPLICATION;

		if (!$USER->IsAuthorized())
		{
			$subnet = Subnet::forAuthentication();
			if ($subnet && Ip::current()->outsideOf($subnet))
			{
				return false;
			}

			$backUrl = mb_strlen($APPLICATION->GetCurPage()) > 1 ? '?back_url=' . rawurlencode($APPLICATION->GetCurUri()) : '';

			if ($_SERVER['SERVER_PORT'] === '80')
			{
				LocalRedirect('http://' . $_SERVER['SERVER_NAME'] . ':8890/' . $backUrl, true);
			}
			elseif (($_SERVER['SERVER_PORT'] === '443'))
			{
				LocalRedirect('https://' . $_SERVER['SERVER_NAME'] . ':8891/' . $backUrl, true);
			}
		}

		return true;
	}

	public static function isBitrixVMAuthSupported()
	{
		$eventManager = \Bitrix\Main\EventManager::getInstance();
		$hndl = $eventManager->findEventHandlers("main", "OnEpilog", array("ldap"));
		return !empty($hndl);
	}

	public static function SetBitrixVMAuthSupport($setOption=false, $netAndMask=false)
	{
		RegisterModuleDependences("main", "OnAfterUserAuthorize", 'ldap', 'CLdapUtil', 'OnAfterUserAuthorizeHandler');
		RegisterModuleDependences("main", "OnEpilog", 'ldap', 'CLdapUtil', 'OnEpilogHandler');

		if($setOption)
			COption::SetOptionString("ldap", "bitrixvm_auth_support", "Y");

		if($netAndMask)
			COption::SetOptionString("ldap", "bitrixvm_auth_net", $netAndMask);
	}

	public static function UnSetBitrixVMAuthSupport($unSetOption=false)
	{
		UnRegisterModuleDependences("main", "OnAfterUserAuthorize", 'ldap', 'CLdapUtil', 'OnAfterUserAuthorizeHandler');
		UnRegisterModuleDependences("main", "OnEpilog", 'ldap', 'CLdapUtil', 'OnEpilogHandler');

		if($unSetOption)
			COption::SetOptionString("ldap", "bitrixvm_auth_support", "N");
	}

	/**
	 * Decides if ip address is from given network/mask;network1/mask1;network2/mask2;...
	 * @deprecated Use \Bitrix\Ldap\Internal\Networking\Subnet instead
	 * @param string $ip - valid ip address  - xxx.xxx.xxx.xxx
	 * @param string $netsAndMasks - valid mask/network - xxx.xxx.xxx.xxx/xxx.xxx.xxx.xxx;xxx.xxx.xxx.xxx/xxx.xxx.xxx.xxx;... or xxx.xxx.xxx.xxx/xx;xxx.xxx.xxx.xxx/xx;...
	 * @return bool true - if in, bool false - not in, or bad params
	 */
	public static function IsIpFromNet($ip, $netsAndMasks)
	{
		$subnet = new Subnet((string)$netsAndMasks);

		return $subnet->includes((string)$ip);
	}

	/**
	 * Returns image file extension/type/format
	 * @deprecated Use Bitrix\Ldap\Internal\ImageType instead
	 * @param string $signature - first 12 bytes of the file.
	 * @return string|false
	 */
	public static function GetImgTypeBySignature($signature)
	{
		$imageType = ImageType::fromFileContent((string)$signature);

		return ($imageType === ImageType::Unknown)
			? false
			: $imageType->value;
	}

	/**
	 * @deprecated
	 * @return bool
	 */
	public static function isLdapPaginationAviable(): bool
	{
		return true;
	}

	/**
	 * Returns true id defined net range for redirection
	 * on ntlm authorization ports 8890, 8891
	 * @deprecated
	 * @return bool
	 */
	public static function isNtlmRedirectNetRangeDefined()
	{
		$authNet = COption::GetOptionString("ldap", 'bitrixvm_auth_net', '');
		return trim($authNet) <> '';
	}

	/**
	 * @deprecated
	 * @param string|false $serverPort Server port.
	 * @return string|false Port for outlook connection.
	 */
	public static function getTargetPort($serverPort = false)
	{
		if ($serverPort === false)
		{
			$serverPort = $_SERVER['SERVER_PORT'];
		}

		$result = false;

		$vmAuth = \COption::GetOptionString('ldap', 'bitrixvm_auth_support', 'N') === 'Y';
		$useNtlm = \COption::GetOptionString('ldap', 'use_ntlm', 'N') === 'Y';
		$isNtlmOn = $vmAuth && $useNtlm;

		if ($serverPort === '80')
		{
			$result = $isNtlmOn ? '8890' : '80';
		}
		elseif ($serverPort == '443')
		{
			$result = $isNtlmOn ? '8891' : '443';
		}

		return $result;
	}
}
