<? /** @noinspection MagicMethodsValidityInspection */
if (!defined("UPDATE_SYSTEM_VERSION_A")) {
	define("UPDATE_SYSTEM_VERSION_A", "24.200.300");
}
if (!defined("BX_DIR_PERMISSIONS")) define("BX_DIR_PERMISSIONS", 493);
if (!defined("DEFAULT_UPDATE_SERVER")) {
	define("DEFAULT_UPDATE_SERVER", "www.bitrixsoft.com");
}

IncludeModuleLangFile(__FILE__);

if (extension_loaded("zlib")) {
	if (!function_exists("gzopen") && function_exists("gzopen64")) {
		function gzopen($_906972647, $_563707371, $_313577720 = 0)
		{
			return gzopen64($_906972647, $_563707371, $_313577720);
		}
	}
}
if (!function_exists("htmlspecialcharsbx")) {
	function htmlspecialcharsbx($_1296951786, $_1302320733 = ENT_COMPAT)
	{
		return htmlspecialchars($_1296951786, $_1302320733, (defined("BX_UTF") ? "UTF-8" : "ISO-8859-1"));
	}
}

if (!defined("US_SHARED_KERNEL_PATH"))
	define("US_SHARED_KERNEL_PATH", "/bitrix");
if (!defined("US_CALL_TYPE"))
	define("US_CALL_TYPE", "ALL");
if (!defined("US_BASE_MODULE")) define("US_BASE_MODULE", "main");

$GLOBALS["UPDATE_STRONG_UPDATE_CHECK"]  = "";
$GLOBALS["CACHE4UPDATESYS_LICENSE_KEY"] = "";

require_once($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/classes/general/update_class.php");

class CUpdateClient
{
	public static function getLicenseTextPath()
	{
		$_1377042919 = array();
		$_326250928  = "sort";
		$_1938863488 = "asc";
		$_719560041  = CLanguage::GetList($_326250928, $_1938863488, array("ACTIVE" => "Y"));
		while ($_1390141975 = $_719560041->Fetch()) {
			$_1377042919[] = $_1390141975["LID"];
		}
		$_1023785319 = COption::GetOptionString("main", "update_site", DEFAULT_UPDATE_SERVER);
		$_1940603258 = COption::GetOptionString("main", "vendor", "");
		if (IsModuleInstalled("updateserverlight")) {
			if ($_1940603258 == "1c_bitrix_portal" || $_1940603258 == "1c_bitrix") {
				$_1023785319 = "www.1c-bitrix.ru";
			} else {
				$_1023785319 = DEFAULT_UPDATE_SERVER;
			}
		}
		return "//" . $_1023785319 . "/bitrix/updates/license.php?intranet=" . (IsModuleInstalled("intranet") ? "Y" : "N") . "&lang=" . urlencode(LANGUAGE_ID) . "&vendor=" . urlencode($_1940603258) . "&langs=" . urlencode(implode(",", $_1377042919));
	}

	public static function getNewLicenseSignedKey()
	{
		$_1178254619 = "~new_license17_5_sign";
		if (!IsModuleInstalled("intranet")) {
			if (file_exists($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/lang/ru")) {
				$_1178254619 = "~new_license18_0_sign";
			}
		} else {
			if (file_exists($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/lang/ru")) {
				$_1178254619 = "~new_license24_400_sign";
			} else {
				$_1178254619 = "~new_license24_400_sign";
			}
		}
		return $_1178254619;
	}

	public static function finalizeModuleUpdate($modules)
	{
		$updaterFilePath = $_SERVER["DOCUMENT_ROOT"] . US_SHARED_KERNEL_PATH . "/modules/updater_versions.php";
		$currentModulesData = [];

		// Загрузка существующих модулей, если файл уже существует
		if (file_exists($updaterFilePath)) {
			$currentModulesData = include $updaterFilePath;
		}

		$processedVersions = [];
		$updatedModules = [];

		foreach ($modules as $moduleInfo) {
			$moduleName = preg_replace("/[^a-zA-Z0-9._-]/", "", $moduleInfo["@"]["NAME"]);
			$moduleVersion = implode(".", array_slice(array_map("intval", explode(".", $moduleInfo["@"]["VALUE"])), 0, 3));

			// Не обрабатываем, если модуль с этой версией уже добавлен
			$moduleKey = $moduleName . "#" . $moduleVersion;
			if (isset($processedVersions[$moduleKey])) {
				continue;
			}

			$processedVersions[$moduleKey] = true;

			// Инициализация обновлённого модуля
			if (!isset($updatedModules[$moduleName])) {
				$updatedModules[$moduleName] = [];
			}
			$updatedModules[$moduleName][] = $moduleVersion;

			// Информация о версиях модуля
			if (!isset($currentModulesData["modules"][$moduleName])) {
				$currentModulesData["modules"][$moduleName] = [];
			}
			$currentModulesData["modules"][$moduleName][] = [$moduleVersion, date("Y-m-d H:i:s")];
		}

		// Сохранение обобщённых данных о модулях
		$exportedData = var_export($currentModulesData, true);
		file_put_contents($updaterFilePath, "<?php return " . $exportedData . ";");

		// Очистка кэшей, если определён класс `Bitrix\Main\Data\CacheEngineFiles`
		if (class_exists("Bitrix\Main\Data\CacheEngineFiles")) {
			$cacheEngine = new Bitrix\Main\Data\CacheEngineFiles();

			$cacheEngine->clean(BX_PERSONAL_ROOT . "/cache", "/css/");
			$cacheEngine->clean(BX_PERSONAL_ROOT . "/cache", "/js/");
			$cacheEngine->clean(BX_PERSONAL_ROOT . "/managed_cache/MYSQL", "/css/");
			$cacheEngine->clean(BX_PERSONAL_ROOT . "/managed_cache/MYSQL", "/js/");
		}

		// Генерация событий
		foreach (GetModuleEvents("main", "OnFinishModuleUpdate", true) as $event) {
			ExecuteModuleEventEx($event, [
				$updatedModules,
				$currentModulesData,
				$GLOBALS["BX_REAL_UPDATED_MODULES"] ?? []
			]);
		}
	}


	public static function finalizeLanguageUpdate($_2054273693)
	{
		$_1455832098 = $_SERVER["DOCUMENT_ROOT"] . US_SHARED_KERNEL_PATH . "/modules/updater_versions.php";
		$_1342175707 = array();
		if(file_exists($_1455832098)) $_1342175707 = include($_1455832098);
		$_250896991                               = array();
		foreach ($_2054273693 as $_339216575 => $_139629394) {
			$_339216575              = preg_replace("/[^a-zA-Z0-9._-]/", "", $_339216575);
			$_250896991[$_339216575] = array($_139629394, date("Y-m-d H:i:s"));
			if (!isset($_1342175707["langs"][$_339216575])) $_1342175707["langs"][$_339216575] = array();
			$_1342175707["langs"][$_339216575][] = date("Y-m-d H:i:s");
		}
		unset($_1342175707["langs"][""]);
		$_685670079 = var_export($_1342175707, true);
		file_put_contents($_1455832098, "<" . "?php return " . $_685670079 . ";");

		foreach (GetModuleEvents("main", "OnFinishLanguageUpdate", true) as $_1599781934) {
			ExecuteModuleEventEx($_1599781934, array($_250896991, $_1342175707));
		}
	}

	private static function __328632251($_321435640)
	{
		CUpdateClient::AddMessage2Log("exec CUpdateClient::executeCounters");
		$_418914584 = microtime(true);
		if (empty($_321435640)) return false;
		$_1015571134 = "";
		$_1622907824 = CUpdateClient::CollectRequestData($_1015571134);
		if (empty($_1622907824) && empty($_1015571134)) $_1015571134 = "[RV01] " . GetMessage("SUPZ_NO_QSTRING") . ". ";
		if (empty($_1015571134)) {
			$_1622907824 .= "&query_type=counter";
			foreach ($_321435640 as $_1982206509) {
				$_869020157 = "";
				if (isset($_1982206509["#"]["cdata-section"][0]["#"]) && is_string($_1982206509["#"]["cdata-section"][0]["#"]) && ($_1982206509["#"]["cdata-section"][0]["#"] !== "")) {
					$_869020157 = $_1982206509["#"]["cdata-section"][0]["#"];
				} elseif (isset($_1982206509["#"]) && is_string($_1982206509["#"]) && ($_1982206509["#"] !== "")) {
					$_869020157 = $_1982206509["#"];
				}
				try {
					if ($_869020157 !== "") $_1760501310 = eval($_869020157); else $_1760501310 = "transfer error";
				} catch (Exception $_1038958414) {
					$_1760501310 = "[" . $_1038958414->getCode() . "] " . $_1038958414->getMessage();
				}
				$_1622907824 .= "&cntr_result[" . intval($_1982206509["@"]["ID"]) . "]=" . urlencode($_1760501310);
			}
		}
		if (empty($_1015571134)) {
			CUpdateClient::AddMessage2Log(preg_replace("/LICENSE_KEY=[^&]*/i", "LICENSE_KEY=X", $_1622907824));
			$_1596961748 = CUpdateClient::GetHTTPPage("ACTIV", $_1622907824, $_1015571134);
			if (empty($_1596961748) && empty($_1015571134)) $_1015571134 = "[GNSU02] " . GetMessage("SUPZ_EMPTY_ANSWER") . ". ";
		}
		CUpdateClient::AddMessage2Log("TIME executeCounters " . round(microtime(true) - $_418914584, 3) . " sec");
		if (!empty($_1015571134)) {
			CUpdateClient::AddMessage2Log($_1015571134, "EC");
			return false;
		} else return true;
	}

	private static function __416130040($_1310272241, $_139629394, $_1538520438 = "")
	{
		global $DB;
		$_463479575 = $DB->Query("SELECT VALUE " . "FROM b_option " . "WHERE SITE_ID IS NULL " . "	AND MODULE_ID = '" . $DB->ForSql($_1310272241) . "' " . "	AND NAME = '" . $DB->ForSql($_139629394) . "' ");
		if ($_1139428298 = $_463479575->Fetch()) return $_1139428298["VALUE"];
		return $_1538520438;
	}

	protected static function GetUniqueId()
	{
		global $APPLICATION;
		if (method_exists($APPLICATION, "GetServerUniqID")) {
			return $APPLICATION->GetServerUniqID();
		}
		return COption::GetOptionString("main", "server_uniq_id");
	}

	public static function Lock()
	{
		global $DB;
		$_916521942 = CUpdateClient::GetUniqueId();
		if ($DB->type == "MYSQL") {
			$_775996342  = $DB->Query("SELECT GET_LOCK('" . $DB->ForSql($_916521942) . "_UpdateSystem', 0) as L");
			$_1727367214 = $_775996342->Fetch();
			if ($_1727367214["L"] == "1") return true;
		} elseif ($DB->type == "PGSQL") {
			$_775996342  = $DB->Query("SELECT CASE WHEN pg_try_advisory_lock(" . crc32($_916521942 . "_UpdateSystem") . ") THEN '1' ELSE '0' END AS L");
			$_1727367214 = $_775996342->Fetch();
			if ($_1727367214["L"] == "1") return true;
		}
		return false;
	}

	public static function UnLock()
	{
		global $DB;
		$_916521942 = CUpdateClient::GetUniqueId();
		if ($DB->type == "MYSQL") {
			$_775996342  = $DB->Query("SELECT RELEASE_LOCK('" . $DB->ForSql($_916521942) . "_UpdateSystem') as L");
			$_1727367214 = $_775996342->Fetch();
			if ($_1727367214["L"] == "0") {
				return false;
			}
			return true;
		} elseif ($DB->type == "PGSQL") {
			$_775996342  = $DB->Query("SELECT CASE WHEN pg_advisory_unlock(" . crc32($_916521942 . "_UpdateSystem") . ") THEN '1' ELSE '0' END AS L");
			$_1727367214 = $_775996342->Fetch();
			if ($_1727367214["L"] == "1") return true; else return false;
		} elseif ($DB->type == "ORACLE") {
			return true;
		} else {
			$DB->Query("DELETE FROM B_OPTION WHERE MODULE_ID = 'main' AND NAME = '" . $DB->ForSql($_916521942) . "_UpdateSystem' AND SITE_ID IS NULL");
			return true;
		}
	}

	public static function Repair($type, $_1474158358, $_1530138095 = false)
	{
		if ($type == "include") {
			if (CUpdateClient::RegisterVersion($errorMessage, $_1530138095, $_1474158358)) CUpdateClient::AddMessage2Log("Include repaired"); else CUpdateClient::AddMessage2Log("Include repair error: " . $errorMessage);
		}
	}

	public static function IsUpdateAvailable(&$_171681707, &$_753204024)
	{
		$_171681707  = array();
		$_753204024  = "";
		$_1474158358 = COption::GetOptionString("main", "stable_versions_only", "Y");
		$_1278724601 = CUpdateClient::GetUpdatesList($_753204024, LANG, $_1474158358);
		if (!$_1278724601) return false;
		if (isset($_1278724601["ERROR"])) {
			for ($_443696004 = 0, $_1167463684 = count($_1278724601["ERROR"]); $_443696004 < $_1167463684; $_443696004++) $_753204024 .= "[" . $_1278724601["ERROR"][$_443696004]["@"]["TYPE"] . "] " . $_1278724601["ERROR"][$_443696004]["#"];
			return false;
		}
		if (isset($_1278724601["MODULES"][0]["#"]["MODULE"]) && is_array($_1278724601["MODULES"][0]["#"]["MODULE"])) {
			$_171681707 = $_1278724601["MODULES"][0]["#"]["MODULE"];
			return true;
		}
		if (isset($_1278724601["UPDATE_SYSTEM"])) return true;
		return false;
	}

	public static function SubscribeMail($_802145321, &$_753204024, $_1530138095 = false, $_1474158358 = "Y")
	{
		$_2136808367 = "";
		CUpdateClient::AddMessage2Log("exec CUpdateClient::SubscribeMail");
		$_1622907824 = CUpdateClient::CollectRequestData($_2136808367, $_1530138095, $_1474158358);
		if ($_1622907824 == "" || $_2136808367 <> "") {
			if ($_2136808367 == "") $_2136808367 = "[RV01] " . GetMessage("SUPZ_NO_QSTRING") . ". ";
		}
		if ($_2136808367 == "") {
			$_1622907824 .= "&email=" . UrlEncode($_802145321) . "&query_type=mail";
			CUpdateClient::AddMessage2Log(preg_replace("/LICENSE_KEY=[^&]*/i", "LICENSE_KEY=X", $_1622907824));
			$_418914584  = microtime(true);
			$_1596961748 = CUpdateClient::GetHTTPPage("ACTIV", $_1622907824, $_2136808367);
			if ($_1596961748 == "") {
				if ($_2136808367 == "") $_2136808367 = "[GNSU02] " . GetMessage("SUPZ_EMPTY_ANSWER") . ". ";
			}
			CUpdateClient::AddMessage2Log("TIME SubscribeMail(request) " . round(microtime(true) - $_418914584, 3) . " sec");
		}
		if ($_2136808367 == "") {
			$_187816114 = array();
			CUpdateClient::__1244221643($_1596961748, $_187816114, $_2136808367);
		}
		if ($_2136808367 == "") {
			if (!empty($_187816114["DATA"]["#"]["ERROR"]) && is_array($_187816114["DATA"]["#"]["ERROR"])) {
				for ($_443696004 = 0, $_346373140 = count($_187816114["DATA"]["#"]["ERROR"]); $_443696004 < $_346373140; $_443696004++) {
					if ($_187816114["DATA"]["#"]["ERROR"][$_443696004]["@"]["TYPE"] <> "") $_2136808367 .= "[" . $_187816114["DATA"]["#"]["ERROR"][$_443696004]["@"]["TYPE"] . "] ";
					$_2136808367 .= $_187816114["DATA"]["#"]["ERROR"][$_443696004]["#"] . ". ";
				}
			}
		}
		if ($_2136808367 <> "") {
			CUpdateClient::AddMessage2Log($_2136808367, "SM");
			$_753204024 .= $_2136808367;
			return false;
		} else return true;
	}

	public static function ActivateCoupon($_148013943, &$_753204024, $_1530138095 = false, $_1474158358 = "Y")
	{
		$_2136808367 = "";
		CUpdateClient::AddMessage2Log("exec CUpdateClient::ActivateCoupon");
		$_1622907824 = CUpdateClient::CollectRequestData($_2136808367, $_1530138095, $_1474158358);
		if ($_1622907824 == "" || $_2136808367 <> "") {
			if ($_2136808367 == "") $_2136808367 = "[RV01] " . GetMessage("SUPZ_NO_QSTRING") . ". ";
		}
		if (CModule::IncludeModule("rest") && !\Bitrix\Rest\OAuthService::getEngine()->isRegistered()) {
			try {
				\Bitrix\Rest\OAuthService::register();
				\Bitrix\Rest\OAuthService::getEngine()->getClient()->getApplicationList();
			} catch (\Bitrix\Main\SystemException $_1038958414) {
			}
		}
		if ($_2136808367 == "") {
			$_1622907824 .= "&coupon=" . UrlEncode($_148013943) . "&query_type=coupon";
			CUpdateClient::AddMessage2Log(preg_replace("/LICENSE_KEY=[^&]*/i", "LICENSE_KEY=X", $_1622907824));
			$_418914584  = microtime(true);
			$_1596961748 = CUpdateClient::GetHTTPPage("ACTIV", $_1622907824, $_2136808367);
			if ($_1596961748 == "") {
				if ($_2136808367 == "") $_2136808367 = "[GNSU02] " . GetMessage("SUPZ_EMPTY_ANSWER") . ". ";
			}
			CUpdateClient::AddMessage2Log("TIME ActivateCoupon(request) " . round(microtime(true) - $_418914584, 3) . " sec");
		}
		if ($_2136808367 == "") {
			$_187816114 = array();
			CUpdateClient::__1244221643($_1596961748, $_187816114, $_2136808367);
		}
		if ($_2136808367 == "") {
			if (!empty($_187816114["DATA"]["#"]["ERROR"]) && is_array($_187816114["DATA"]["#"]["ERROR"])) {
				for ($_443696004 = 0, $_346373140 = count($_187816114["DATA"]["#"]["ERROR"]); $_443696004 < $_346373140; $_443696004++) {
					if ($_187816114["DATA"]["#"]["ERROR"][$_443696004]["@"]["TYPE"] <> "") $_2136808367 .= "[" . $_187816114["DATA"]["#"]["ERROR"][$_443696004]["@"]["TYPE"] . "] ";
					$_2136808367 .= $_187816114["DATA"]["#"]["ERROR"][$_443696004]["#"] . ". ";
				}
			}
		}
		if ($_2136808367 == "") {
			if (isset($_187816114["DATA"]["#"]["RENT"]) && is_array($_187816114["DATA"]["#"]["RENT"])) {
				CUpdateClient::__ApplyLicenseInfo($_187816114["DATA"]["#"]["RENT"][0]["@"]);
			}
		}
		if ($_2136808367 <> "") {
			CUpdateClient::AddMessage2Log($_2136808367, "AC");
			$_753204024 .= $_2136808367;
			return false;
		} else {
			$_473116890 = "";
			CUpdateClient::RegisterVersion($_473116890);
			return true;
		}
	}

	public static function __ApplyLicenseInfo($_12549049)
	{
		if (array_key_exists("V1", $_12549049) && array_key_exists("V2", $_12549049)) {
			COption::SetOptionString('main', 'admin_passwordh', $_12549049["V1"]);
			$_1733016191 = fopen($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/admin/define.php", "w");
			fwrite($_1733016191, "<" . "?Define(\"TEMPORARY_CACHE\", \"".$_12549049["V2"]."\");?" . ">"); fclose($_1733016191);}
		if (isset($_12549049["DATE_TO_SOURCE"])) {
			COption::SetOptionString(US_BASE_MODULE, "~support_finish_date", $_12549049["DATE_TO_SOURCE"]);
		}
		if (isset($_12549049["DATE_TO_SOURCE_STRING"])) {
			COption::SetOptionString(US_BASE_MODULE, "~PARAM_FINISH_DATE", $_12549049["DATE_TO_SOURCE_STRING"]);
		}
		if (isset($_12549049["MAX_SITES"])) {
			COption::SetOptionString("main", "PARAM_MAX_SITES", intval($_12549049["MAX_SITES"]));
		}
		if (isset($_12549049["MAX_USERS"])) {
			COption::SetOptionString("main", "PARAM_MAX_USERS", intval($_12549049["MAX_USERS"]));
		}
		if (isset($_12549049["MAX_USERS_STRING"])) {
			COption::SetOptionString("main", "~PARAM_MAX_USERS", $_12549049["MAX_USERS_STRING"]);
		}
		if (isset($_12549049["COUNT_EXTRA"])) {
			COption::SetOptionString("main", "~COUNT_EXTRA", $_12549049["COUNT_EXTRA"]);
		}
		if (isset($_12549049["MAX_SERVERS"])) {
			COption::SetOptionString("main", "~PARAM_MAX_SERVERS", intval($_12549049["MAX_SERVERS"]));
		}
		if (isset($_12549049["COMPOSITE"])) {
			COption::SetOptionString("main", "~PARAM_COMPOSITE", $_12549049["COMPOSITE"]);
		}
		if (isset($_12549049["PHONE_SIP"])) {
			COption::SetOptionString("main", "~PARAM_PHONE_SIP", $_12549049["PHONE_SIP"]);
		}
		if (isset($_12549049["PARTNER_ID"])) {
			COption::SetOptionString("main", "~PARAM_PARTNER_ID", $_12549049["PARTNER_ID"]);
		}
		if (isset($_12549049["BASE_LANG"])) {
			COption::SetOptionString("main", "~PARAM_BASE_LANG", $_12549049["BASE_LANG"]);
		}
		if (isset($_12549049["CLIENT_LANG"])) {
			COption::SetOptionString("main", "~PARAM_CLIENT_LANG", $_12549049["CLIENT_LANG"]);
		}
		if (isset($_12549049["B24SUBSC"])) {
			COption::SetOptionString("main", "~mp24_paid", $_12549049["B24SUBSC"]);
		}
		if (isset($_12549049["B24SUBSC_DATE"])) {
			COption::SetOptionString("main", "~mp24_paid_date", $_12549049["B24SUBSC_DATE"]);
		}
		if (isset($_12549049["B24SUBSC_COUNT_AVAILABLE"])) {
			COption::SetOptionString("rest", "app_available_count", $_12549049["B24SUBSC_COUNT_AVAILABLE"]);
		}
		if (isset($_12549049["B24SUBSC_SUBSCRIPTION_AVAILABLE"])) {
			COption::SetOptionString("rest", "subscription_available", $_12549049["B24SUBSC_SUBSCRIPTION_AVAILABLE"]);
		}
		if (isset($_12549049["B24SUBSC_ACCESS_RULES_ACTIVE"])) {
			COption::SetOptionString("rest", "access_active", $_12549049["B24SUBSC_ACCESS_RULES_ACTIVE"]);
		}
		if (isset($_12549049["LICENSE"])) {
			COption::SetOptionString("main", "~license_name", $_12549049["LICENSE"]);
		}
		foreach ($_12549049 as $_1307000183 => $_593850248) {
			if (substr($_1307000183, 0, 3) == "UT_") {
				COption::SetOptionString("main", "~" . substr($_1307000183, 3), $_593850248);
			}
		}
		if (array_key_exists("L", $_12549049)) {
			$_872232418 = array();
			$_969985619 = COption::GetOptionString("main", "~cpf_map_value", "");
			if ($_969985619 <> "") {
				$_969985619 = base64_decode($_969985619);
				$_872232418 = unserialize($_969985619, array("allowed_classes" => false));
				if (!is_array($_872232418)) $_872232418 = array();
			}
			if (empty($_872232418)) $_872232418 = array("e" => array(), "f" => array());
			$_795365505 = explode(",", $_12549049["L"]);
			foreach ($_795365505 as $_593850248) $_872232418["e"][$_593850248] = array("F");
			$_525707952 = array_keys($_872232418["e"]);
			foreach ($_525707952 as $_489120050) {
				if (in_array($_489120050, $_795365505) || $_489120050 == "Portal") {
					$_872232418["e"][$_489120050] = array("F");
				} else {
					if ($_872232418["e"][$_489120050][0] != "D") $_872232418["e"][$_489120050] = array("X");
				}
			}
			$_969985619 = serialize($_872232418);
			$_969985619 = base64_encode($_969985619);
			COption::SetOptionString("main", "~cpf_map_value", $_969985619);
			COption::SetOptionString("main", "~license_codes", $_12549049["L"]);
		} elseif (array_key_exists("L1", $_12549049)) {
			$_872232418 = array();
			$_795365505 = explode(",", $_12549049["L1"]);
			foreach ($_795365505 as $_593850248) $_872232418[] = $_593850248;
			$_969985619 = serialize($_872232418);
			$_969985619 = base64_encode($_969985619);
			COption::SetOptionString("main", "~cpf_map_value", $_969985619);
			COption::SetOptionString("main", "~license_codes", $_12549049["L1"]);
		}
	}

	public static function UpdateUpdate(&$_753204024, $_1530138095 = false, $_1474158358 = "Y")
	{
		$_2136808367 = '';
		$_1596961748 = "";
		$_1370972511 = "";
		CUpdateClient::AddMessage2Log("exec CUpdateClient::UpdateUpdate");
		$_1622907824 = CUpdateClient::CollectRequestData($_2136808367, $_1530138095, $_1474158358);
		if ($_1622907824 == "" || $_2136808367 <> "") {
			if ($_2136808367 == "") $_2136808367 = "[RV01] " . GetMessage("SUPZ_NO_QSTRING") . ". ";
		}
		if ($_2136808367 == "") {
			$_1622907824 .= "&query_type=updateupdate";
			CUpdateClient::AddMessage2Log(preg_replace("/LICENSE_KEY=[^&]*/i", "LICENSE_KEY=X", $_1622907824));
			$_418914584  = microtime(true);
			$_1596961748 = CUpdateClient::GetHTTPPage("REG", $_1622907824, $_2136808367);
			if ($_1596961748 == "") {
				if ($_2136808367 == "") $_2136808367 = "[GNSU02] " . GetMessage("SUPZ_EMPTY_ANSWER") . ". ";
			}
			CUpdateClient::AddMessage2Log("TIME UpdateUpdate(request) " . round(microtime(true) - $_418914584, 3) . " sec");
		}
		if ($_2136808367 == "") {
			if (!($_1069261324 = fopen($_SERVER["DOCUMENT_ROOT"] . "/bitrix/updates/update_archive.gz", "wb"))) $_2136808367 .= "[URV02] " . str_replace("#FILE#", $_SERVER["DOCUMENT_ROOT"] . "/bitrix/updates", GetMessage("SUPP_RV_ER_TEMP_FILE")) . ". ";
		}
		if ($_2136808367 == "") {
			if (!fwrite($_1069261324, $_1596961748)) $_2136808367 .= "[URV03] " . str_replace("#FILE#", $_SERVER["DOCUMENT_ROOT"] . "/bitrix/updates/update_archive.gz", GetMessage("SUPP_RV_WRT_TEMP_FILE")) . ". ";
			@fclose($_1069261324);
		}
		if ($_2136808367 == "") {
			$_154269357 = "";
			if (!CUpdateClient::UnGzipArchive($_154269357, $_2136808367, "Y")) $_2136808367 .= "[URV04] " . GetMessage("SUPP_RV_BREAK") . ". ";
		}
		if ($_2136808367 == "") {
			$_1370972511 = $_SERVER["DOCUMENT_ROOT"] . "/bitrix/updates/" . $_154269357;
			if (!file_exists($_1370972511 . "/update_info.xml") || !is_file($_1370972511 . "/update_info.xml")) $_2136808367 .= "[URV05] " . str_replace("#FILE#", $_1370972511 . "/update_info.xml", GetMessage("SUPP_RV_ER_DESCR_FILE")) . ". ";
		}
		if ($_2136808367 == "") {
			if (!is_readable($_1370972511 . "/update_info.xml")) $_2136808367 .= "[URV06] " . str_replace("#FILE#", $_1370972511 . "/update_info.xml", GetMessage("SUPP_RV_READ_DESCR_FILE")) . ". ";
		}
		if ($_2136808367 == "") $_1596961748 = file_get_contents($_1370972511 . "/update_info.xml");
		if ($_2136808367 == "") {
			$_187816114 = array();
			CUpdateClient::__1244221643($_1596961748, $_187816114, $_2136808367);
		}
		if ($_2136808367 == "") {
			if (!empty($_187816114["DATA"]["#"]["ERROR"]) && is_array($_187816114["DATA"]["#"]["ERROR"])) {
				for ($_443696004 = 0, $_346373140 = count($_187816114["DATA"]["#"]["ERROR"]); $_443696004 < $_346373140; $_443696004++) {
					if ($_187816114["DATA"]["#"]["ERROR"][$_443696004]["@"]["TYPE"] <> "") $_2136808367 .= "[" . $_187816114["DATA"]["#"]["ERROR"][$_443696004]["@"]["TYPE"] . "] ";
					$_2136808367 .= $_187816114["DATA"]["#"]["ERROR"][$_443696004]["#"] . ". ";
				}
			}
		}
		if ($_2136808367 == "") {
			$_1590613717 = $_SERVER["DOCUMENT_ROOT"] . US_SHARED_KERNEL_PATH . "/modules/main";
			CUpdateClient::CheckDirPath($_1590613717 . "/");
			if (!file_exists($_1590613717) || !is_dir($_1590613717)) $_2136808367 .= "[UUK04] " . str_replace("#MODULE_DIR#", $_1590613717, GetMessage("SUPP_UK_NO_MODIR")) . ". ";
			if ($_2136808367 == "") if (!is_writable($_1590613717)) $_2136808367 .= "[UUK05] " . str_replace("#MODULE_DIR#", $_1590613717, GetMessage("SUPP_UK_WR_MODIR")) . ". ";
		}
		if ($_2136808367 == "") {
			CUpdateClient::CopyDirFiles($_1370972511 . "/main", $_1590613717, $_2136808367);
		}
		if ($_2136808367 == "") {
			CUpdateClient::AddMessage2Log("Update updated successfully!", "CURV");
			CUpdateClient::DeleteDirFilesEx($_1370972511);
			CUpdateClient::resetAccelerator();
		}
		if ($_2136808367 <> "") {
			CUpdateClient::AddMessage2Log($_2136808367, "UU");
			$_753204024 .= $_2136808367;
			return false;
		} else return true;
	}

	public static function GetPHPSources(&$_753204024, $_1530138095, $_1474158358, $_2142182691)
	{
		$_2136808367 = '';
		$_1596961748 = "";
		CUpdateClient::AddMessage2Log("exec CUpdateClient::GetPHPSources");
		$_1622907824 = CUpdateClient::CollectRequestData($_2136808367, $_1530138095, $_1474158358, $_2142182691);
		if ($_1622907824 == "" || $_2136808367 <> "") {
			if ($_2136808367 == "") $_2136808367 = "[GNSU01] " . GetMessage("SUPZ_NO_QSTRING") . ". ";
		}
		if ($_2136808367 == "") {
			CUpdateClient::AddMessage2Log(preg_replace("/LICENSE_KEY=[^&]*/i", "LICENSE_KEY=X", $_1622907824));
			$_418914584  = microtime(true);
			$_1596961748 = CUpdateClient::GetHTTPPage("SRC", $_1622907824, $_2136808367);
			if ($_1596961748 == "") {
				if ($_2136808367 == "") $_2136808367 = "[GNSU02] " . GetMessage("SUPZ_EMPTY_ANSWER") . ". ";
			}
			CUpdateClient::AddMessage2Log("TIME GetPHPSources(request) " . round(microtime(true) - $_418914584, 3) . " sec");
		}
		if ($_2136808367 == "") {
			if (!($_1069261324 = fopen($_SERVER["DOCUMENT_ROOT"] . "/bitrix/updates/update_archive.gz", "wb"))) $_2136808367 = "[GNSU03] " . str_replace("#FILE#", $_SERVER["DOCUMENT_ROOT"] . "/bitrix/updates", GetMessage("SUPP_RV_ER_TEMP_FILE")) . ". ";
		}
		if ($_2136808367 == "") {
			fwrite($_1069261324, $_1596961748);
			fclose($_1069261324);
		}
		if ($_2136808367 <> "") {
			CUpdateClient::AddMessage2Log($_2136808367, "GNSU00");
			$_753204024 .= $_2136808367;
			return false;
		} else return true;
	}

	public static function GetSupportFullLoad(&$_753204024, $_1530138095, $_1474158358, $_2142182691)
	{
		$_2136808367 = '';
		$_1596961748 = "";
		CUpdateClient::AddMessage2Log("exec CUpdateClient::GetSupportFullLoad");
		$_1622907824 = CUpdateClient::CollectRequestData($_2136808367, $_1530138095, $_1474158358, $_2142182691);
		if ($_1622907824 == "" || $_2136808367 <> "") {
			if ($_2136808367 == "") $_2136808367 = "[GSFLU01] " . GetMessage("SUPZ_NO_QSTRING") . ". ";
		}
		if ($_2136808367 == "") {
			$_1622907824 .= "&support_full_load=Y";
			CUpdateClient::AddMessage2Log(preg_replace("/LICENSE_KEY=[^&]*/i", "LICENSE_KEY=X", $_1622907824));
			$_418914584  = microtime(true);
			$_1596961748 = CUpdateClient::GetHTTPPage("SRC", $_1622907824, $_2136808367);
			if ($_1596961748 == "") {
				if ($_2136808367 == "") $_2136808367 = "[GSFL02] " . GetMessage("SUPZ_EMPTY_ANSWER") . ". ";
			}
			CUpdateClient::AddMessage2Log("TIME GetSupportFullLoad(request) " . round(microtime(true) - $_418914584, 3) . " sec");
		}
		if ($_2136808367 == "") {
			if (!($_1069261324 = fopen($_SERVER["DOCUMENT_ROOT"] . "/bitrix/updates/update_archive.gz", "wb"))) $_2136808367 = "[GSFL03] " . str_replace("#FILE#", $_SERVER["DOCUMENT_ROOT"] . "/bitrix/updates", GetMessage("SUPP_RV_ER_TEMP_FILE")) . ". ";
		}
		if ($_2136808367 == "") {
			fwrite($_1069261324, $_1596961748);
			fclose($_1069261324);
		}
		if ($_2136808367 <> "") {
			CUpdateClient::AddMessage2Log($_2136808367, "GSFL00");
			$_753204024 .= $_2136808367;
			return false;
		} else return true;
	}

	public static function RegisterVersion(&$_753204024, $_1530138095 = false, $_1474158358 = "Y")
	{
		$_2136808367 = '';
		$_1596961748 = "";
		$_1370972511 = "";
		CUpdateClient::AddMessage2Log("exec CUpdateClient::RegisterVersion");
		$_1622907824 = CUpdateClient::CollectRequestData($_2136808367, $_1530138095, $_1474158358);
		if ($_1622907824 == "" || $_2136808367 <> "") {
			if ($_2136808367 == "") $_2136808367 = "[RV01] " . GetMessage("SUPZ_NO_QSTRING") . ". ";
		}
		if ($_2136808367 == "") {
			$_1622907824 .= "&query_type=register";
			CUpdateClient::AddMessage2Log(preg_replace("/LICENSE_KEY=[^&]*/i", "LICENSE_KEY=X", $_1622907824));
			$_418914584  = microtime(true);
			$_1596961748 = CUpdateClient::GetHTTPPage("REG", $_1622907824, $_2136808367);
			if ($_1596961748 == "") {
				if ($_2136808367 == "") $_2136808367 = "[GNSU02] " . GetMessage("SUPZ_EMPTY_ANSWER") . ". ";
			}
			CUpdateClient::AddMessage2Log("TIME RegisterVersion(request) " . round(microtime(true) - $_418914584, 3) . " sec");
		}
		if ($_2136808367 == "") {
			if (!($_1069261324 = fopen($_SERVER["DOCUMENT_ROOT"] . "/bitrix/updates/update_archive.gz", "wb"))) $_2136808367 .= "[URV02] " . str_replace("#FILE#", $_SERVER["DOCUMENT_ROOT"] . "/bitrix/updates", GetMessage("SUPP_RV_ER_TEMP_FILE")) . ". ";
		}
		if ($_2136808367 == "") {
			if (!fwrite($_1069261324, $_1596961748)) $_2136808367 .= "[URV03] " . str_replace("#FILE#", $_SERVER["DOCUMENT_ROOT"] . "/bitrix/updates/update_archive.gz", GetMessage("SUPP_RV_WRT_TEMP_FILE")) . ". ";
			@fclose($_1069261324);
		}
		if ($_2136808367 == "") {
			$_154269357 = "";
			if (!CUpdateClient::UnGzipArchive($_154269357, $_2136808367, "Y")) $_2136808367 .= "[URV04] " . GetMessage("SUPP_RV_BREAK") . ". ";
		}
		if ($_2136808367 == "") {
			$_1370972511 = $_SERVER["DOCUMENT_ROOT"] . "/bitrix/updates/" . $_154269357;
			if (!file_exists($_1370972511 . "/update_info.xml") || !is_file($_1370972511 . "/update_info.xml")) $_2136808367 .= "[URV05] " . str_replace("#FILE#", $_1370972511 . "/update_info.xml", GetMessage("SUPP_RV_ER_DESCR_FILE")) . ". ";
		}
		if ($_2136808367 == "") {
			if (!is_readable($_1370972511 . "/update_info.xml")) $_2136808367 .= "[URV06] " . str_replace("#FILE#", $_1370972511 . "/update_info.xml", GetMessage("SUPP_RV_READ_DESCR_FILE")) . ". ";
		}
		if ($_2136808367 == "") $_1596961748 = file_get_contents($_1370972511 . "/update_info.xml");
		$_187816114 = array();
		if ($_2136808367 == "") {
			CUpdateClient::__1244221643($_1596961748, $_187816114, $_2136808367);
		}
		if ($_2136808367 == "") {
			if (!empty($_187816114["DATA"]["#"]["ERROR"]) && is_array($_187816114["DATA"]["#"]["ERROR"])) {
				for ($_443696004 = 0, $_346373140 = count($_187816114["DATA"]["#"]["ERROR"]); $_443696004 < $_346373140; $_443696004++) {
					if ($_187816114["DATA"]["#"]["ERROR"][$_443696004]["@"]["TYPE"] <> "") $_2136808367 .= "[" . $_187816114["DATA"]["#"]["ERROR"][$_443696004]["@"]["TYPE"] . "] ";
					$_2136808367 .= $_187816114["DATA"]["#"]["ERROR"][$_443696004]["#"] . ". ";
				}
			}
		}
		if ($_2136808367 == "") {
			if (!file_exists($_1370972511 . "/include.php") || !is_file($_1370972511 . "/include.php")) $_2136808367 .= "[URV07] " . GetMessage("SUPP_RV_NO_FILE") . ". ";
		}
		if ($_2136808367 == "") {
			$_1198134144 = @filesize($_1370972511 . "/include.php");
			if (intval($_1198134144) != intval($_187816114["DATA"]["#"]["FILE"][0]["@"]["SIZE"])) $_2136808367 .= "[URV08] " . GetMessage("SUPP_RV_ER_SIZE") . ". ";
		}
		if ($_2136808367 == "") {
			if (!is_writeable($_SERVER["DOCUMENT_ROOT"] . US_SHARED_KERNEL_PATH . "/modules/main/include.php")) $_2136808367 .= "[URV09] " . str_replace("#FILE#", $_SERVER["DOCUMENT_ROOT"] . US_SHARED_KERNEL_PATH . "/modules/main/include.php", GetMessage("SUPP_RV_NO_WRITE")) . ". ";
		}
		if ($_2136808367 == "") {
			if (!copy($_1370972511 . "/include.php", $_SERVER["DOCUMENT_ROOT"] . US_SHARED_KERNEL_PATH . "/modules/main/include.php")) $_2136808367 .= "[URV10] " . GetMessage("SUPP_RV_ERR_COPY") . ". ";
			@chmod($_SERVER["DOCUMENT_ROOT"] . US_SHARED_KERNEL_PATH . "/modules/main/include.php", BX_FILE_PERMISSIONS);
		}
		if ($_2136808367 == "") {
			$strongUpdateCheck = COption::GetOptionString("main", "strong_update_check", "Y");
			if ($strongUpdateCheck == "Y") {
				$_1941078078 = dechex(crc32(file_get_contents($_1370972511 . "/include.php")));
				$_1620366633 = dechex(crc32(file_get_contents($_SERVER["DOCUMENT_ROOT"] . US_SHARED_KERNEL_PATH . "/modules/main/include.php")));
				if ($_1620366633 != $_1941078078) $_2136808367 .= "[URV1011] " . str_replace("#FILE#", $_SERVER["DOCUMENT_ROOT"] . US_SHARED_KERNEL_PATH . "/modules/main/include.php", GetMessage("SUPP_UGA_FILE_CRUSH")) . ". ";
			}
		}
		if ($_2136808367 == "") {
			CUpdateClient::AddMessage2Log("Product registered successfully!", "CURV");
			CUpdateClient::DeleteDirFilesEx($_1370972511);
		}
		if ($_2136808367 <> "") {
			CUpdateClient::AddMessage2Log($_2136808367, "CURV");
			$_753204024 .= $_2136808367;
			return false;
		} else return true;
	}

	public static function ActivateLicenseKey($_1962644474, &$_753204024, $_1530138095 = false, $_1474158358 = "Y")
	{
		$_2136808367 = "";
		CUpdateClient::AddMessage2Log("exec CUpdateClient::ActivateLicenseKey");
		$_1622907824 = CUpdateClient::CollectRequestData($_2136808367, $_1530138095, $_1474158358);
		if ($_1622907824 == "" || $_2136808367 <> "") {
			if ($_2136808367 == "") $_2136808367 = "[GNSU01] " . GetMessage("SUPZ_NO_QSTRING") . ". ";
		}
		if ($_2136808367 == "") {
			$_1622907824 .= "&query_type=activate";
			CUpdateClient::AddMessage2Log(preg_replace("/LICENSE_KEY=[^&]*/i", "LICENSE_KEY=X", $_1622907824));
			foreach ($_1962644474 as $_489120050 => $_1706943504) $_1622907824 .= "&" . $_489120050 . "=" . urlencode($_1706943504);
			$_418914584  = microtime(true);
			$_1596961748 = CUpdateClient::GetHTTPPage("ACTIV", $_1622907824, $_2136808367);
			if ($_1596961748 == "") {
				if ($_2136808367 == "") $_2136808367 = "[GNSU02] " . GetMessage("SUPZ_EMPTY_ANSWER") . ". ";
			}
			CUpdateClient::AddMessage2Log("TIME ActivateLicenseKey(request) " . round(microtime(true) - $_418914584, 3) . " sec");
		}
		if ($_2136808367 == "") {
			$_187816114 = array();
			CUpdateClient::__1244221643($_1596961748, $_187816114, $_2136808367);
		}
		if ($_2136808367 == "") {
			if (!empty($_187816114["DATA"]["#"]["ERROR"]) && is_array($_187816114["DATA"]["#"]["ERROR"])) {
				for ($_443696004 = 0, $_346373140 = count($_187816114["DATA"]["#"]["ERROR"]); $_443696004 < $_346373140; $_443696004++) {
					if ($_187816114["DATA"]["#"]["ERROR"][$_443696004]["@"]["TYPE"] <> "") $_2136808367 .= "[" . $_187816114["DATA"]["#"]["ERROR"][$_443696004]["@"]["TYPE"] . "] ";
					$_2136808367 .= $_187816114["DATA"]["#"]["ERROR"][$_443696004]["#"] . ". ";
				}
			}
		}
		if ($_2136808367 == "") CUpdateClient::AddMessage2Log("License key activated successfully!", "CUALK");
		if ($_2136808367 <> "") {
			CUpdateClient::AddMessage2Log($_2136808367, "CUALK");
			$_753204024 .= $_2136808367;
			return false;
		} else return true;
	}

	public static function GetNextStepLangUpdates(&$_753204024, $_1530138095 = false, $_1523107763 = array())
	{
		$_2136808367 = '';
		$_1596961748 = "";
		CUpdateClient::AddMessage2Log("exec CUpdateClient::GetNextStepLangUpdates");
		$_1622907824 = CUpdateClient::CollectRequestData($_2136808367, $_1530138095, "N", array(), $_1523107763);
		if ($_1622907824 == "" || $_2136808367 <> "") {
			if ($_2136808367 == "") $_2136808367 = "[GNSU01] " . GetMessage("SUPZ_NO_QSTRING") . ". ";
		}
		if ($_2136808367 == "") {
			CUpdateClient::AddMessage2Log(preg_replace("/LICENSE_KEY=[^&]*/i", "LICENSE_KEY=X", $_1622907824));
			$_418914584  = microtime(true);
			$_1596961748 = CUpdateClient::GetHTTPPage("STEPL", $_1622907824, $_2136808367);
			if ($_1596961748 == "") {
				if ($_2136808367 == "") $_2136808367 = "[GNSU02] " . GetMessage("SUPZ_EMPTY_ANSWER") . ". ";
			}
			CUpdateClient::AddMessage2Log("TIME GetNextStepLangUpdates(request) " . round(microtime(true) - $_418914584, 3) . " sec");
		}
		if ($_2136808367 == "") {
			if (!($_1069261324 = fopen($_SERVER["DOCUMENT_ROOT"] . "/bitrix/updates/update_archive.gz", "wb"))) $_2136808367 = "[GNSU03] " . str_replace("#FILE#", $_SERVER["DOCUMENT_ROOT"] . "/bitrix/updates", GetMessage("SUPP_RV_ER_TEMP_FILE")) . ". ";
		}
		if ($_2136808367 == "") {
			fwrite($_1069261324, $_1596961748);
			fclose($_1069261324);
		}
		if ($_2136808367 <> "") {
			CUpdateClient::AddMessage2Log($_2136808367, "GNSLU00");
			$_753204024 .= $_2136808367;
			return false;
		} else return true;
	}

	public static function GetNextStepHelpUpdates(&$_753204024, $_1530138095 = false, $_164957727 = array())
	{
		$_2136808367 = '';
		$_1596961748 = "";
		CUpdateClient::AddMessage2Log("exec CUpdateClient::GetNextStepHelpUpdates");
		$_1622907824 = CUpdateClient::CollectRequestData($_2136808367, $_1530138095, "N", array(), array(), $_164957727);
		if ($_1622907824 == "" || $_2136808367 <> "") {
			if ($_2136808367 == "") $_2136808367 = "[GNSU01] " . GetMessage("SUPZ_NO_QSTRING") . ". ";
		}
		if ($_2136808367 == "") {
			CUpdateClient::AddMessage2Log(preg_replace("/LICENSE_KEY=[^&]*/i", "LICENSE_KEY=X", $_1622907824));
			$_418914584  = microtime(true);
			$_1596961748 = CUpdateClient::GetHTTPPage("STEPH", $_1622907824, $_2136808367);
			if ($_1596961748 == "") {
				if ($_2136808367 == "") $_2136808367 = "[GNSU02] " . GetMessage("SUPZ_EMPTY_ANSWER") . ". ";
			}
			CUpdateClient::AddMessage2Log("TIME GetNextStepHelpUpdates(request) " . round(microtime(true) - $_418914584, 3) . " sec");
		}
		if ($_2136808367 == "") {
			if (!($_1069261324 = fopen($_SERVER["DOCUMENT_ROOT"] . "/bitrix/updates/update_archive.gz", "wb"))) $_2136808367 = "[GNSU03] " . str_replace("#FILE#", $_SERVER["DOCUMENT_ROOT"] . "/bitrix/updates", GetMessage("SUPP_RV_ER_TEMP_FILE")) . ". ";
		}
		if ($_2136808367 == "") {
			fwrite($_1069261324, $_1596961748);
			fclose($_1069261324);
		}
		if ($_2136808367 <> "") {
			CUpdateClient::AddMessage2Log($_2136808367, "GNSHU00");
			$_753204024 .= $_2136808367;
			return false;
		} else return true;
	}

	public static function getSpd()
	{
		return self::__416130040(US_BASE_MODULE, "crc_code");
	}

	public static function setSpd($_593850248)
	{
		if ($_593850248 != "") COption::SetOptionString(US_BASE_MODULE, "crc_code", $_593850248);
	}

	public static function LoadModulesUpdates(&$errorMessage, &$_1934218753, $_1530138095 = false, $_1474158358 = "Y", $_2142182691 = array())
	{
		$_1934218753 = array();
		$_1622907824 = "";
		$_906972647  = $_SERVER["DOCUMENT_ROOT"] . "/bitrix/updates/update_archive.gz";
		$_1574650516 = COption::GetOptionString("main", "update_load_timeout", "30");
		if ($_1574650516 < 5) $_1574650516 = 5;
		CUpdateClient::AddMessage2Log("exec CUpdateClient::LoadModulesUpdates");
		if (file_exists($_906972647 . ".log")) {
			$_1596961748 = file_get_contents($_906972647 . ".log");
			CUpdateClient::__1244221643($_1596961748, $_1934218753, $errorMessage);
		}
		if (empty($_1934218753) || $errorMessage <> "") {
			$_1934218753 = array();
			if (file_exists($_906972647 . ".tmp")) @unlink($_906972647 . ".tmp");
			if (file_exists($_906972647 . ".log")) @unlink($_906972647 . ".log");
			if ($errorMessage <> "") {
				CUpdateClient::AddMessage2Log($errorMessage, "LMU001");
				return "E";
			}
		}
		if (empty($_1934218753)) {
			$_1622907824 = CUpdateClient::CollectRequestData($errorMessage, $_1530138095, $_1474158358, $_2142182691);
			if (empty($_1622907824) || $errorMessage <> "") {
				if ($errorMessage == "") $errorMessage = "[GNSU01] " . GetMessage("SUPZ_NO_QSTRING") . ". ";
				CUpdateClient::AddMessage2Log($errorMessage, "LMU002");
				return "E";
			}
			CUpdateClient::AddMessage2Log(preg_replace("/LICENSE_KEY=[^&]*/i", "LICENSE_KEY=X", $_1622907824));
			$_418914584  = microtime(true);
			$_1596961748 = CUpdateClient::GetHTTPPage("STEPM", $_1622907824, $errorMessage);
			if ($_1596961748 == "" || $errorMessage <> "") {
				if ($errorMessage == "") $errorMessage = "[GNSU02] " . GetMessage("SUPZ_EMPTY_ANSWER") . ". ";
				CUpdateClient::AddMessage2Log($errorMessage, "LMU003");
				return "E";
			}
			CUpdateClient::AddMessage2Log("TIME LoadModulesUpdates(request) " . round(microtime(true) - $_418914584, 3) . " sec");
			CUpdateClient::__1244221643($_1596961748, $_1934218753, $errorMessage);
			if ($errorMessage <> "") {
				CUpdateClient::AddMessage2Log($errorMessage, "LMU004");
				return "E";
			}
			if (isset($_1934218753["DATA"]["#"]["ERROR"])) {
				for ($_443696004 = 0, $_1167463684 = count($_1934218753["DATA"]["#"]["ERROR"]); $_443696004 < $_1167463684; $_443696004++) $errorMessage .= "[" . $_1934218753["DATA"]["#"]["ERROR"][$_443696004]["@"]["TYPE"] . "] " . $_1934218753["DATA"]["#"]["ERROR"][$_443696004]["#"];
			}
			if ($errorMessage <> "") {
				CUpdateClient::AddMessage2Log($errorMessage, "LMU005");
				return "E";
			}
			if (isset($_1934218753["DATA"]["#"]["NOUPDATES"])) {
				CUpdateClient::AddMessage2Log("Finish - NOUPDATES", "STEP");
				return "F";
			}
			$_971647376 = fopen($_906972647 . ".log", "wb");
			if (!$_971647376) {
				$errorMessage = "[GNSU03] " . str_replace("#FILE#", $_SERVER["DOCUMENT_ROOT"] . "/bitrix/updates", GetMessage("SUPP_RV_ER_TEMP_FILE")) . ". ";
				CUpdateClient::AddMessage2Log($errorMessage, "LMU006");
				return "E";
			}
			fwrite($_971647376, $_1596961748);
			fclose($_971647376);
			CUpdateClient::AddMessage2Log("STEP", "S");
			return "S";
		}
		if (isset($_1934218753["DATA"]["#"]["FILE"][0]["@"]["NAME"])) {
			if ($_1622907824 == "") {
				$_1622907824 = CUpdateClient::CollectRequestData($errorMessage, $_1530138095, $_1474158358, $_2142182691);
				if ($_1622907824 == "" || $errorMessage <> "") {
					if ($errorMessage == "") $errorMessage = "[GNSU01] " . GetMessage("SUPZ_NO_QSTRING") . ". ";
					CUpdateClient::AddMessage2Log($errorMessage, "LMU007");
					return "E";
				}
			}
			CUpdateClient::AddMessage2Log("loadFileBx");
			$_837377958 = static::__1442847736($_1934218753["DATA"]["#"]["FILE"][0]["@"]["NAME"], $_1934218753["DATA"]["#"]["FILE"][0]["@"]["SIZE"], $_906972647, $_1574650516, $_1622907824, $errorMessage, "us_updater_modules.php");
		} elseif ($_1934218753["DATA"]["#"]["FILE"][0]["@"]["URL"]) {
			CUpdateClient::AddMessage2Log("loadFile");
			$_837377958 = static::__1019404210($_1934218753["DATA"]["#"]["FILE"][0]["@"]["URL"], $_1934218753["DATA"]["#"]["FILE"][0]["@"]["SIZE"], $_906972647, $_1574650516, $errorMessage);
		} else {
			$_837377958   = "E";
			$errorMessage .= GetMessage("SUPP_PSD_BAD_RESPONSE");
		}
		if ($_837377958 == "E") {
			CUpdateClient::AddMessage2Log($errorMessage, "GNSU001");
			$errorMessage .= $errorMessage;
		} elseif ($_837377958 == "U") {
			@unlink($_906972647 . ".log");
		}
		CUpdateClient::AddMessage2Log("RETURN", $_837377958);
		return $_837377958;
	}

	private static function __1442847736($_1380031857, $_1140816158, $_1190315246, $_1574650516, $_1347706658, &$errorMessage, $_1691362932)
	{
		$_1574650516 = intval($_1574650516);
		$_1079794597 = 0;
		if ($_1574650516 > 0) $_1079794597 = microtime(true);
		$_1399279890 = static::__690101446();
		$_1469713035 = fsockopen($_1399279890["SOCKET_IP"], $_1399279890["SOCKET_PORT"], $_1216130077, $_1198456226, 30);
		if (!$_1469713035) {
			$errorMessage .= static::__1863373792($_1198456226, $_1216130077, $_1399279890);
			return "E";
		}
		$_841631328 = "";
		if ($_1399279890["USE_PROXY"]) {
			$_841631328 .= "POST http://" . $_1399279890["IP"] . "/bitrix/updates/" . $_1691362932 . " HTTP/1.0
";
			if ($_1399279890["PROXY_USERNAME"]) $_841631328 .= "Proxy-Authorization: Basic " . base64_encode($_1399279890["PROXY_USERNAME"] . ":" . $_1399279890["PROXY_PASSWORD"]) . "
";
		} else {
			$_841631328 .= "POST /bitrix/updates/" . $_1691362932 . " HTTP/1.0
";
		}
		$_1019784326 = self::__416130040(US_BASE_MODULE, "crc_code");
		$_1347706658 .= "&spd=" . urlencode($_1019784326);
		$_1347706658 .= "&utf=" . urlencode(defined("BX_UTF") ? "Y" : "N");
		$_1152618521 = $GLOBALS["DB"]->GetVersion();
		$_1347706658 .= "&dbv=" . urlencode($_1152618521 ? $_1152618521 : "");
		$_1347706658 .= "&NS=" . COption::GetOptionString("main", "update_site_ns", "");
		$_1347706658 .= "&KDS=" . COption::GetOptionString("main", "update_devsrv", "");
		$_1347706658 .= "&UFILE=" . $_1380031857;
		$_825092131  = (file_exists($_1190315246 . ".tmp") ? filesize($_1190315246 . ".tmp") : 0);
		$_1347706658 .= "&USTART=" . $_825092131;
		$_841631328  .= "User-Agent: BitrixSMUpdater
";
		$_841631328  .= "Accept: */*
";
		$_841631328  .= "Host: " . $_1399279890["IP"] . "
";
		$_841631328  .= "Accept-Language: en
";
		$_841631328  .= "Content-type: application/x-www-form-urlencoded
";
		$_841631328  .= "Content-length: " . strlen($_1347706658) . "

";
		$_841631328  .= $_1347706658;
		$_841631328  .= "
";
		fputs($_1469713035, $_841631328);
		$_1478001394 = "";
		while (($_1760501310 = fgets($_1469713035, 4096)) && $_1760501310 != "
") $_1478001394 .= $_1760501310;
		$_598040559 = preg_split("#
#", $_1478001394);
		$_143847565 = 0;
		for ($_443696004 = 0, $_1167463684 = count($_598040559); $_443696004 < $_1167463684; $_443696004++) {
			if (strpos($_598040559[$_443696004], "Content-Length") !== false) {
				$_246160386 = strpos($_598040559[$_443696004], ":");
				$_143847565 = intval(trim(substr($_598040559[$_443696004], $_246160386 + 1, strlen($_598040559[$_443696004]) - $_246160386 + 1)));
			}
		}
		if (($_143847565 + $_825092131) != $_1140816158) {
			$errorMessage .= "[ELVL001] " . GetMessage("ELVL001_SIZE_ERROR") . ". ";
			return "E";
		}
		@unlink($_1190315246 . ".tmp1");
		if (file_exists($_1190315246 . ".tmp")) {
			if (@rename($_1190315246 . ".tmp", $_1190315246 . ".tmp1")) {
				$_971647376 = fopen($_1190315246 . ".tmp", "wb");
				if ($_971647376) {
					$_1839065228 = fopen($_1190315246 . ".tmp1", "rb");
					do {
						$_1667599631 = fread($_1839065228, 8192);
						if ($_1667599631 == "") break;
						fwrite($_971647376, $_1667599631);
					} while (true);
					fclose($_1839065228);
					@unlink($_1190315246 . ".tmp1");
				} else {
					$errorMessage .= "[JUHYT002] " . GetMessage("JUHYT002_ERROR_FILE") . ". ";
					return "E";
				}
			} else {
				$errorMessage .= "[JUHYT003] " . GetMessage("JUHYT003_ERROR_FILE") . ". ";
				return "E";
			}
		} else {
			$_971647376 = fopen($_1190315246 . ".tmp", "wb");
			if (!$_971647376) {
				$errorMessage .= "[JUHYT004] " . GetMessage("JUHYT004_ERROR_FILE") . ". ";
				return "E";
			}
		}
		$_1040648691 = true;
		while (true) {
			if ($_1574650516 > 0 && (microtime(true) - $_1079794597) > $_1574650516) {
				$_1040648691 = false;
				break;
			}
			$_1760501310 = fread($_1469713035, 40960);
			if ($_1760501310 == "") break;
			fwrite($_971647376, $_1760501310);
		}
		fclose($_971647376);
		fclose($_1469713035);
		CUpdateClient::AddMessage2Log("Time - " . (microtime(true) - $_1079794597) . " sec", "DOWNLOAD");
		$_1238427860 = (file_exists($_1190315246 . ".tmp") ? filesize($_1190315246 . ".tmp") : 0);
		if ($_1238427860 == $_1140816158) {
			$_1040648691 = true;
		}
		if ($_1040648691) {
			@unlink($_1190315246);
			if (!@rename($_1190315246 . ".tmp", $_1190315246)) {
				$errorMessage .= "[JUHYT005] " . GetMessage("JUHYT005_ERROR_FILE") . ". ";
				return "E";
			}
			@unlink($_1190315246 . ".tmp");
		} else {
			return "S";
		}
		return "U";
	}

	private static function __1019404210($_1380031857, $_1140816158, $_1190315246, $_1574650516, &$errorMessage)
	{
		$_804267775  = 0;
		$_1760501310 = null;
		while ($_804267775 < 10) {
			$_804267775++;
			$_1760501310 = static::__427015786($_1380031857, $_1140816158, $_1190315246, $_1574650516, $errorMessage);
			if ($_1760501310 === "S") {
				continue;
			}
			break;
		}
		if ($_1760501310 === "S") {
			$errorMessage = "[UPCLLF111] " . GetMessage("SUPP_PSD_BAD_TRANS") . ". ";
			$_1760501310  = "E";
		}
		return $_1760501310;
	}

	private static function __427015786($_1380031857, $_1140816158, $_1190315246, $_1574650516, &$errorMessage)
	{
		$_1574650516 = intval($_1574650516);
		$_1079794597 = 0;
		if ($_1574650516 > 0) $_1079794597 = microtime(true);
		$_825092131  = file_exists($_1190315246 . ".tmp") ? filesize($_1190315246 . ".tmp") : 0;
		$_1399279890 = static::__690101446();
		$_1469713035 = fsockopen($_1399279890["SOCKET_IP"], $_1399279890["SOCKET_PORT"], $_1216130077, $_1198456226, 30);
		if (!$_1469713035) {
			$errorMessage .= static::__1863373792($_1198456226, $_1216130077, $_1399279890);
			return "E";
		}
		if (!$_1380031857) $_1380031857 = "/";
		$_841631328 = "";
		if (!$_1399279890["USE_PROXY"]) {
			$_841631328 .= "GET " . $_1380031857 . " HTTP/1.0
";
			$_841631328 .= "Host: " . $_1399279890["IP"] . "
";
		} else {
			$_841631328 .= "GET http://" . $_1399279890["IP"] . $_1380031857 . " HTTP/1.0
";
			$_841631328 .= "Host: " . $_1399279890["IP"] . "
";
			if ($_1399279890["PROXY_USERNAME"]) $_841631328 .= "Proxy-Authorization: Basic " . base64_encode($_1399279890["PROXY_USERNAME"] . ":" . $_1399279890["PROXY_PASSWORD"]) . "
";
		}
		$_841631328 .= "User-Agent: BitrixSMUpdater
";
		if ($_825092131 > 0) $_841631328 .= "Range: bytes=" . $_825092131 . "-
";
		$_841631328 .= "
";
		fwrite($_1469713035, $_841631328);
		$_1478001394 = "";
		while (($_1760501310 = fgets($_1469713035, 4096)) && $_1760501310 != "
") $_1478001394 .= $_1760501310;
		$_598040559  = preg_split("#
#", $_1478001394);
		$_469676813  = 0;
		$_2133439420 = "";
		if (preg_match("#([A-Z]{4})/([0-9.]{3}) ([0-9]{3})#", $_598040559[0], $_1979923803)) {
			$_469676813  = intval($_1979923803[3]);
			$_2133439420 = substr($_598040559[0], strpos($_598040559[0], $_469676813) + strlen($_469676813) + 1, strlen($_598040559[0]) - strpos($_598040559[0], $_469676813) + 1);
		}
		if ($_469676813 != 200 && $_469676813 != 204 && $_469676813 != 302 && $_469676813 != 206) {
			$errorMessage .= GetMessage("SUPP_PSD_BAD_RESPONSE") . " (" . $_469676813 . " - " . $_2133439420 . ")";
			return "E";
		}
		$_425404911  = "";
		$_1889349419 = 0;
		for ($_443696004 = 1; $_443696004 < count($_598040559); $_443696004++) {
			if (strpos($_598040559[$_443696004], "Content-Range") !== false) $_425404911 = trim(substr($_598040559[$_443696004], strpos($_598040559[$_443696004], ":") + 1, strlen($_598040559[$_443696004]) - strpos($_598040559[$_443696004], ":") + 1)); elseif (strpos($_598040559[$_443696004], "Content-Length") !== false) $_1889349419 = doubleval(trim(substr($_598040559[$_443696004], strpos($_598040559[$_443696004], ":") + 1, strlen($_598040559[$_443696004]) - strpos($_598040559[$_443696004], ":") + 1)));
		}
		$_450159475 = true;
		if ($_425404911 <> "") {
			if (preg_match("# *bytes +([0-9]*) *- *([0-9]*) */ *([0-9]*)#i", $_425404911, $_1979923803)) {
				$_1002897392 = doubleval($_1979923803[1]);
				$_1605817621 = doubleval($_1979923803[2]);
				$_408133725  = doubleval($_1979923803[3]);
				if (($_1002897392 == $_825092131) && ($_1605817621 == ($_1140816158 - 1)) && ($_408133725 == $_1140816158)) {
					$_450159475 = false;
				}
			}
		}
		if ($_450159475) {
			@unlink($_1190315246 . ".tmp");
			$_825092131 = 0;
		}
		if (($_1889349419 + $_825092131) != $_1140816158) {
			$errorMessage .= "[ELVL010] " . GetMessage("ELVL001_SIZE_ERROR") . ". ";
			return "E";
		}
		$_971647376 = fopen($_1190315246 . ".tmp", "ab");
		if (!$_971647376) {
			$errorMessage .= "[JUHYT010] " . GetMessage("JUHYT002_ERROR_FILE") . ". ";
			return "E";
		}
		while (true) {
			if ($_1574650516 > 0 && (microtime(true) - $_1079794597) > $_1574650516) {
				break;
			}
			$_1760501310 = fread($_1469713035, 256 * 1024);
			if ($_1760501310 == "") {
				break;
			}
			fwrite($_971647376, $_1760501310);
		}
		fclose($_971647376);
		fclose($_1469713035);
		clearstatcache();
		$_1238427860 = (file_exists($_1190315246 . ".tmp") ? filesize($_1190315246 . ".tmp") : 0);
		if ((int)$_1238427860 === (int)$_1140816158) {
			@unlink($_1190315246);
			if (!@rename($_1190315246 . ".tmp", $_1190315246)) {
				$errorMessage .= "[JUHYT010] " . GetMessage("JUHYT005_ERROR_FILE") . ". ";
				return "E";
			}
			@unlink($_1190315246 . ".tmp");
		} else {
			return "S";
		}
		return "U";
	}

	public static function LoadLangsUpdates(&$errorMessage, &$_1934218753, $_1530138095 = false, $_1474158358 = "Y", $_1523107763 = array())
	{
		$_1934218753 = array();
		$_1622907824 = "";
		$_906972647  = $_SERVER["DOCUMENT_ROOT"] . "/bitrix/updates/update_archive.gz";
		$_1574650516 = COption::GetOptionString("main", "update_load_timeout", "30");
		if ($_1574650516 < 5) $_1574650516 = 5;
		CUpdateClient::AddMessage2Log("exec CUpdateClient::LoadLangsUpdates");
		if (file_exists($_906972647 . ".log")) {
			$_1596961748 = file_get_contents($_906972647 . ".log");
			CUpdateClient::__1244221643($_1596961748, $_1934218753, $errorMessage);
		}
		if (empty($_1934218753) || $errorMessage <> "") {
			$_1934218753 = array();
			if (file_exists($_906972647 . ".tmp")) @unlink($_906972647 . ".tmp");
			if (file_exists($_906972647 . ".log")) @unlink($_906972647 . ".log");
			if ($errorMessage <> "") {
				CUpdateClient::AddMessage2Log($errorMessage, "LMUL001");
				return "E";
			}
		}
		if (empty($_1934218753)) {
			$_1622907824 = CUpdateClient::CollectRequestData($errorMessage, $_1530138095, $_1474158358, array(), $_1523107763);
			if (empty($_1622907824) || $errorMessage <> "") {
				if ($errorMessage == "") $errorMessage = "[GNSU01] " . GetMessage("SUPZ_NO_QSTRING") . ". ";
				CUpdateClient::AddMessage2Log($errorMessage, "LMUL002");
				return "E";
			}
			CUpdateClient::AddMessage2Log(preg_replace("/LICENSE_KEY=[^&]*/i", "LICENSE_KEY=X", $_1622907824));
			$_418914584  = microtime(true);
			$_1596961748 = CUpdateClient::GetHTTPPage("STEPL", $_1622907824, $errorMessage);
			if ($_1596961748 == "" || $errorMessage <> "") {
				if ($errorMessage == "") $errorMessage = "[GNSU02] " . GetMessage("SUPZ_EMPTY_ANSWER") . ". ";
				CUpdateClient::AddMessage2Log($errorMessage, "LMUL003");
				return "E";
			}
			CUpdateClient::AddMessage2Log("TIME LoadLangsUpdates(request) " . round(microtime(true) - $_418914584, 3) . " sec");
			CUpdateClient::__1244221643($_1596961748, $_1934218753, $errorMessage);
			if ($errorMessage <> "") {
				CUpdateClient::AddMessage2Log($errorMessage, "LMUL004");
				return "E";
			}
			if (isset($_1934218753["DATA"]["#"]["ERROR"])) {
				for ($_443696004 = 0, $_1167463684 = count($_1934218753["DATA"]["#"]["ERROR"]); $_443696004 < $_1167463684; $_443696004++) $errorMessage .= "[" . $_1934218753["DATA"]["#"]["ERROR"][$_443696004]["@"]["TYPE"] . "] " . $_1934218753["DATA"]["#"]["ERROR"][$_443696004]["#"];
			}
			if ($errorMessage <> "") {
				CUpdateClient::AddMessage2Log($errorMessage, "LMU005");
				return "E";
			}
			if (isset($_1934218753["DATA"]["#"]["NOUPDATES"])) {
				CUpdateClient::AddMessage2Log("Finish - NOUPDATES", "STEP");
				return "F";
			}
			$_971647376 = fopen($_906972647 . ".log", "wb");
			if (!$_971647376) {
				$errorMessage = "[GNSUL03] " . str_replace("#FILE#", $_SERVER["DOCUMENT_ROOT"] . "/bitrix/updates", GetMessage("SUPP_RV_ER_TEMP_FILE")) . ". ";
				CUpdateClient::AddMessage2Log($errorMessage, "LMU006");
				return "E";
			}
			fwrite($_971647376, $_1596961748);
			fclose($_971647376);
			CUpdateClient::AddMessage2Log("STEP", "S");
			return "S";
		}
		if (isset($_1934218753["DATA"]["#"]["FILE"][0]["@"]["NAME"])) {
			if ($_1622907824 == "") {
				$_1622907824 = CUpdateClient::CollectRequestData($errorMessage, $_1530138095, $_1474158358, array(), $_1523107763);
				if (empty($_1622907824) || $errorMessage <> "") {
					if ($errorMessage == "") $errorMessage = "[GNSUL01] " . GetMessage("SUPZ_NO_QSTRING") . ". ";
					CUpdateClient::AddMessage2Log($errorMessage, "LMUL007");
					return "E";
				}
			}
			CUpdateClient::AddMessage2Log("loadLangFileBx");
			$_837377958 = static::__1442847736($_1934218753["DATA"]["#"]["FILE"][0]["@"]["NAME"], $_1934218753["DATA"]["#"]["FILE"][0]["@"]["SIZE"], $_906972647, $_1574650516, $_1622907824, $errorMessage, "us_updater_langs.php");
		} elseif ($_1934218753["DATA"]["#"]["FILE"][0]["@"]["URL"]) {
			CUpdateClient::AddMessage2Log("loadFile");
			$_837377958 = static::__1019404210($_1934218753["DATA"]["#"]["FILE"][0]["@"]["URL"], $_1934218753["DATA"]["#"]["FILE"][0]["@"]["SIZE"], $_906972647, $_1574650516, $errorMessage);
		} else {
			$_837377958   = "E";
			$errorMessage .= GetMessage("SUPP_PSD_BAD_RESPONSE");
		}
		if ($_837377958 == "E") {
			CUpdateClient::AddMessage2Log($errorMessage, "GNSUL001");
			$errorMessage .= $errorMessage;
		} elseif ($_837377958 == "U") {
			@unlink($_906972647 . ".log");
		}
		CUpdateClient::AddMessage2Log("RETURN", $_837377958);
		return $_837377958;
	}

	public static function GetNextStepUpdates(&$_753204024, $_1530138095 = false, $_1474158358 = "Y", $_2142182691 = array())
	{
		$_2136808367 = '';
		$_1596961748 = "";
		CUpdateClient::AddMessage2Log("exec CUpdateClient::GetNextStepUpdates");
		$_1622907824 = CUpdateClient::CollectRequestData($_2136808367, $_1530138095, $_1474158358, $_2142182691);
		if ($_1622907824 == "" || $_2136808367 <> "") {
			if ($_2136808367 == "") $_2136808367 = "[GNSU01] " . GetMessage("SUPZ_NO_QSTRING") . ". ";
		}
		if ($_2136808367 == "") {
			CUpdateClient::AddMessage2Log(preg_replace("/LICENSE_KEY=[^&]*/i", "LICENSE_KEY=X", $_1622907824));
			$_418914584  = microtime(true);
			$_1596961748 = CUpdateClient::GetHTTPPage("STEPM", $_1622907824, $_2136808367);
			if ($_1596961748 == "") {
				if ($_2136808367 == "") $_2136808367 = "[GNSU02] " . GetMessage("SUPZ_EMPTY_ANSWER") . ". ";
			}
			CUpdateClient::AddMessage2Log("TIME GetNextStepUpdates(request) " . round(microtime(true) - $_418914584, 3) . " sec");
		}
		if ($_2136808367 == "") {
			if (!($_1069261324 = fopen($_SERVER["DOCUMENT_ROOT"] . "/bitrix/updates/update_archive.gz", "wb"))) $_2136808367 = "[GNSU03] " . str_replace("#FILE#", $_SERVER["DOCUMENT_ROOT"] . "/bitrix/updates", GetMessage("SUPP_RV_ER_TEMP_FILE")) . ". ";
		}
		if ($_2136808367 == "") {
			fwrite($_1069261324, $_1596961748);
			fclose($_1069261324);
		}
		if ($_2136808367 <> "") {
			CUpdateClient::AddMessage2Log($_2136808367, "GNSU00");
			$_753204024 .= $_2136808367;
			return false;
		} else return true;
	}

	public static function UnGzipArchive(&$_426046905, &$_753204024, $_1479349593 = true)
	{
		$_2136808367 = '';
		$_1370972511 = "";
		CUpdateClient::AddMessage2Log("exec CUpdateClient::UnGzipArchive");
		$_418914584  = microtime(true);
		$_1913215604 = $_SERVER["DOCUMENT_ROOT"] . "/bitrix/updates/update_archive.gz";
		if (!file_exists($_1913215604) || !is_file($_1913215604)) $_2136808367 .= "[UUGZA01] " . str_replace("#FILE#", $_1913215604, GetMessage("SUPP_UGA_NO_TMP_FILE")) . ". ";
		if ($_2136808367 == "") {
			if (!is_readable($_1913215604)) $_2136808367 .= "[UUGZA02] " . str_replace("#FILE#", $_1913215604, GetMessage("SUPP_UGA_NO_READ_FILE")) . ". ";
		}
		if ($_2136808367 == "") {
			$_426046905  = "update_m" . time();
			$_1370972511 = $_SERVER["DOCUMENT_ROOT"] . "/bitrix/updates/" . $_426046905;
			CUpdateClient::CheckDirPath($_1370972511 . "/");
			if (!file_exists($_1370972511) || !is_dir($_1370972511)) $_2136808367 .= "[UUGZA03] " . str_replace("#FILE#", $_1370972511, GetMessage("SUPP_UGA_NO_TMP_CAT")) . ". "; elseif (!is_writable($_1370972511)) $_2136808367 .= "[UUGZA04] " . str_replace("#FILE#", $_1370972511, GetMessage("SUPP_UGA_WRT_TMP_CAT")) . ". ";
		}
		if ($_2136808367 == "") {
			$_975063846  = true;
			$_237833530  = fopen($_1913215604, "rb");
			$_1161027315 = fread($_237833530, strlen("BITRIX"));
			fclose($_237833530);
			if ($_1161027315 == "BITRIX") $_975063846 = false;
		}
		if ($_2136808367 == "") {
			if ($_975063846 && !function_exists("gzopen")) $_975063846 = false;
		}
		if ($_2136808367 == "") {
			if ($_975063846) $_1125224425 = gzopen($_1913215604, "rb9f"); else $_1125224425 = fopen($_1913215604, "rb");
			if (!$_1125224425) $_2136808367 .= "[UUGZA05] " . str_replace("#FILE#", $_1913215604, GetMessage("SUPP_UGA_CANT_OPEN")) . ". ";
		}
		if ($_2136808367 == "") {
			if ($_975063846) $_1161027315 = gzread($_1125224425, strlen("BITRIX")); else $_1161027315 = fread($_1125224425, strlen("BITRIX"));
			if ($_1161027315 != "BITRIX") {
				$_2136808367 .= "[UUGZA06] " . str_replace("#FILE#", $_1913215604, GetMessage("SUPP_UGA_BAD_FORMAT")) . ". ";
				if ($_975063846) gzclose($_1125224425); else fclose($_1125224425);
			}
		}
		if ($_2136808367 == "") {
			$strongUpdateCheck = COption::GetOptionString("main", "strong_update_check", "Y");
			while (true) {
				if ($_975063846) $_2071982622 = gzread($_1125224425, 5); else $_2071982622 = fread($_1125224425, 5);
				$_2071982622 = trim($_2071982622);
				if (intval($_2071982622) > 0 && intval($_2071982622) . "!" == $_2071982622 . "!") {
					$_2071982622 = intval($_2071982622);
				} else {
					if ($_2071982622 != "RTIBE") $_2136808367 .= "[UUGZA071] " . str_replace("#FILE#", $_1913215604, GetMessage("SUPP_UGA_BAD_FORMAT")) . ". ";
					break;
				}
				if ($_975063846) $_1678941771 = gzread($_1125224425, $_2071982622); else $_1678941771 = fread($_1125224425, $_2071982622);
				$_1750538859 = explode("|", $_1678941771);
				if (count($_1750538859) != 3) {
					$_2136808367 .= "[UUGZA072] " . str_replace("#FILE#", $_1913215604, GetMessage("SUPP_UGA_BAD_FORMAT")) . ". ";
					break;
				}
				$_1993631613 = $_1750538859[0];
				$_925801086  = $_1750538859[1];
				$_126958015  = $_1750538859[2];
				$_11445862   = "";
				if (intval($_1993631613) > 0) {
					if ($_975063846) $_11445862 = gzread($_1125224425, $_1993631613); else $_11445862 = fread($_1125224425, $_1993631613);
				}
				$_1620366633 = dechex(crc32($_11445862));
				if ($_1620366633 !== $_126958015) {
					$_2136808367 .= "[UUGZA073] " . str_replace("#FILE#", $_925801086, GetMessage("SUPP_UGA_FILE_CRUSH")) . ". ";
					break;
				} else {
					CUpdateClient::CheckDirPath($_1370972511 . $_925801086);
					if (!($_1069261324 = fopen($_1370972511 . $_925801086, "wb"))) {
						$_2136808367 .= "[UUGZA074] " . str_replace("#FILE#", $_1370972511 . $_925801086, GetMessage("SUPP_UGA_CANT_OPEN_WR")) . ". ";
						break;
					}
					if ($_11445862 <> "" && !fwrite($_1069261324, $_11445862)) {
						$_2136808367 .= "[UUGZA075] " . str_replace("#FILE#", $_1370972511 . $_925801086, GetMessage("SUPP_UGA_CANT_WRITE_F")) . ". ";
						@fclose($_1069261324);
						break;
					}
					fclose($_1069261324);
					if ($strongUpdateCheck == "Y") {
						$_1620366633 = dechex(crc32(file_get_contents($_1370972511 . $_925801086)));
						if ($_1620366633 !== $_126958015) {
							$_2136808367 .= "[UUGZA0761] " . str_replace("#FILE#", $_925801086, GetMessage("SUPP_UGA_FILE_CRUSH")) . ". ";
							break;
						}
					}
				}
			}
			if ($_975063846) gzclose($_1125224425); else fclose($_1125224425);
		}
		if ($_2136808367 == "") {
			if ($_1479349593) @unlink($_1913215604);
		}
		CUpdateClient::AddMessage2Log("TIME UnGzipArchive " . round(microtime(true) - $_418914584, 3) . " sec");
		if ($_2136808367 <> "") {
			CUpdateClient::AddMessage2Log($_2136808367, "CUUGZA");
			$_753204024 .= $_2136808367;
			return false;
		} else return true;
	}

	public static function CheckUpdatability($_426046905, &$_753204024)
	{
		$_2136808367 = "";
		$_1370972511 = $_SERVER["DOCUMENT_ROOT"] . "/bitrix/updates/" . $_426046905;
		if (!file_exists($_1370972511) || !is_dir($_1370972511)) $_2136808367 .= "[UCU01] " . str_replace("#FILE#", $_1370972511, GetMessage("SUPP_CU_NO_TMP_CAT")) . ". ";
		if ($_2136808367 == "") if (!is_readable($_1370972511)) $_2136808367 .= "[UCU02] " . str_replace("#FILE#", $_1370972511, GetMessage("SUPP_CU_RD_TMP_CAT")) . ". ";
		if ($_1177408942 = @opendir($_1370972511)) {
			while (($_292314395 = readdir($_1177408942)) !== false) {
				if ($_292314395 == "." || $_292314395 == "..") continue;
				if (is_dir($_1370972511 . "/" . $_292314395)) {
					CUpdateClient::CheckUpdatability($_426046905 . "/" . $_292314395, $_2136808367);
				} elseif (is_file($_1370972511 . "/" . $_292314395)) {
					$_1163746080 = $_SERVER["DOCUMENT_ROOT"] . US_SHARED_KERNEL_PATH . "/modules/" . substr($_426046905 . "/" . $_292314395, strpos($_426046905 . "/" . $_292314395, "/"));
					if (file_exists($_1163746080)) {
						if (!is_writeable($_1163746080)) $_2136808367 .= "[UCU03] " . str_replace("#FILE#", $_1163746080, GetMessage("SUPP_CU_MAIN_ERR_FILE")) . ". ";
					} else {
						$_700691280  = CUpdateClient::bxstrrpos($_1163746080, "/");
						$_1163746080 = substr($_1163746080, 0, $_700691280);
						if (strlen($_1163746080) > 1) $_1163746080 = rtrim($_1163746080, "/");
						$_700691280 = CUpdateClient::bxstrrpos($_1163746080, "/");
						while ($_700691280 > 0) {
							if (file_exists($_1163746080) && is_dir($_1163746080)) {
								if (!is_writable($_1163746080)) $_2136808367 .= "[UCU04] " . str_replace("#FILE#", $_1163746080, GetMessage("SUPP_CU_MAIN_ERR_CAT")) . ". ";
								break;
							}
							$_1163746080 = substr($_1163746080, 0, $_700691280);
							$_700691280  = CUpdateClient::bxstrrpos($_1163746080, "/");
						}
					}
				}
			}
			@closedir($_1177408942);
		}
		if ($_2136808367 <> "") {
			CUpdateClient::AddMessage2Log($_2136808367, "CUCU");
			$_753204024 .= $_2136808367;
			return false;
		} else return true;
	}

	public static function GetStepUpdateInfo($_426046905, &$_753204024)
	{
		$_1139428298 = array();
		$_2136808367 = "";
		CUpdateClient::AddMessage2Log("exec CUpdateClient::GetStepUpdateInfo");
		$_1370972511 = $_SERVER["DOCUMENT_ROOT"] . "/bitrix/updates/" . $_426046905;
		if (!file_exists($_1370972511) || !is_dir($_1370972511)) $_2136808367 .= "[UGLMU01] " . str_replace("#FILE#", $_1370972511, GetMessage("SUPP_CU_NO_TMP_CAT")) . ". ";
		if ($_2136808367 == "") if (!is_readable($_1370972511)) $_2136808367 .= "[UGLMU02] " . str_replace("#FILE#", $_1370972511, GetMessage("SUPP_CU_RD_TMP_CAT")) . ". ";
		if ($_2136808367 == "") if (!file_exists($_1370972511 . "/update_info.xml") || !is_file($_1370972511 . "/update_info.xml")) $_2136808367 .= "[UGLMU03] " . str_replace("#FILE#", $_1370972511 . "/update_info.xml", GetMessage("SUPP_RV_ER_DESCR_FILE")) . ". ";
		if ($_2136808367 == "") if (!is_readable($_1370972511 . "/update_info.xml")) $_2136808367 .= "[UGLMU04] " . str_replace("#FILE#", $_1370972511 . "/update_info.xml", GetMessage("SUPP_RV_READ_DESCR_FILE")) . ". ";
		if ($_2136808367 == "") $_1596961748 = file_get_contents($_1370972511 . "/update_info.xml");
		if ($_2136808367 == "") {
			$_1139428298 = array();
			CUpdateClient::__1244221643($_1596961748, $_1139428298, $_2136808367);
		}
		if ($_2136808367 == "") {
			if (!isset($_1139428298["DATA"]) || !is_array($_1139428298["DATA"])) $_2136808367 .= "[UGSMU01] " . GetMessage("SUPP_GAUT_SYSERR") . ". ";
		}
		if ($_2136808367 <> "") {
			CUpdateClient::AddMessage2Log($_2136808367, "CUGLMU");
			$_753204024 .= $_2136808367;
			return false;
		} else return $_1139428298;
	}

	public static function UpdateStepHelps($_426046905, &$_753204024)
	{
		$_2136808367 = "";
		CUpdateClient::AddMessage2Log("exec CUpdateClient::UpdateHelp");
		$_418914584  = microtime(true);
		$_1370972511 = $_SERVER["DOCUMENT_ROOT"] . "/bitrix/updates/" . $_426046905;
		$_1919044642 = $_SERVER["DOCUMENT_ROOT"] . US_SHARED_KERNEL_PATH . "/help";
		$_1601956098 = array();
		$_1177408942 = @opendir($_1370972511);
		if ($_1177408942) {
			while (false !== ($_239640225 = readdir($_1177408942))) {
				if ($_239640225 == "." || $_239640225 == "..") continue;
				if (is_dir($_1370972511 . "/" . $_239640225)) $_1601956098[] = $_239640225;
			}
			closedir($_1177408942);
		}
		if (!is_array($_1601956098) || empty($_1601956098)) $_2136808367 .= "[UUH00] " . GetMessage("SUPP_UH_NO_LANG") . ". ";
		if (!file_exists($_1370972511) || !is_dir($_1370972511)) $_2136808367 .= "[UUH01] " . str_replace("#FILE#", $_1370972511, GetMessage("SUPP_CU_NO_TMP_CAT")) . ". ";
		if ($_2136808367 == "") if (!is_readable($_1370972511)) $_2136808367 .= "[UUH03] " . str_replace("#FILE#", $_1370972511, GetMessage("SUPP_CU_RD_TMP_CAT")) . ". ";
		if ($_2136808367 == "") {
			CUpdateClient::CheckDirPath($_1919044642 . "/");
			if (!file_exists($_1919044642) || !is_dir($_1919044642)) $_2136808367 .= "[UUH02] " . str_replace("#FILE#", $_1919044642, GetMessage("SUPP_UH_NO_HELP_CAT")) . ". "; elseif (!is_writable($_1919044642)) $_2136808367 .= "[UUH03] " . str_replace("#FILE#", $_1919044642, GetMessage("SUPP_UH_NO_WRT_HELP")) . ". ";
		}
		if ($_2136808367 == "") {
			for ($_443696004 = 0, $_346373140 = count($_1601956098); $_443696004 < $_346373140; $_443696004++) {
				$_1123571860 = "";
				$_361977632  = $_1370972511 . "/" . $_1601956098[$_443696004];
				if (!file_exists($_361977632) || !is_dir($_361977632)) $_1123571860 .= "[UUH04] " . str_replace("#FILE#", $_361977632, GetMessage("SUPP_UL_NO_TMP_LANG")) . ". ";
				if ($_1123571860 == "") if (!is_readable($_361977632)) $_1123571860 .= "[UUH05] " . str_replace("#FILE#", $_361977632, GetMessage("SUPP_UL_NO_READ_LANG")) . ". ";
				if ($_1123571860 == "") {
					if (file_exists($_1919044642 . "/" . $_1601956098[$_443696004] . "_tmp")) CUpdateClient::DeleteDirFilesEx($_1919044642 . "/" . $_1601956098[$_443696004] . "_tmp");
					if (file_exists($_1919044642 . "/" . $_1601956098[$_443696004] . "_tmp")) $_1123571860 .= "[UUH06] " . str_replace("#FILE#", $_1919044642 . "/" . $_1601956098[$_443696004] . "_tmp", GetMessage("SUPP_UH_CANT_DEL")) . ". ";
				}
				if ($_1123571860 == "") {
					if (file_exists($_1919044642 . "/" . $_1601956098[$_443696004])) if (!rename($_1919044642 . "/" . $_1601956098[$_443696004], $_1919044642 . "/" . $_1601956098[$_443696004] . "_tmp")) $_1123571860 .= "[UUH07] " . str_replace("#FILE#", $_1919044642 . "/" . $_1601956098[$_443696004], GetMessage("SUPP_UH_CANT_RENAME")) . ". ";
				}
				if ($_1123571860 == "") {
					CUpdateClient::CheckDirPath($_1919044642 . "/" . $_1601956098[$_443696004] . "/");
					if (!file_exists($_1919044642 . "/" . $_1601956098[$_443696004]) || !is_dir($_1919044642 . "/" . $_1601956098[$_443696004])) $_1123571860 .= "[UUH08] " . str_replace("#FILE#", $_1919044642 . "/" . $_1601956098[$_443696004], GetMessage("SUPP_UH_CANT_CREATE")) . ". "; elseif (!is_writable($_1919044642 . "/" . $_1601956098[$_443696004])) $_1123571860 .= "[UUH09] " . str_replace("#FILE#", $_1919044642 . "/" . $_1601956098[$_443696004], GetMessage("SUPP_UH_CANT_WRITE")) . ". ";
				}
				if ($_1123571860 == "") CUpdateClient::CopyDirFiles($_361977632, $_1919044642 . "/" . $_1601956098[$_443696004], $_1123571860);
				if ($_1123571860 <> "") {
					$_2136808367 .= $_1123571860;
				} else {
					if (file_exists($_1919044642 . "/" . $_1601956098[$_443696004] . "_tmp")) CUpdateClient::DeleteDirFilesEx($_1919044642 . "/" . $_1601956098[$_443696004] . "_tmp");
				}
			}
			CUpdateClient::ClearUpdateFolder($_1370972511);
		}
		CUpdateClient::AddMessage2Log("TIME UpdateHelp " . round(microtime(true) - $_418914584, 3) . " sec");
		if ($_2136808367 <> "") {
			CUpdateClient::AddMessage2Log($_2136808367, "USH");
			$_753204024 .= $_2136808367;
			return false;
		} else return true;
	}

	public static function UpdateStepLangs($_426046905, &$_753204024)
	{
		$_2136808367 = '';
		$_1590613717 = "";
		$_418914584  = microtime(true);
		$_1370972511 = $_SERVER["DOCUMENT_ROOT"] . "/bitrix/updates/" . $_426046905;
		if (!file_exists($_1370972511) || !is_dir($_1370972511)) $_2136808367 .= "[UUL01] " . str_replace("#FILE#", $_1370972511, GetMessage("SUPP_CU_NO_TMP_CAT")) . ". ";
		$_757086976 = array();
		if ($_2136808367 == "") {
			$_1177408942 = @opendir($_1370972511);
			if ($_1177408942) {
				while (false !== ($_239640225 = readdir($_1177408942))) {
					if ($_239640225 == "." || $_239640225 == "..") continue;
					if (is_dir($_1370972511 . "/" . $_239640225)) $_757086976[] = $_239640225;
				}
				closedir($_1177408942);
			}
		}
		if (!is_array($_757086976) || empty($_757086976)) $_2136808367 .= "[UUL02] " . GetMessage("SUPP_UL_NO_LANGS") . ". ";
		if ($_2136808367 == "") if (!is_readable($_1370972511)) $_2136808367 .= "[UUL03] " . str_replace("#FILE#", $_1370972511, GetMessage("SUPP_CU_RD_TMP_CAT")) . ". ";
		$_1290774310 = array("component" => $_SERVER["DOCUMENT_ROOT"] . US_SHARED_KERNEL_PATH . "/components/bitrix", "activities" => $_SERVER["DOCUMENT_ROOT"] . US_SHARED_KERNEL_PATH . "/activities/bitrix", "gadgets" => $_SERVER["DOCUMENT_ROOT"] . US_SHARED_KERNEL_PATH . "/gadgets/bitrix", "wizards" => $_SERVER["DOCUMENT_ROOT"] . US_SHARED_KERNEL_PATH . "/wizards/bitrix", "template" => $_SERVER["DOCUMENT_ROOT"] . US_SHARED_KERNEL_PATH . "/templates", "blocks" => $_SERVER["DOCUMENT_ROOT"] . US_SHARED_KERNEL_PATH . "/blocks/bitrix");
		$_784276436  = array("component" => "/install/components/bitrix", "activities" => "/install/activities/bitrix", "gadgets" => "/install/gadgets/bitrix", "wizards" => "/install/wizards/bitrix", "template" => "/install/templates", "blocks" => "/install/blocks/bitrix",);
		if ($_2136808367 == "") {
			foreach ($_1290774310 as $_1630931493) {
				CUpdateClient::CheckDirPath($_1630931493 . "/");
				if (!file_exists($_1630931493) || !is_dir($_1630931493)) $_2136808367 .= "[UUL04] " . str_replace("#FILE#", $_1630931493, GetMessage("SUPP_UL_CAT")) . ". "; elseif (!is_writable($_1630931493)) $_2136808367 .= "[UUL05] " . str_replace("#FILE#", $_1630931493, GetMessage("SUPP_UL_NO_WRT_CAT")) . ". ";
			}
		}
		if ($_2136808367 == "") {
			$_1590613717 = $_SERVER["DOCUMENT_ROOT"] . US_SHARED_KERNEL_PATH . "/modules";
			CUpdateClient::CheckDirPath($_1590613717 . "/");
			if (!file_exists($_1590613717) || !is_dir($_1590613717)) $_2136808367 .= "[UUL04] " . str_replace("#FILE#", $_1590613717, GetMessage("SUPP_UL_CAT")) . ". "; elseif (!is_writable($_1590613717)) $_2136808367 .= "[UUL05] " . str_replace("#FILE#", $_1590613717, GetMessage("SUPP_UL_NO_WRT_CAT")) . ". ";
		}
		$_628558867 = array();
		if ($_2136808367 == "") {
			foreach ($_1290774310 as $_1947975612 => $_1630931493) {
				$_1690130272 = @opendir($_1630931493);
				if ($_1690130272) {
					while (false !== ($_1097333674 = readdir($_1690130272))) {
						if (is_dir($_1630931493 . "/" . $_1097333674) && $_1097333674 != "." && $_1097333674 != "..") {
							if (!is_writable($_1630931493 . "/" . $_1097333674)) $_2136808367 .= "[UUL051] " . str_replace("#FILE#", $_1630931493 . "/" . $_1097333674, GetMessage("SUPP_UL_NO_WRT_CAT")) . ". ";
							if (file_exists($_1630931493 . "/" . $_1097333674 . "/lang") && !is_writable($_1630931493 . "/" . $_1097333674 . "/lang")) $_2136808367 .= "[UUL052] " . str_replace("#FILE#", $_1630931493 . "/" . $_1097333674 . "/lang", GetMessage("SUPP_UL_NO_WRT_CAT")) . ". ";
							$_628558867[$_1947975612][] = $_1097333674;
						}
					}
					closedir($_1690130272);
				}
			}
		}
		if ($_2136808367 == "") {
			$_613112957  = array();
			$_1177408942 = @opendir($_1590613717);
			if ($_1177408942) {
				while (false !== ($_239640225 = readdir($_1177408942))) {
					if (is_dir($_1590613717 . "/" . $_239640225) && $_239640225 != "." && $_239640225 != "..") {
						if (!is_writable($_1590613717 . "/" . $_239640225)) $_2136808367 .= "[UUL051] " . str_replace("#FILE#", $_1590613717 . "/" . $_239640225, GetMessage("SUPP_UL_NO_WRT_CAT")) . ". ";
						if (file_exists($_1590613717 . "/" . $_239640225 . "/lang") && !is_writable($_1590613717 . "/" . $_239640225 . "/lang")) $_2136808367 .= "[UUL052] " . str_replace("#FILE#", $_1590613717 . "/" . $_239640225 . "/lang", GetMessage("SUPP_UL_NO_WRT_CAT")) . ". ";
						$_613112957[] = $_239640225;
					}
				}
				closedir($_1177408942);
			}
		}
		if ($_2136808367 == "") {
			for ($_443696004 = 0, $_346373140 = count($_757086976); $_443696004 < $_346373140; $_443696004++) {
				$_1123571860 = "";
				$_361977632  = $_1370972511 . "/" . $_757086976[$_443696004];
				if (!file_exists($_361977632) || !is_dir($_361977632)) $_1123571860 .= "[UUL06] " . str_replace("#FILE#", $_361977632, GetMessage("SUPP_UL_NO_TMP_LANG")) . ". ";
				if ($_1123571860 == "") if (!is_readable($_361977632)) $_1123571860 .= "[UUL07] " . str_replace("#FILE#", $_361977632, GetMessage("SUPP_UL_NO_READ_LANG")) . ". ";
				if ($_1123571860 == "") {
					$_1690130272 = @opendir($_361977632);
					if ($_1690130272) {
						while (false !== ($_1097333674 = readdir($_1690130272))) {
							if (!is_dir($_361977632 . "/" . $_1097333674) || $_1097333674 == "." || $_1097333674 == "..") continue;
							foreach ($_784276436 as $_1947975612 => $_1630931493) {
								if (empty($_628558867[$_1947975612])) {
									continue;
								}
								if (!file_exists($_361977632 . "/" . $_1097333674 . $_1630931493)) continue;
								$_2026545294 = @opendir($_361977632 . "/" . $_1097333674 . $_1630931493);
								if ($_2026545294) {
									while (false !== ($_744002689 = readdir($_2026545294))) {
										if (!is_dir($_361977632 . "/" . $_1097333674 . $_1630931493 . "/" . $_744002689) || $_744002689 == "." || $_744002689 == "..") continue;
										if (!in_array($_744002689, $_628558867[$_1947975612])) continue;
										CUpdateClient::CopyDirFiles($_361977632 . "/" . $_1097333674 . $_1630931493 . "/" . $_744002689, $_1290774310[$_1947975612] . "/" . $_744002689, $_1123571860);
									}
									closedir($_2026545294);
								}
							}
							CUpdateClient::__451329904($_757086976[$_443696004], $_361977632, $_1097333674, $_SERVER["DOCUMENT_ROOT"] . US_SHARED_KERNEL_PATH, $_784276436);
							if (in_array($_1097333674, $_613112957)) CUpdateClient::CopyDirFiles($_361977632 . "/" . $_1097333674, $_1590613717 . "/" . $_1097333674, $_1123571860);
						}
						closedir($_1690130272);
					}
				}
				if ($_1123571860 <> "") $_2136808367 .= $_1123571860;
			}
		}
		if ($_2136808367 == "") CUpdateClient::ClearUpdateFolder($_1370972511);
		CUpdateClient::resetAccelerator();
		CUpdateClient::AddMessage2Log("TIME UpdateLangs " . round(microtime(true) - $_418914584, 3) . " sec");
		if ($_2136808367 <> "") {
			CUpdateClient::AddMessage2Log($_2136808367, "USL");
			$_753204024 .= $_2136808367;
			return false;
		} else return true;
	}

	private static function __451329904($_1530138095, $_1396374947, $_395671902, $_419232152, $_2141308421 = array())
	{
		$_1497327601 = $_1396374947 . "/" . $_395671902 . "/install";
		if (!file_exists($_1497327601) || !is_readable($_1497327601)) return;
		$_483938071 = @opendir($_1497327601);
		if ($_483938071) {
			while (false !== ($_376327103 = readdir($_483938071))) {
				if ($_376327103 === "." || $_376327103 === ".." || !is_dir($_1497327601 . "/" . $_376327103)) continue;
				foreach ($_2141308421 as $_30946448) {
					if (strpos($_30946448 . "/", "/install/" . $_376327103 . "/") === 0) continue 2;
				}
				self::__560785366($_1530138095, $_1497327601 . "/" . $_376327103, $_419232152 . "/" . $_376327103);
			}
			closedir($_483938071);
		}
	}

	private static function __560785366($_1530138095, $_1396374947, $_419232152, $_335072875 = "")
	{
		$_1478323267 = $_1396374947 . $_335072875;
		if (!file_exists($_1478323267) || !is_readable($_1478323267)) return;
		$_483938071 = @opendir($_1478323267);
		if ($_483938071) {
			while (false !== ($_376327103 = readdir($_483938071))) {
				if ($_376327103 === "." || $_376327103 === ".." || !is_dir($_1478323267 . "/" . $_376327103)) continue;
				if ($_376327103 === $_1530138095) {
					if (substr_compare($_1478323267, "/lang", -5) === 0) {
						if (file_exists($_419232152 . $_335072875) && is_dir($_419232152 . $_335072875) && is_writable($_419232152 . $_335072875)) {
							$_790523439 = "";
							self::CopyDirFiles($_1478323267 . "/" . $_376327103, $_419232152 . $_335072875 . "/" . $_376327103, $_790523439);
						}
						continue;
					}
				}
				self::__560785366($_1530138095, $_1396374947, $_419232152, $_335072875 . "/" . $_376327103);
			}
			closedir($_483938071);
		}
	}

	public static function UpdateStepModules($_426046905, &$_753204024, $_1311398460 = false)
	{
		global $DB;
		$_2136808367 = "";
		if (!defined("US_SAVE_UPDATERS_DIR") || US_SAVE_UPDATERS_DIR == "") $_1311398460 = false;
		$_418914584 = microtime(true);
		$_193561183 = array();
		if (!file_exists($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/lang/ua")) $_193561183[] = "ua";
		if (!file_exists($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/lang/de")) $_193561183[] = "de";
		if (!file_exists($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/lang/en")) $_193561183[] = "en";
		if (!file_exists($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/lang/ru")) $_193561183[] = "ru";
		$_1370972511 = $_SERVER["DOCUMENT_ROOT"] . "/bitrix/updates/" . $_426046905;
		if (!file_exists($_1370972511) || !is_dir($_1370972511)) $_2136808367 .= "[UUK01] " . str_replace("#FILE#", $_1370972511, GetMessage("SUPP_CU_NO_TMP_CAT")) . ". ";
		if ($_2136808367 == "") if (!is_readable($_1370972511)) $_2136808367 .= "[UUK03] " . str_replace("#FILE#", $_1370972511, GetMessage("SUPP_CU_RD_TMP_CAT")) . ". ";
		$_196288623 = array();
		if ($_2136808367 == "") {
			$_1177408942 = @opendir($_1370972511);
			if ($_1177408942) {
				while (false !== ($_239640225 = readdir($_1177408942))) {
					if ($_239640225 == "." || $_239640225 == "..") continue;
					if (is_dir($_1370972511 . "/" . $_239640225)) $_196288623[] = $_239640225;
				}
				closedir($_1177408942);
			}
		}
		if (!is_array($_196288623) || empty($_196288623)) $_2136808367 .= "[UUK02] " . GetMessage("SUPP_UK_NO_MODS") . ". ";
		if ($_2136808367 == "") {
			for ($_443696004 = 0, $_1167463684 = count($_196288623); $_443696004 < $_1167463684; $_443696004++) {
				$_1123571860 = "";
				$_361977632  = $_1370972511 . "/" . $_196288623[$_443696004];
				$_1590613717 = $_SERVER["DOCUMENT_ROOT"] . US_SHARED_KERNEL_PATH . "/modules/" . $_196288623[$_443696004];
				CUpdateClient::CheckDirPath($_1590613717 . "/");
				if (!file_exists($_1590613717) || !is_dir($_1590613717)) $_1123571860 .= "[UUK04] " . str_replace("#MODULE_DIR#", $_1590613717, GetMessage("SUPP_UK_NO_MODIR")) . ". ";
				if ($_1123571860 == "") if (!is_writable($_1590613717)) $_1123571860 .= "[UUK05] " . str_replace("#MODULE_DIR#", $_1590613717, GetMessage("SUPP_UK_WR_MODIR")) . ". ";
				if ($_1123571860 == "") if (!file_exists($_361977632) || !is_dir($_361977632)) $_1123571860 .= "[UUK06] " . str_replace("#DIR#", $_361977632, GetMessage("SUPP_UK_NO_FDIR")) . ". ";
				if ($_1123571860 == "") if (!is_readable($_361977632)) $_1123571860 .= "[UUK07] " . str_replace("#DIR#", $_361977632, GetMessage("SUPP_UK_READ_FDIR")) . ". ";
				$_1305681727 = array();
				if ($_1123571860 == "") {
					$_1177408942 = @opendir($_361977632);
					if ($_1177408942) {
						while (false !== ($_239640225 = readdir($_1177408942))) {
							if (substr($_239640225, 0, 7) == "updater") {
								$_352437796 = "N";
								if (is_file($_361977632 . "/" . $_239640225)) {
									$_705722731 = substr($_239640225, 7, strlen($_239640225) - 11);
									if (substr($_239640225, strlen($_239640225) - 9) == "_post.php") {
										$_352437796 = "Y";
										$_705722731 = substr($_239640225, 7, strlen($_239640225) - 16);
									}
									$_1305681727[] = array("/" . $_239640225, trim($_705722731), $_352437796);
								} elseif (file_exists($_361977632 . "/" . $_239640225 . "/index.php")) {
									$_705722731 = substr($_239640225, 7);
									if (substr($_239640225, strlen($_239640225) - 5) == "_post") {
										$_352437796 = "Y";
										$_705722731 = substr($_239640225, 7, strlen($_239640225) - 12);
									}
									$_1305681727[] = array("/" . $_239640225 . "/index.php", trim($_705722731), $_352437796);
								}
								if ($_1311398460) CUpdateClient::CopyDirFiles($_361977632 . "/" . $_239640225, $_SERVER["DOCUMENT_ROOT"] . US_SAVE_UPDATERS_DIR . "/" . $_196288623[$_443696004] . "/" . $_239640225, $_1123571860, false);
							}
						}
						closedir($_1177408942);
					}
					$_346373140 = count($_1305681727);
					for ($_1118145356 = 0; $_1118145356 < $_346373140 - 1; $_1118145356++) {
						for ($_1341177660 = $_1118145356 + 1; $_1341177660 < $_346373140; $_1341177660++) {
							if (CUpdateClient::CompareVersions($_1305681727[$_1118145356][1], $_1305681727[$_1341177660][1]) > 0) {
								$_816185724                = $_1305681727[$_1118145356];
								$_1305681727[$_1118145356] = $_1305681727[$_1341177660];
								$_1305681727[$_1341177660] = $_816185724;
							}
						}
					}
				}
				if ($_1123571860 == "") {
					if (strtolower($DB->type) == "mysql" && defined("MYSQL_TABLE_TYPE") && MYSQL_TABLE_TYPE <> "") {
						$DB->Query("SET storage_engine = '" . MYSQL_TABLE_TYPE . "'", true);
					}
				}
				if ($_1123571860 == "") {
					for ($_1118145356 = 0, $_346373140 = count($_1305681727); $_1118145356 < $_346373140; $_1118145356++) {
						if ($_1305681727[$_1118145356][2] == "N") {
							$_897742753 = "";
							CUpdateClient::RunUpdaterScript($_361977632 . $_1305681727[$_1118145356][0], $_897742753, "/bitrix/updates/" . $_426046905 . "/" . $_196288623[$_443696004], $_196288623[$_443696004]);
							if ($_897742753 <> "") {
								$_1123571860 .= str_replace("#MODULE#", $_196288623[$_443696004], str_replace("#VER#", $_1305681727[$_1118145356][1], GetMessage("SUPP_UK_UPDN_ERR"))) . ": " . $_897742753 . ". ";
								$_1123571860 .= str_replace("#MODULE#", $_196288623[$_443696004], GetMessage("SUPP_UK_UPDN_ERR_BREAK")) . " ";
								break;
							}
						}
					}
				}
				if ($_1123571860 == "") CUpdateClient::CopyDirFiles($_361977632, $_1590613717, $_1123571860, true, $_193561183);
				if ($_1123571860 == "") {
					for ($_1118145356 = 0, $_346373140 = count($_1305681727); $_1118145356 < $_346373140; $_1118145356++) {
						if ($_1305681727[$_1118145356][2] == "Y") {
							$_897742753 = "";
							CUpdateClient::RunUpdaterScript($_361977632 . $_1305681727[$_1118145356][0], $_897742753, "/bitrix/updates/" . $_426046905 . "/" . $_196288623[$_443696004], $_196288623[$_443696004]);
							if ($_897742753 <> "") {
								$_1123571860 .= str_replace("#MODULE#", $_196288623[$_443696004], str_replace("#VER#", $_1305681727[$_1118145356][1], GetMessage("SUPP_UK_UPDY_ERR"))) . ": " . $_897742753 . ". ";
								$_1123571860 .= str_replace("#MODULE#", $_196288623[$_443696004], GetMessage("SUPP_UK_UPDN_ERR_BREAK")) . " ";
								break;
							}
						}
					}
				}
				if ($_1123571860 <> "") $_2136808367 .= $_1123571860;
			}
			CUpdateClient::ClearUpdateFolder($_1370972511);
		}
		CUpdateClient::AddMessage2Log("TIME UpdateStepModules " . round(microtime(true) - $_418914584, 3) . " sec");
		if ($_2136808367 <> "") {
			CUpdateClient::AddMessage2Log($_2136808367, "USM");
			$_753204024 .= $_2136808367;
			return false;
		} else {
			$GLOBALS["BX_REAL_UPDATED_MODULES"] = $_196288623;
			if (function_exists("ExecuteModuleEventEx")) {
				foreach (GetModuleEvents("main", "OnModuleUpdate", true) as $_1599781934) {
					ExecuteModuleEventEx($_1599781934, $_196288623);
				}
			}
			return true;
		}
	}

	public static function ClearUpdateFolder($_1370972511)
	{
		CUpdateClient::DeleteDirFilesEx($_1370972511);
		CUpdateClient::resetAccelerator();
	}

	public static function RunUpdaterScript($_2036567828, &$_753204024, $_361977632, $_944152425)
	{
		global $DBType, $DB, $APPLICATION, $USER;
		if (!isset($GLOBALS["UPDATE_STRONG_UPDATE_CHECK"]) || ($GLOBALS["UPDATE_STRONG_UPDATE_CHECK"] != "Y" && $GLOBALS["UPDATE_STRONG_UPDATE_CHECK"] != "N")) {
			$GLOBALS["UPDATE_STRONG_UPDATE_CHECK"] = ((US_CALL_TYPE != "DB") ? COption::GetOptionString("main", "strong_update_check", "Y") : "Y");
		}
		$strongUpdateCheck = $GLOBALS["UPDATE_STRONG_UPDATE_CHECK"];
		$DOCUMENT_ROOT     = $_SERVER["DOCUMENT_ROOT"];
		$_2036567828       = str_replace("\\", "/", $_2036567828);
		$updaterPath       = dirname($_2036567828);
		$updaterPath       = substr($updaterPath, strlen($_SERVER["DOCUMENT_ROOT"]));
		$updaterPath       = trim($updaterPath, " /\"");
		if ($updaterPath <> "")
			$updaterPath = "/" . $updaterPath;
		$updaterName = substr($_2036567828, strlen($_SERVER["DOCUMENT_ROOT"]));

		CUpdateClient::AddMessage2Log("Run updater '" . $updaterName . "'", "CSURUS1");
		$updater = new CUpdater();

		$updater->Init($updaterPath, $DB->type, $updaterName, $_361977632, $_944152425, US_CALL_TYPE);
		$errorMessage = "";
		include($_2036567828);
		if ($errorMessage <> "")
			$_753204024 .= $errorMessage;
		if (is_array($updater->errorMessage) && !empty($updater->errorMessage))
			$_753204024 .= implode("\n", $updater->errorMessage);
		unset($updater);
	}

	public static function CompareVersions($_1932684487, $_637012980)
	{
		$_1932684487 = trim($_1932684487);
		$_637012980  = trim($_637012980);
		if ($_1932684487 == $_637012980) return 0;
		$_539918193 = explode(".", $_1932684487);
		$_964074558 = explode(".", $_637012980);
		if (intval($_539918193[0]) > intval($_964074558[0]) || intval($_539918193[0]) == intval($_964074558[0]) && intval($_539918193[1]) > intval($_964074558[1]) || intval($_539918193[0]) == intval($_964074558[0]) && intval($_539918193[1]) == intval($_964074558[1])
			&& intval($_539918193[2]) > intval($_964074558[2])) {
			return 1;
		}
		if (intval($_539918193[0]) == intval($_964074558[0]) && intval($_539918193[1]) == intval($_964074558[1]) && intval($_539918193[2]) == intval($_964074558[2])) {
			return 0;
		}
		return -1;
	}

	public static function checkValid()
	{
		$_1596961748 = file_get_contents($_SERVER["DOCUMENT_ROOT"] . '/bitrix/modules/main/include.php');
		$_246160386  = strpos($_1596961748, "/*ZDUyZmZ");
		if ($_246160386 !== false) {
			$_1525768972 = strpos($_1596961748, "/**/", $_246160386);
			if ($_1525768972 !== false) {
				$_1596961748 = substr($_1596961748, $_246160386, $_1525768972 - $_246160386);
				$_2091664644 = strpos($_1596961748, "*/");
				if ($_2091664644 !== false) {
					$_466179878  = substr($_1596961748, 9, $_2091664644 - 9);
					$_1596961748 = substr($_1596961748, $_2091664644 + 2);
					$_996201139  = base64_encode(md5($_1596961748));
					if ($_466179878 === $_996201139) return true;
				}
			}
		}
		if (substr($_1596961748, 0, strlen("<? \$GLOBALS['_____\")) === \"<? \$GLOBALS['_____")))
			return true;

		if (md5(CUpdateClient::GetLicenseKey() . "check") === "31ea312de1006771f0a4e5b25a90932c")
			return true;
		return false;
	}

	public static function GetUpdatesList(&$_753204024, $_1530138095 = false, $_1474158358 = "Y")
	{
		$_2136808367 = "";
		CUpdateClient::AddMessage2Log("exec CUpdateClient::GetUpdatesList");
		$_1622907824 = CUpdateClient::CollectRequestData($_2136808367, $_1530138095, $_1474158358);
		if ($_1622907824 == "" || $_2136808367 <> "") {
			$_753204024 .= $_2136808367;
			CUpdateClient::AddMessage2Log("empty query list", "GUL01");
			return false;
		}
		CUpdateClient::AddMessage2Log(preg_replace("/LICENSE_KEY=[^&]*/i", "LICENSE_KEY=X", $_1622907824));
		$_418914584  = microtime(true);
		$_1596961748 = CUpdateClient::GetHTTPPage("list", $_1622907824, $_2136808367);
		CUpdateClient::AddMessage2Log("TIME GetUpdatesList(request) " . round(microtime(true) - $_418914584, 3) . " sec");
		$_1139428298 = array();
		if ($_2136808367 == "")
			CUpdateClient::__1244221643($_1596961748, $_1139428298, $_2136808367);
		if ($_2136808367 == "") {
			if (!isset($_1139428298["DATA"]) || !is_array($_1139428298["DATA"]))
				$_2136808367 .= "[UGAUT01] " . GetMessage("SUPP_GAUT_SYSERR") . ". ";
		}
		if ($_2136808367 == "") {
			$_1139428298 = $_1139428298["DATA"]["#"];
			if ((!isset($_1139428298["CLIENT"]) || !is_array($_1139428298["CLIENT"])) && (!isset($_1139428298["ERROR"]) || !is_array($_1139428298["ERROR"]))) {
				$_2136808367 .= "[UGAUT01] " . GetMessage("SUPP_GAUT_SYSERR") . ". ";
			}
			$_798512439 = $_SERVER["DOCUMENT_ROOT"] . "/bitrix/updates/update_archive.gz";
			if (file_exists($_798512439)) {
				@unlink($_798512439);
			}
			$_773946775 = $_SERVER["DOCUMENT_ROOT"] . "/bitrix/updates/update_archive.gz.log";
			if (file_exists($_773946775)) {
				@unlink($_773946775);
			}
		}
		if ($_2136808367 <> "") {
			CUpdateClient::AddMessage2Log($_2136808367, "GUL02");
			$_753204024 .= $_2136808367;
			return false;
		} else return $_1139428298;
	}

	public static function GetHTTPPage($_554151431, $_1085691492, &$_753204024)
	{
		global $DB;
		CUpdateClient::AddMessage2Log("exec CUpdateClient::GetHTTPPage");
		if ($_554151431 == "LIST") $_554151431 = "us_updater_list.php"; elseif ($_554151431 == "STEPM") $_554151431 = "us_updater_modules.php";
		elseif ($_554151431 == "STEPL") $_554151431 = "us_updater_langs.php";
		elseif ($_554151431 == "STEPH") $_554151431 = "us_updater_helps.php";
		elseif ($_554151431 == "ACTIV") $_554151431 = "us_updater_actions.php";
		elseif ($_554151431 == "REG") $_554151431 = "us_updater_register.php";
		elseif ($_554151431 == "SRC") $_554151431 = "us_updater_sources.php";
		$_1399279890 = static::__690101446();
		$_2059576749 = @fsockopen($_1399279890["SOCKET_IP"], $_1399279890["SOCKET_PORT"], $_2106056148, $_1116920530, 120);
		if ($_2059576749) {
			$_358206942 = "";
			if ($_1399279890["USE_PROXY"]) {
				$_358206942 .= "POST http://" . $_1399279890["IP"] . "/bitrix/updates/" . $_554151431 . " HTTP/1.0";
				if ($_1399279890["PROXY_USERNAME"] <> "") $_358206942 .= "Proxy-Authorization: Basic " . base64_encode($_1399279890["PROXY_USERNAME"] . ":" . $_1399279890["PROXY_PASSWORD"]) . "";
			} else {
				$_358206942 .= "POST /bitrix/updates/" . $_554151431 . " HTTP/1.0";
			}
			$_946673907  = self::__416130040(US_BASE_MODULE, "crc_code");
			$_1085691492 .= "&spd=" . urlencode($_946673907);
			if (defined("BX_UTF")) $_1085691492 .= "&utf=" . urlencode("Y"); else $_1085691492 .= "&utf=" . urlencode("N");
			$_1152618521 = $DB->GetVersion();
			$_1085691492 .= "&dbv=" . urlencode($_1152618521 ? $_1152618521 : "");
			$_1085691492 .= "&NS=" . COption::GetOptionString("main", "update_site_ns", "");
			$_1085691492 .= "&KDS=" . COption::GetOptionString("main", "update_devsrv", "");
			$_358206942  .= "User-Agent: BitrixSMUpdater";
			$_358206942  .= "Accept: */*";
			$_358206942  .= "Host: " . $_1399279890["IP"] . "";
			$_358206942  .= "Accept-Language: en";
			$_358206942  .= "Content-type: application/x-www-form-urlencoded";
			$_358206942  .= "Content-length: " . strlen($_1085691492) . "";
			$_358206942  .= "$_1085691492";
			$_358206942  .= "";
			fputs($_2059576749, $_358206942);
			$_475355530 = false;

			while (!feof($_2059576749)) {
				$_1907623322 = fgets($_2059576749, 4096);
				if ($_1907623322 != "") {
					if (preg_match("/Transfer-Encoding: +chunked/i", $_1907623322)) $_475355530 = true;
				} else {
					break;
				}
			}
			$_1596961748 = "";
			if ($_475355530) {
				$_1220677881 = 4096;
				$_460270920  = 0;
				$_1907623322 = fgets($_2059576749, $_1220677881);
				$_1907623322 = strtolower($_1907623322);
				$_1816171785 = "";
				$_443696004  = 0;
				while ($_443696004 < strlen($_1907623322) && in_array($_1907623322[$_443696004], array("0", "1", "2", "3", "4", "5", "6", "7", "8", "9", "a", "b", "c", "d", "e", "f"))) {
					$_1816171785 .= $_1907623322[$_443696004];
					$_443696004++;
				}
				$_1251326892 = hexdec($_1816171785);
				while ($_1251326892 > 0) {
					$_132240663  = 0;
					$_1001548312 = (($_1251326892 > $_1220677881) ? $_1220677881 : $_1251326892);
					while ($_1001548312 > 0 && $_1907623322 = fread($_2059576749, $_1001548312)) {
						$_1596961748 .= $_1907623322;
						$_132240663  += strlen($_1907623322);
						$_1100949267 = $_1251326892 - $_132240663;
						$_1001548312 = (($_1100949267 > $_1220677881) ? $_1220677881 : $_1100949267);
					}
					$_460270920  += $_1251326892;
					$_1907623322 = fgets($_2059576749, $_1220677881);
					$_1907623322 = fgets($_2059576749, $_1220677881);
					$_1907623322 = strtolower($_1907623322);
					$_1816171785 = "";
					$_443696004  = 0;
					while ($_443696004 < strlen($_1907623322) && in_array($_1907623322[$_443696004], array("0", "1", "2", "3", "4", "5", "6", "7", "8", "9", "a", "b", "c", "d", "e", "f"))) {
						$_1816171785 .= $_1907623322[$_443696004];
						$_443696004++;
					}
					$_1251326892 = hexdec($_1816171785);
				}
			} else {
				while ($_1907623322 = fread($_2059576749, 4096)) $_1596961748 .= $_1907623322;
			}
			fclose($_2059576749);
		} else {
			$_1596961748 = "";
			if (class_exists("CUtil") && method_exists("CUtil", "ConvertToLangCharset")) $_1116920530 = CUtil::ConvertToLangCharset($_1116920530);
			$_753204024 .= GetMessage("SUPP_GHTTP_ER") . ": [" . $_2106056148 . "] " . $_1116920530 . ". ";
			if ($_2106056148 <= 0) $_753204024 .= GetMessage("SUPP_GHTTP_ER_DEF") . " ";
			CUpdateClient::AddMessage2Log("Error connecting to " . $_1399279890["SOCKET_IP"] . ": [" . $_2106056148 . "] " . $_1116920530, "ERRCONN");
		}
		return $_1596961748;
	}


	private static function __1706490244(&$_1760501310, $_761780272)
	{
		$_28696865 = $_761780272->getName();
		if (!isset($_1760501310[$_28696865])) $_1760501310[$_28696865] = array();
		$_2046167719 = array("@" => array());
		foreach ($_761780272->attributes() as $_834432614 => $_613861993) {
			$_2046167719["@"][$_834432614] = (string)$_613861993;
		}
		foreach ($_761780272->children() as $_1561229535) {
			if (!isset($_2046167719["#"])) $_2046167719["#"] = array();
			self::__1706490244($_2046167719["#"], $_1561229535);
		}
		if (!isset($_2046167719["#"])) $_2046167719["#"] = (string)$_761780272;
		$_1760501310[$_28696865][] = $_2046167719;
	}

	private static function __1521549544($_1522976280)
	{
		$_1760501310 = array();
		if (!defined("BX_UTF") || !class_exists("\SimpleXMLElement")) {
			$_33757510 = new CUpdatesXML();
			if ($_33757510->LoadString($_1522976280) && $_33757510->GetTree()) $_1760501310 = $_33757510->GetArray();
			return $_1760501310;
		}
		if (strpos($_1522976280, pack("CCC", 239, 187, 191)) === 0) $_1522976280 = substr($_1522976280, 3);
		if (strpos($_1522976280, "<?") !== 0) $_1522976280 = "<" . "?xml version='1.0' encoding='" . (defined("BX_UTF") ? "utf-8" : "windows-1251") . "' standalone='yes'?" . ">
	" . $_1522976280;
		$_761780272 = new \SimpleXMLElement($_1522976280);
		self::__1706490244($_1760501310, $_761780272);
		$_1760501310["DATA"] = $_1760501310["DATA"][0];
		if (!defined("BX_UTF")) $_1760501310 = \Bitrix\Main\Text\Encoding::convertEncoding($_1760501310, "utf-8", "windows-1251");
		return $_1760501310;
	}

	private static function __1244221643(&$_242112568, &$_187816114, &$_753204024)
	{
		$_2136808367 = "";
		$_187816114  = array();
		CUpdateClient::AddMessage2Log("exec CUpdateClient::ParseServerData");
		if ($_242112568 == "") $_2136808367 .= "[UPSD01] " . GetMessage("SUPP_AS_EMPTY_RESP") . ". ";
		if ($_2136808367 == "") {
			if (substr($_242112568, 0, strlen("<DATA>")) != "<DATA>" && CUpdateClient::IsGzipInstalled()) $_242112568 = @gzuncompress($_242112568);
			if (substr($_242112568, 0, strlen("<DATA>")) != "<DATA>") {
				CUpdateClient::AddMessage2Log(substr($_242112568, 0, 100), "UPSD02");
				$_2136808367 .= "[UPSD02] " . GetMessage("SUPP_PSD_BAD_RESPONSE") . ". ";
			}
		}
		if ($_2136808367 == "") {
			$_187816114 = self::__1521549544($_242112568);
			if (!is_array($_187816114) || !isset($_187816114["DATA"]) || !is_array($_187816114["DATA"])) $_2136808367 .= "[UPSD03] " . GetMessage("SUPP_PSD_BAD_TRANS") . ". ";
		}
		if ($_2136808367 == "") {
			if (isset($_187816114["DATA"]["#"]["RESPONSE"])) {
				$_946673907 = $_187816114["DATA"]["#"]["RESPONSE"][0]["@"]["CRC_CODE"];
				if ($_946673907 <> "") COption::SetOptionString(US_BASE_MODULE, "crc_code", $_946673907);
			}
			if (isset($_187816114["DATA"]["#"]["CLIENT"])) {
				CUpdateClient::__ApplyLicenseInfo($_187816114["DATA"]["#"]["CLIENT"][0]["@"]);
			}
		}
		if ($_2136808367 == "") {
			if (isset($_187816114["DATA"]["#"]["COUNTER"])) CUpdateClient::__328632251($_187816114["DATA"]["#"]["COUNTER"]);
		}
		if ($_2136808367 <> "") {
			CUpdateClient::AddMessage2Log($_2136808367, "CUPSD");
			$_753204024 .= $_2136808367;
			return false;
		} else return true;
	}

	public static function CollectRequestData(&$_753204024, $_1530138095 = false, $_1474158358 = "Y", $_2142182691 = array(), $_1523107763 = array(), $_164957727 = array())
	{
		$_2136808367 = "";
		if ($_1530138095 === false) {
			$_1530138095 = LANGUAGE_ID;
		}
		$_1474158358 = (is_numeric($_1474158358) ? intval($_1474158358) : (($_1474158358 == "N") ? "N" : "Y"));
		CUpdateClient::AddMessage2Log("exec CUpdateClient::CollectRequestData");
		CUpdateClient::CheckDirPath($_SERVER["DOCUMENT_ROOT"] . "/bitrix/updates/");
		$_1450490487 = CUpdateClient::GetCurrentModules($_2136808367);
		$_546637926  = CUpdateClient::GetCurrentLanguages($_2136808367);
		$_1133438944 = (CUpdateExpertMode::isEnabled() && CUpdateExpertMode::isCorrectModulesStructure($_2142182691));
		if ($_1133438944) {
			$_1450490487 = CUpdateExpertMode::processModulesFrom($_2142182691, $_1450490487);
		}
		if ($_2136808367 == "") {
			$GLOBALS["DB"]->GetVersion();
			$_522224472  = "LICENSE_KEY=" . urlencode(md5(CUpdateClient::GetLicenseKey())) . "&lang=" . urlencode($_1530138095) . "&SUPD_VER=" . urlencode(UPDATE_SYSTEM_VERSION_A) . "&VERSION=" . urlencode(SM_VERSION) . "&TYPENC=" . ((defined("DEMO") && DEMO == "Y") ? "D" : ((defined("ENCODE") && ENCODE == "Y") ? "E" : ((defined("TIMELIMIT_EDITION") && TIMELIMIT_EDITION == "Y") ? "T" : "F"))) . "&SUPD_STS=" . urlencode(CUpdateClient::__GetFooPath()) . "&SUPD_URS=" . urlencode(CUpdateClient::__GetFooPath1()) . "&SUPD_DBS=" . urlencode($GLOBALS["DB"]->type) . "&XE=" . urlencode((isset($GLOBALS["DB"]->XE) && $GLOBALS["DB"]->XE) ? "Y" : "N") . "&CLIENT_SITE=" . urlencode($_SERVER["SERVER_NAME"]) . "&SERVER_NAME=" . urlencode(self::GetServerName()) . "&CHHB=" . urlencode($_SERVER["HTTP_HOST"]) . "&CSAB=" . urlencode($_SERVER["SERVER_ADDR"]) . "&SUID=" . urlencode(CUpdateClient::GetUniqueId()) . "&CANGZIP=" . urlencode((CUpdateClient::IsGzipInstalled()) ? "Y" : "N") . "&CLIENT_PHPVER=" . urlencode(phpversion()) . "&stable=" . urlencode($_1474158358) . "&mbfo=" . urlencode((int)ini_get("mbstring.func_overload")) . "&NGINX=" . urlencode(COption::GetOptionString("main", "update_use_nginx", "Y")) . "&SMD=" . urlencode(COption::GetOptionString("main", "update_safe_mode", "N")) . "&rerere=" . urlencode(CUpdateClient::checkValid() ? "Y" : "N") . "&" . CUpdateClient::ModulesArray2Query($_1450490487, "bitm_") . "&" . CUpdateClient::ModulesArray2Query($_546637926, "bitl_");
			$_1874476756 = "";
			if ($_1133438944) {
				$_1627630432 = CUpdateExpertMode::extractModulesTo($_2142182691);
				$_522224472  .= "&expert_requested_modules=" . urlencode(json_encode($_1627630432));
				$_2142182691 = array_keys($_1627630432);
			}
			if (CUpdateExpertMode::isIncludeTmpUpdatesEnabled()) {
				$_522224472 .= "&expert_include_tmp_updates=y";
			}
			if (!empty($_2142182691)) {
				for ($_443696004 = 0, $_1167463684 = count($_2142182691); $_443696004 < $_1167463684; $_443696004++) {
					if ($_1874476756 <> "") $_1874476756 .= ",";
					$_1874476756 .= $_2142182691[$_443696004];
				}
			}
			if ($_1874476756 <> "") {
				$_522224472 .= "&requested_modules=" . urlencode($_1874476756);
			}
			$_1874476756 = "";
			if (!empty($_1523107763)) {
				for ($_443696004 = 0, $_1167463684 = count($_1523107763); $_443696004 < $_1167463684; $_443696004++) {
					if ($_1874476756 <> "") $_1874476756 .= ",";
					$_1874476756 .= $_1523107763[$_443696004];
				}
			}
			if ($_1874476756 <> "") $_522224472 .= "&requested_langs=" . urlencode($_1874476756);
			$_1874476756 = "";
			if (!empty($_164957727)) {
				for ($_443696004 = 0, $_1167463684 = count($_164957727); $_443696004 < $_1167463684; $_443696004++) {
					if ($_1874476756 <> "") $_1874476756 .= ",";
					$_1874476756 .= $_164957727[$_443696004];
				}
			}
			if ($_1874476756 <> "") $_522224472 .= "&requested_helps=" . urlencode($_1874476756);
			if (defined("FIRST_EDITION") && constant("FIRST_EDITION") == "Y") {
				$_1167463684 = 1;
				if (CModule::IncludeModule("iblock")) {
					$_1167463684 = 0;
					$_10329247   = CIBlock::GetList(array(), array("CHECK_PERMISSIONS" => "N"));
					while ($_10329247->Fetch()) $_1167463684++;
				}
				$_522224472  .= "&SUPD_PIBC=" . $_1167463684;
				$_522224472  .= "&SUPD_PUC=" . CUser::GetCount();
				$_1167463684 = 0;
				$_1754704762 = "";
				$_1536162850 = "";
				$_837377958  = CSite::GetList($_1754704762, $_1536162850, array());
				while ($_837377958->Fetch()) $_1167463684++;
				$_522224472 .= "&SUPD_PSC=" . $_1167463684;
			}
			if (defined("INTRANET_EDITION") && constant("INTRANET_EDITION") == "Y") {
				$_1645043615 = array();
				$_969985619  = COption::GetOptionString("main", "~cpf_map_value", "");
				if ($_969985619 <> "") {
					$_969985619  = base64_decode($_969985619);
					$_1645043615 = unserialize($_969985619, array("allowed_classes" => false));
					if (!is_array($_1645043615)) $_1645043615 = array();
				}
				if (empty($_1645043615)) $_1645043615 = array("e" => array(), "f" => array());
				$_836364742 = "";
				foreach ($_1645043615["e"] as $_1813017612 => $_1561559754) {
					if ($_1561559754[0] == "F" || $_1561559754[0] == "D") {
						if ($_836364742 <> "") $_836364742 .= ",";
						$_836364742 .= $_1813017612 . ":" . $_1561559754[0] . ":" . (isset($_1561559754[1]) ? $_1561559754[1] : "");
					}
				}
				$_522224472 .= "&SUPD_OFC=" . urlencode($_836364742);
			}
			if (defined("BUSINESS_EDITION") && constant("BUSINESS_EDITION") == "Y") {
				$_998125995 = array();
				$_969985619 = COption::GetOptionString("main", "~cpf_map_value", "");
				if ($_969985619 <> "") {
					$_969985619 = base64_decode($_969985619);
					$_998125995 = unserialize($_969985619, array("allowed_classes" => false));
					if (!is_array($_998125995)) $_998125995 = array("Small");
				}
				if (empty($_998125995)) $_998125995 = array("Small");
				$_522224472 .= "&SUPD_OFC=" . urlencode(implode(",", $_998125995));
			}
			if (CModule::IncludeModule("cluster") && class_exists("CCluster")) $_522224472 .= "&SUPD_SRS=" . urlencode(CCluster::getServersCount()); else $_522224472 .= "&SUPD_SRS=" . urlencode("RU");
			if (method_exists("CHTMLPagesCache", "IsOn") && method_exists("CHTMLPagesCache", "IsCompositeEnabled") && CHTMLPagesCache::IsOn() && CHTMLPagesCache::IsCompositeEnabled()) $_522224472 .= "&SUPD_CMP=" . urlencode("Y"); else $_522224472 .= "&SUPD_CMP=" . urlencode("N");
			global $DB;
			if ($DB->TableExists("b_sale_order") || $DB->TableExists("B_SALE_ORDER")) $_522224472 .= "&SALE_15=" . urlencode((COption::GetOptionString("main", "~sale_converted_15", "N") == "Y" ? "Y" : "N")); else $_522224472 .= "&SALE_15=" . urlencode("Y");
			$_1178254619 = CUpdateClient::getNewLicenseSignedKey();
			$_522224472  .= "&LICENSE_SIGNED=" . urlencode($_1178254619 . "-" . COption::GetOptionString("main", $_1178254619, "N"));
			return $_522224472;
		}
		CUpdateClient::AddMessage2Log($_2136808367, "NCRD01");
		$_753204024 .= $_2136808367;
		return false;
	}

	public static function ModulesArray2Query($_1450490487, $_1890671657 = "bitm_")
	{
		$_510667792 = "";
		if (is_array($_1450490487)) {
			foreach ($_1450490487 as $_489120050 => $_1706943504) {
				if ($_510667792 <> "") $_510667792 .= "&";
				$_510667792 .= $_1890671657 . $_489120050 . "=" . urlencode($_1706943504);
			}
		}
		return $_510667792;
	}

	protected static function GetServerName()
	{
		global $DB;
		$_322260007 = $DB->Query("select SERVER_NAME from b_lang where DEF = 'Y'");
		if ($_322260007 && ($_347104408 = $_322260007->Fetch()) && $_347104408["SERVER_NAME"] != "") {
			return $_347104408["SERVER_NAME"];
		}
		return self::__416130040("main", "server_name");
	}

	public static function IsGzipInstalled()
	{
		if (function_exists("gzcompress")) return COption::GetOptionString("main", "update_is_gzip_installed", "Y") == "Y";
		return false;
	}

	public static function GetCurrentModules(&$_753204024, $_922511211 = false)
	{
		$_1450490487 = array();
		$_1177408942 = @opendir($_SERVER["DOCUMENT_ROOT"] . US_SHARED_KERNEL_PATH . "/modules");
		if ($_1177408942) {
			if ($_922511211 === false || is_array($_922511211) && in_array("main", $_922511211)) {
				if (file_exists($_SERVER["DOCUMENT_ROOT"] . US_SHARED_KERNEL_PATH . "/modules/main/classes/general/version.php") && is_file($_SERVER["DOCUMENT_ROOT"] . US_SHARED_KERNEL_PATH . "/modules/main/classes/general/version.php")) {
					$_700691280 = file_get_contents($_SERVER["DOCUMENT_ROOT"] . US_SHARED_KERNEL_PATH . "/modules/main/classes/general/version.php");
					preg_match("/define\s*\(\s*\"SM_VERSION\"\s*,\s*\"(\d + \.\d + \.\d +)\"\s*\)\s*/im", $_700691280, $_1699432342);
					$_1450490487["main"] = $_1699432342[1];
				}
				if ($_1450490487["main"] == "") {
					CUpdateClient::AddMessage2Log(GetMessage("SUPP_GM_ERR_DMAIN"), "Ux09");
					$_753204024 .= "[Ux09] " . GetMessage("SUPP_GM_ERR_DMAIN") . ". ";
				}
			}
			while (false !== ($_239640225 = readdir($_1177408942))) {
				if (is_dir($_SERVER["DOCUMENT_ROOT"] . US_SHARED_KERNEL_PATH . "/modules/" . $_239640225) && $_239640225 != "." && $_239640225 != ".." && $_239640225 != "main" && strpos($_239640225, ".") === false) {
					if ($_922511211 === false || is_array($_922511211) && in_array($_239640225, $_922511211)) {
						$_1162295015 = $_SERVER["DOCUMENT_ROOT"] . US_SHARED_KERNEL_PATH . "/modules/" . $_239640225;
						if (file_exists($_1162295015 . "/install/index.php")) {
							$_1546447603 = CUpdateClient::GetModuleInfo($_1162295015);
							if (!isset($_1546447603["VERSION"]) || $_1546447603["VERSION"] == "") {
								CUpdateClient::AddMessage2Log(str_replace("#MODULE#", $_239640225, GetMessage("SUPP_GM_ERR_DMOD")), "Ux11");
								$_753204024 .= "[Ux11] " . str_replace("#MODULE#", $_239640225, GetMessage("SUPP_GM_ERR_DMOD")) . ". ";
							} else {
								$_1450490487[$_239640225] = $_1546447603["VERSION"];
							}
						}
					}
				}
			}
			closedir($_1177408942);
		} else {
			CUpdateClient::AddMessage2Log(GetMessage("SUPP_GM_NO_KERNEL"), "Ux15");
			$_753204024 .= "[Ux15] " . GetMessage("SUPP_GM_NO_KERNEL") . ". ";
		}
		return $_1450490487;
	}

	public static function __GetFooPath()
	{
		if (!class_exists("CLang")) {
			return "RA";
		} else {
			$_1167463684 = 0;
			$_326250928  = $_1938863488 = "";
			$_2036567828 = CLang::GetList($_326250928, $_1938863488, array("ACTIVE" => "Y"));
			while ($_2036567828->Fetch()) $_1167463684++;
			return $_1167463684;
		}
	}

	public static function GetCurrentNumberOfUsers()
	{
		return CUpdateClient::__GetFooPath1();
	}

	public static function GetCurrentLanguages(&$_753204024, $_922511211 = false)
	{
		$_651413951  = array();
		$_1438931728 = $_SERVER["DOCUMENT_ROOT"] . US_SHARED_KERNEL_PATH . "/modules/main/lang";
		$_1177408942 = @opendir($_1438931728);
		if ($_1177408942) {
			while (false !== ($_239640225 = readdir($_1177408942))) {
				if (is_dir($_1438931728 . "/" . $_239640225) && $_239640225 != "." && $_239640225 != "..") {
					if ($_922511211 === false || is_array($_922511211) && in_array($_239640225, $_922511211)) {
						$_1957310088 = "";
						if (file_exists($_1438931728 . "/" . $_239640225 . "/supd_lang_date.dat")) {
							$_1957310088 = file_get_contents($_1438931728 . "/" . $_239640225 . "/supd_lang_date.dat");
							$_1957310088 = preg_replace("/\D+/", "", $_1957310088);
							if (strlen($_1957310088) != 8) {
								CUpdateClient::AddMessage2Log(str_replace("#LANG#", $_239640225, GetMessage("SUPP_GL_ERR_DLANG")), "UGL01");
								$_753204024  .= "[UGL01] " . str_replace("#LANG#", $_239640225, GetMessage("SUPP_GL_ERR_DLANG")) . ". ";
								$_1957310088 = "";
							}
						}
						$_651413951[$_239640225] = $_1957310088;
					}
				}
			}
			closedir($_1177408942);
		}
		$_161636322  = false;
		$_326250928  = "sort";
		$_1938863488 = "asc";
		if (class_exists("CLanguage")) $_161636322 = CLanguage::GetList($_326250928, $_1938863488, array("ACTIVE" => "Y")); elseif (class_exists("CLang")) $_161636322 = CLang::GetList($_326250928, $_1938863488, array("ACTIVE" => "Y"));
		if ($_161636322 === false) {
			CUpdateClient::AddMessage2Log(GetMessage("SUPP_GL_WHERE_LANGS"), "UGL00");
			$_753204024 .= "[UGL00] " . GetMessage("SUPP_GL_WHERE_LANGS") . ". ";
		} else {
			while ($_1200678719 = $_161636322->Fetch()) {
				if ($_922511211 === false || is_array($_922511211) && in_array($_1200678719["LID"], $_922511211)) {
					if (!array_key_exists($_1200678719["LID"], $_651413951)) {
						$_651413951[$_1200678719["LID"]] = "";
					}
				}
			}
			if ($_922511211 === false && empty($_651413951)) {
				CUpdateClient::AddMessage2Log(GetMessage("SUPP_GL_NO_SITE_LANGS"), "UGL02");
				$_753204024 .= "[UGL02] " . GetMessage("SUPP_GL_NO_SITE_LANGS") . ". ";
			}
		}
		return $_651413951;
	}

	public static function __GetFooPath1()
	{
		if (method_exists('\Bitrix\Main\License', 'getActiveUsersCount')) {
			$_1662546114 = new \Bitrix\Main\License();
			return $_1662546114->getActiveUsersCount();
		} elseif (IsModuleInstalled("intranet")) {
			$_783774585  = "SELECT COUNT(U.ID) as C FROM b_user U WHERE U.ACTIVE = 'Y' AND U.LAST_LOGIN IS NOT NULL AND EXISTS(SELECT 'x' FROM b_utm_user UF, b_user_field F WHERE F.ENTITY_ID = 'USER' AND F.FIELD_NAME = 'UF_DEPARTMENT' AND UF.FIELD_ID = F.ID AND UF.VALUE_ID = U.ID AND UF.VALUE_INT IS NOT NULL AND UF.VALUE_INT <> 0)";
			$_1150149515 = $GLOBALS["DB"]->Query($_783774585, true);
			if ($_1150149515 && ($_187816114 = $_1150149515->Fetch())) {
				return $_187816114["C"];
			}
		}
		return 0;
	}

	public static function GetCurrentHelps(&$_753204024, $_922511211 = false)
	{
		$_1519468821 = array();
		$_1796617134 = $_SERVER["DOCUMENT_ROOT"] . US_SHARED_KERNEL_PATH . "/help";
		$_1177408942 = @opendir($_1796617134);
		if ($_1177408942) {
			while (false !== ($_239640225 = readdir($_1177408942))) {
				if (is_dir($_1796617134 . "/" . $_239640225) && $_239640225 != "." && $_239640225 != "..") {
					if ($_922511211 === false || is_array($_922511211) && in_array($_239640225, $_922511211)) {
						$_613961841 = "";
						if (file_exists($_1796617134 . "/" . $_239640225 . "/supd_lang_date.dat")) {
							$_613961841 = file_get_contents($_1796617134 . "/" . $_239640225 . "/supd_lang_date.dat");
							$_613961841 = preg_replace("/\D+/", "", $_613961841);
							if (strlen($_613961841) != 8) {
								CUpdateClient::AddMessage2Log(str_replace("#HELP#", $_239640225, GetMessage("SUPP_GH_ERR_DHELP")), "UGH01");
								$_753204024 .= "[UGH01] " . str_replace("#HELP#", $_239640225, GetMessage("SUPP_GH_ERR_DHELP")) . ". ";
								$_613961841 = "";
							}
						}
						$_1519468821[$_239640225] = $_613961841;
					}
				}
			}
			closedir($_1177408942);
		}
		$_161636322  = false;
		$_326250928  = "sort";
		$_1938863488 = "asc";
		if (class_exists("CLanguage")) $_161636322 = CLanguage::GetList($_326250928, $_1938863488, array("ACTIVE" => "Y")); elseif (class_exists("CLang")) $_161636322 = CLang::GetList($_326250928, $_1938863488, array("ACTIVE" => "Y"));
		if ($_161636322 === false) {
			CUpdateClient::AddMessage2Log(GetMessage("SUPP_GL_WHERE_LANGS"), "UGH00");
			$_753204024 .= "[UGH00] " . GetMessage("SUPP_GL_WHERE_LANGS") . ". ";
		} else {
			while ($_1200678719 = $_161636322->Fetch()) {
				if ($_922511211 === false || is_array($_922511211) && in_array($_1200678719["LID"], $_922511211)) {
					if (!array_key_exists($_1200678719["LID"], $_1519468821)) {
						$_1519468821[$_1200678719["LID"]] = "";
					}
				}
			}
			if ($_922511211 === false && empty($_1519468821)) {
				CUpdateClient::AddMessage2Log(GetMessage("SUPP_GL_NO_SITE_LANGS"), "UGH02");
				$_753204024 .= "[UGH02] " . GetMessage("SUPP_GL_NO_SITE_LANGS") . ". ";
			}
		}
		return $_1519468821;
	}

	public static function AddMessage2Log($_843066608, $_1484137941 = "")
	{
		$_692453237  = 1000000;
		$_379278512  = 8000;
		$_1920643663 = $_SERVER["DOCUMENT_ROOT"] . US_SHARED_KERNEL_PATH . "/modules/updater.log";
		$_120280461  = $_SERVER["DOCUMENT_ROOT"] . US_SHARED_KERNEL_PATH . "/modules/updater_tmp1.log";
		if ($_843066608 <> "" || $_1484137941 <> "") {
			$_991151899 = ignore_user_abort(true);
			if (file_exists($_1920643663)) {
				$_1011234688 = @filesize($_1920643663);
				$_1011234688 = intval($_1011234688);
				if ($_1011234688 > $_692453237) {
					if (!($_1733016191 = @fopen($_1920643663, "rb"))) {
						ignore_user_abort($_991151899);
						return false;
					}
					if (!($_1069261324 = @fopen($_120280461, "wb"))) {
						ignore_user_abort($_991151899);
						return false;
					}
					$_221577973 = intval($_1011234688 - $_692453237 / 2.0);
					fseek($_1733016191, $_221577973);
					do {
						$_1667599631 = fread($_1733016191, $_379278512);
						if ($_1667599631 == "") break;
						@fwrite($_1069261324, $_1667599631);
					} while (true);
					@fclose($_1733016191);
					@fclose($_1069261324);
					@copy($_120280461, $_1920643663);
					@unlink($_120280461);
				}
				clearstatcache();
			}
			if ($_1733016191 = @fopen($_1920643663, "ab+")) {
				if (flock($_1733016191, LOCK_EX)) {
					@fwrite($_1733016191, date("Y-m-d H:i:s") . " - " . $_1484137941 . " - " . $_843066608 . "");
					@fflush($_1733016191);
					@flock($_1733016191, LOCK_UN);
					@fclose($_1733016191);
				}
			}
			ignore_user_abort($_991151899);
		}
		return true;
	}


	public static function CheckDirPath($_2036567828, $_1230914372 = true)
	{
		$_1189235069 = array();
		$_2036567828 = str_replace("\\", "/", $_2036567828);
		$_2036567828 = str_replace("//", "/", $_2036567828);
		if ($_2036567828[strlen($_2036567828) - 1] != "/") {
			$_700691280  = CUpdateClient::bxstrrpos($_2036567828, "/");
			$_2036567828 = substr($_2036567828, 0, $_700691280);
		}
		while (strlen($_2036567828) > 1 && $_2036567828[strlen($_2036567828) - 1] == "/") $_2036567828 = substr($_2036567828, 0, strlen($_2036567828) - 1);
		$_700691280 = CUpdateClient::bxstrrpos($_2036567828, "/");
		while ($_700691280 > 0) {
			if (file_exists($_2036567828) && is_dir($_2036567828)) {
				if ($_1230914372) {
					if (!is_writable($_2036567828)) @chmod($_2036567828, BX_DIR_PERMISSIONS);
				}
				break;
			}
			$_1189235069[] = substr($_2036567828, $_700691280 + 1);
			$_2036567828   = substr($_2036567828, 0, $_700691280);
			$_700691280    = CUpdateClient::bxstrrpos($_2036567828, "/");
		}
		for ($_443696004 = count($_1189235069) - 1; $_443696004 >= 0; $_443696004--) {
			$_2036567828 = $_2036567828 . "/" . $_1189235069[$_443696004];
			@mkdir($_2036567828, BX_DIR_PERMISSIONS);
		}
	}

	public static function CopyDirFiles($_1244707243, $_800682851, &$_753204024, $_116010718 = true, $_193561183 = array())
	{
		$_2136808367 = "";
		while (strlen($_1244707243) > 1 && $_1244707243[strlen($_1244707243) - 1] == "/") $_1244707243 = substr($_1244707243, 0, strlen($_1244707243) - 1);
		while (strlen($_800682851) > 1 && $_800682851[strlen($_800682851) - 1] == "/") $_800682851 = substr($_800682851, 0, strlen($_800682851) - 1);
		if (strpos($_800682851 . "/", $_1244707243 . "/") === 0) $_2136808367 .= "[UCDF01] " . GetMessage("SUPP_CDF_SELF_COPY") . ". ";
		if ($_2136808367 == "") {
			if (!file_exists($_1244707243)) $_2136808367 .= "[UCDF02] " . str_replace("#FILE#", $_1244707243, GetMessage("SUPP_CDF_NO_PATH")) . ". ";
		}
		if ($_2136808367 == "") {
			$strongUpdateCheck = COption::GetOptionString("main", "strong_update_check", "Y");
			if (is_dir($_1244707243)) {
				CUpdateClient::CheckDirPath($_800682851 . "/");
				if (!file_exists($_800682851) || !is_dir($_800682851)) $_2136808367 .= "[UCDF03] " . str_replace("#FILE#", $_800682851, GetMessage("SUPP_CDF_CANT_CREATE")) . ". "; elseif (!is_writable($_800682851)) $_2136808367 .= "[UCDF04] " . str_replace("#FILE#", $_800682851, GetMessage("SUPP_CDF_CANT_WRITE")) . ". ";
				if ($_2136808367 == "") {
					if ($_1177408942 = @opendir($_1244707243)) {
						while (($_292314395 = readdir($_1177408942)) !== false) {
							if ($_292314395 == "." || $_292314395 == "..") continue;
							if ($_116010718 && substr($_292314395, 0, strlen("updater")) == "updater") continue;
							if ($_116010718 && (substr($_292314395, 0, strlen("description")) === "description") && (in_array(substr($_292314395, -3), array(".ru", ".de", ".en", ".ua")) || substr($_292314395, -5) == ".full")) {
								continue;
							}
							if (!empty($_193561183)) {
								$_276770789 = false;
								foreach ($_193561183 as $_1468469529) {
									if (strpos($_1244707243 . "/" . $_292314395 . "/", "/lang/" . $_1468469529 . "/") !== false) {
										$_276770789 = true;
										break;
									}
								}
								if ($_276770789) continue;
							}
							if (is_dir($_1244707243 . "/" . $_292314395)) {
								CUpdateClient::CopyDirFiles($_1244707243 . "/" . $_292314395, $_800682851 . "/" . $_292314395, $_2136808367, false, $_193561183);
							} elseif (is_file($_1244707243 . "/" . $_292314395)) {
								if (file_exists($_800682851 . "/" . $_292314395) && !is_writable($_800682851 . "/" . $_292314395)) {
									$_2136808367 .= "[UCDF05] " . str_replace("#FILE#", $_800682851 . "/" . $_292314395, GetMessage("SUPP_CDF_CANT_FILE")) . ". ";
								} else {
									if ($strongUpdateCheck == "Y") $_1941078078 = dechex(crc32(file_get_contents($_1244707243 . "/" . $_292314395)));
									@copy($_1244707243 . "/" . $_292314395, $_800682851 . "/" . $_292314395);
									@chmod($_800682851 . "/" . $_292314395, BX_FILE_PERMISSIONS);
									if ($strongUpdateCheck == "Y") {
										$_1620366633 = dechex(crc32(file_get_contents($_800682851 . "/" . $_292314395)));
										if ($_1620366633 !== $_1941078078) {
											$_2136808367 .= "[UCDF061] " . str_replace("#FILE#", $_800682851 . "/" . $_292314395, GetMessage("SUPP_UGA_FILE_CRUSH")) . ". ";
										}
									}
								}
							}
						}
						@closedir($_1177408942);
					}
				}
			} else {
				$_700691280 = CUpdateClient::bxstrrpos($_800682851, "/");
				$_102313621 = substr($_800682851, 0, $_700691280);
				CUpdateClient::CheckDirPath($_102313621 . "/");
				if (!file_exists($_102313621) || !is_dir($_102313621)) $_2136808367 .= "[UCDF06] " . str_replace("#FILE#", $_102313621, GetMessage("SUPP_CDF_CANT_FOLDER")) . ". "; elseif (!is_writable($_102313621)) $_2136808367 .= "[UCDF07] " . str_replace("#FILE#", $_102313621, GetMessage("SUPP_CDF_CANT_FOLDER_WR")) . ". ";
				if ($_2136808367 == "") {
					if ($strongUpdateCheck == "Y") $_1941078078 = dechex(crc32(file_get_contents($_1244707243)));
					@copy($_1244707243, $_800682851);
					@chmod($_800682851, BX_FILE_PERMISSIONS);
					if ($strongUpdateCheck == "Y") {
						$_1620366633 = dechex(crc32(file_get_contents($_800682851)));
						if ($_1620366633 !== $_1941078078) {
							$_2136808367 .= "[UCDF0611] " . str_replace("#FILE#", $_800682851, GetMessage("SUPP_UGA_FILE_CRUSH")) . ". ";
						}
					}
				}
			}
		}
		if ($_2136808367 <> "") {
			CUpdateClient::AddMessage2Log($_2136808367, "CUCDF");
			$_753204024 .= $_2136808367;
			return false;
		} else return true;
	}

	public static function DeleteDirFilesEx($_2036567828)
	{
		if (!file_exists($_2036567828)) return false;
		if (is_file($_2036567828)) {
			@unlink($_2036567828);
			return true;
		}
		if ($_1177408942 = @opendir($_2036567828)) {
			while (($_292314395 = readdir($_1177408942)) !== false) {
				if ($_292314395 == "." || $_292314395 == "..") continue;
				if (is_dir($_2036567828 . "/" . $_292314395)) {
					CUpdateClient::DeleteDirFilesEx($_2036567828 . "/" . $_292314395);
				} else {
					@unlink($_2036567828 . "/" . $_292314395);
				}
			}
		}
		@closedir($_1177408942);
		@rmdir($_2036567828);
		return true;
	}

	public static function bxstrrpos($_681521238, $_1822690654)
	{
		$_1913562229 = strpos(strrev($_681521238), strrev($_1822690654));
		if ($_1913562229 === false) {
			return false;
		}
		$_1913562229 = strlen($_681521238) - strlen($_1822690654) - $_1913562229;
		return $_1913562229;
	}

	public static function GetModuleInfo($_2036567828)
	{
		$arModuleVersion = array();
		$_836364742      = file_get_contents($_2036567828 . "/install/version.php");
		if ($_836364742 !== false) {
			@eval(str_replace(array('<?php', '<?', '?>'), '', $_836364742));
			if (is_array($arModuleVersion) && array_key_exists("VERSION", $arModuleVersion)) return $arModuleVersion;
		}
		touch($_2036567828 . "/install/version.php");
		include($_2036567828 . "/install/version.php");
		if (is_array($arModuleVersion) && array_key_exists("VERSION", $arModuleVersion)) return $arModuleVersion;
		include_once($_2036567828 . "/install/index.php");
		$_2046167719 = explode("/", $_2036567828);
		$_443696004  = array_search("modules", $_2046167719);
		$_845073196  = $_2046167719[$_443696004 + 1];
		$_845073196  = str_replace(".", "_", $_845073196);
		$_179704299  = new $_845073196;
		return array("VERSION" => $_179704299->MODULE_VERSION, "VERSION_DATE" => $_179704299->MODULE_VERSION_DATE,);
	}

	public static function GetLicenseKey()
	{
		if (defined("US_LICENSE_KEY")) return US_LICENSE_KEY;
		if (defined("LICENSE_KEY")) return LICENSE_KEY;
		if (!isset($GLOBALS["CACHE4UPDATESYS_LICENSE_KEY"]) || $GLOBALS["CACHE4UPDATESYS_LICENSE_KEY"] == "") {
			$LICENSE_KEY = "demo";
			if (file_exists($_SERVER["DOCUMENT_ROOT"] . "/bitrix/license_key.php")) include($_SERVER["DOCUMENT_ROOT"] . "/bitrix/license_key.php");
			$GLOBALS["CACHE4UPDATESYS_LICENSE_KEY"] = $LICENSE_KEY;
		}
		return $GLOBALS["CACHE4UPDATESYS_LICENSE_KEY"];
	}

	public static function getmicrotime()
	{
		return microtime(true);
	}

	private static function __1863373792($_1116920530, $_2106056148, $_1399279890)
	{
		if (class_exists('CUtil') && method_exists('CUtil', 'ConvertToLangCharset')) $_1116920530 = CUtil::ConvertToLangCharset($_1116920530);
		$_1015571134 = GetMessage("SUPP_GHTTP_ER") . ": [" . $_2106056148 . "] " . $_1116920530 . ". ";
		if (intval($_2106056148) <= 0) $_1015571134 .= GetMessage("SUPP_GHTTP_ER_DEF") . " ";
		CUpdateClient::AddMessage2Log("Error connecting 2 " . $_1399279890["SOCKET_IP"] . ": [" . $_2106056148 . "] " . $_1116920530, "ERRCONN1");
		return $_1015571134;
	}

	private static function __690101446()
	{
		$_1023785319 = COption::GetOptionString("main", "update_site", DEFAULT_UPDATE_SERVER);
		$_1726963472 = COption::GetOptionString("main", "update_use_https", "N") == "Y";
		$_1038998234 = COption::GetOptionString("main", "update_site_proxy_addr", "");
		$_1431866772 = COption::GetOptionString("main", "update_site_proxy_port", "");
		$_108662072  = COption::GetOptionString("main", "update_site_proxy_user", "");
		$_387915335  = COption::GetOptionString("main", "update_site_proxy_pass", "");
		$_1831003445 = ($_1038998234 <> "" && $_1431866772 <> "");
		$_1760501310 = array("USE_PROXY" => $_1831003445, "IP" => $_1023785319, "SOCKET_IP" => ($_1726963472 ? "tls://" : "") . $_1023785319, "SOCKET_PORT" => ($_1726963472 ? 443 : 80),);
		if ($_1831003445) {
			$_1431866772 = intval($_1431866772);
			if ($_1431866772 <= 0) $_1431866772 = 80;
			$_1760501310["SOCKET_IP"]      = $_1038998234;
			$_1760501310["SOCKET_PORT"]    = $_1431866772;
			$_1760501310["PROXY_USERNAME"] = $_108662072;
			$_1760501310["PROXY_PASSWORD"] = $_387915335;
		}
		return $_1760501310;
	}

	protected static function resetAccelerator()
	{
		if (function_exists("opcache_reset")) {
			opcache_reset();
		} elseif (function_exists("accelerator_reset")) {
			accelerator_reset();
		}
	}
}

class CUpdateControllerSupport
{
	public static function CheckUpdates()
	{
		$errorMessage = "";
		$_1474158358  = COption::GetOptionString("main", "stable_versions_only", "Y");
		if (!($_1278724601 = CUpdateClient::GetUpdatesList($errorMessage, LANG, $_1474158358))) $errorMessage .= GetMessage("SUPZC_NO_CONNECT") . ". ";
		if ($_1278724601) {
			if (isset($_1278724601["ERROR"])) {
				for ($_443696004 = 0, $_1167463684 = count($_1278724601["ERROR"]); $_443696004 < $_1167463684; $_443696004++) $errorMessage .= "[" . $_1278724601["ERROR"][$_443696004]["@"]["TYPE"] . "] " . $_1278724601["ERROR"][$_443696004]["#"];
			}
		}
		if ($errorMessage <> "") return array("ERROR", $errorMessage);
		if (isset($_1278724601["UPDATE_SYSTEM"])) return array("UPDSYS", "");
		$_146588115 = 0;
		if (isset($_1278724601["MODULES"][0]["#"]["MODULE"]) && is_array($_1278724601["MODULES"][0]["#"]["MODULE"])) $_146588115 = count($_1278724601["MODULES"][0]["#"]["MODULE"]);
		$_808523184 = 0;
		if (isset($_1278724601["LANGS"][0]["#"]["INST"][0]["#"]["LANG"]) && is_array($_1278724601["LANGS"][0]["#"]["INST"][0]["#"]["LANG"])) $_808523184 = count($_1278724601["LANGS"][0]["#"]["INST"][0]["#"]["LANG"]);
		if ($_808523184 > 0 && $_146588115 > 0) return array("UPDATE", "ML"); elseif ($_808523184 <= 0 && $_146588115 > 0) return array("UPDATE", "M");
		elseif ($_808523184 > 0 && $_146588115 <= 0) return array("UPDATE", "L");
		else return array("FINISH", "");
	}

	public static function UpdateModules()
	{
		return CUpdateControllerSupport::__UpdateKernel("M");
	}

	public static function UpdateLangs()
	{
		return CUpdateControllerSupport::__UpdateKernel("L");
	}

	public static function __UpdateKernel($_869801936)
	{
		define("UPD_INTERNAL_CALL", "Y");
		$_REQUEST["query_type"] = $_869801936;
		ob_start();
		include($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/admin/update_system_call.php");
		$_1760501310 = ob_get_contents();
		ob_end_clean();
		return $_1760501310;
	}

	public static function UpdateUpdate()
	{
		define("UPD_INTERNAL_CALL", "Y");
		$_REQUEST["query_type"] = "updateupdate";
		ob_start();
		include($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/admin/update_system_act.php");
		$_1760501310 = ob_get_contents();
		ob_end_clean();
		return $_1760501310;
	}

	public static function Finish()
	{
		@unlink($_SERVER["DOCUMENT_ROOT"] . US_SHARED_KERNEL_PATH . "/modules/versions.php");
	}

	public static function Update($_1667599631 = "")
	{
		@set_time_limit(0);
		ini_set("track_errors", "1");
		ignore_user_abort(true);
		$_1667599631 = trim($_1667599631);
		if ($_1667599631 == "" || $_1667599631 == "CHK") {
			$_1139428298 = CUpdateControllerSupport::CheckUpdates();
			if ($_1139428298[0] == "ERROR") {
				$_522224472 = "ERR|" . $_1139428298[1];
			} elseif ($_1139428298[0] == "FINISH") {
				$_522224472 = "FIN";
			} elseif ($_1139428298[0] == "UPDSYS") {
				$_522224472 = "UPS";
			} elseif ($_1139428298[0] == "UPDATE") {
				$_522224472 = "STP" . $_1139428298[1];
			} else {
				$_522224472 = "ERR|" . "UNK1";
			}
		} else {
			if ($_1667599631 == "UPS") {
				$_322260007 = CUpdateControllerSupport::UpdateUpdate();
				if ($_322260007 == "Y") $_522224472 = "CHK"; else $_522224472 = "ERR|" . $_322260007;
			} elseif (substr($_1667599631, 0, 3) == "STP") {
				$_1277027716 = substr($_1667599631, 3);
				if ($_1277027716 == "ML") {
					$_322260007 = CUpdateControllerSupport::UpdateModules();
					if ($_322260007 == "FIN") $_522224472 = "STP" . "L"; elseif (substr($_322260007, 0, 3) == "ERR") $_522224472 = "ERR|" . substr($_322260007, 3);
					elseif (substr($_322260007, 0, 3) == "STP") $_522224472 = "STP" . "ML" . "|" . substr($_322260007, 3);
					else $_522224472 = "ERR|" . "UNK01";
				} elseif ($_1277027716 == "M") {
					$_322260007 = CUpdateControllerSupport::UpdateModules();
					if ($_322260007 == "FIN") $_522224472 = "FIN"; elseif (substr($_322260007, 0, 3) == "ERR") $_522224472 = "ERR|" . substr($_322260007, 3);
					elseif (substr($_322260007, 0, 3) == "STP") $_522224472 = "STP" . "M" . "|" . substr($_322260007, 3);
					else $_522224472 = "ERR|" . "UNK02";
				} elseif ($_1277027716 == "L") {
					$_322260007 = CUpdateControllerSupport::UpdateLangs();
					if ($_322260007 == "FIN") $_522224472 = "FIN"; elseif (substr($_322260007, 0, 3) == "ERR") $_522224472 = "ERR|" . substr($_322260007, 3);
					elseif (substr($_322260007, 0, 3) == "STP") $_522224472 = "STP" . "L" . "|" . substr($_322260007, 3);
					else $_522224472 = "ERR|" . "UNK03";
				} else {
					$_522224472 = "ERR|" . "UNK2";
				}
			} else {
				$_522224472 = "ERR|" . "UNK3";
			}
		}
		if ($_522224472 == "FIN") CUpdateControllerSupport::Finish();
		return $_522224472;
	}

	public static function CollectVersionsFile()
	{
		$_1329480955 = $_SERVER["DOCUMENT_ROOT"] . US_SHARED_KERNEL_PATH . "/modules/versions.php";
		@unlink($_1329480955);
		$errorMessage = "";
		$_1573213927  = CUpdateClient::GetCurrentModules($errorMessage);
		if ($errorMessage == "") {
			$_1518328039 = fopen($_1329480955, "w");
			fwrite($_1518328039, "<" . "?");
			fwrite($_1518328039, "\$arVersions = array(");
			foreach ($_1573213927 as $_944152425 => $_163546139)
				fwrite($_1518328039, "	\"".htmlspecialcharsbx($_944152425)."\" => \"".htmlspecialcharsbx($_163546139)."\",");
			fwrite($_1518328039, ");");
			fwrite($_1518328039, "?" . ">");
			fclose($_1518328039);
		}
	}
}

class CUpdateExpertMode
{
	const OPTION_NAME = 'update_system_expert_mode';

	public static function isAvailable()
	{
		return (version_compare(phpversion(), '7.0.0') >= 0 && defined('UPDATE_SYSTEM_EXPERT_MODE_ENABLED') && UPDATE_SYSTEM_EXPERT_MODE_ENABLED === true);
	}

	public static function isEnabled()
	{
		return (static::isAvailable() && COption::GetOptionString('main', 'update_system_expert_mode', 'N') === 'Y');
	}

	public static function enable()
	{
		COption::SetOptionString('main', 'update_system_expert_mode', 'Y');
	}

	public static function disable()
	{
		COption::SetOptionString('main', 'update_system_expert_mode', 'N');
	}

	public static function isCorrectModulesStructure($_405564040)
	{
		if (!is_array($_405564040)) {
			return false;
		}
		$_525707952 = array_keys($_405564040);
		if ($_525707952 === array_keys($_525707952)) {
			return false;
		}
		$_2062014989 = reset($_405564040);
		if (is_array($_2062014989) && isset($_2062014989["to"]) && is_string($_2062014989["to"])) {
			return true;
		}
		return false;
	}

	public static function processModulesFrom($_405564040, $_1503380714)
	{
		if (!is_array($_1503380714)) {
			return array();
		}
		if (!is_array($_405564040)) {
			return $_1503380714;
		}
		foreach ($_1503380714 as $_1310272241 => $_932962422) {
			if (!isset($_405564040[$_1310272241]["from"])) {
				continue;
			}
			if (CUpdateClient::CompareVersions($_932962422, $_405564040[$_1310272241]["from"]) > 0) {
				$_1503380714[$_1310272241] = $_405564040[$_1310272241]["from"];
			}
		}
		return $_1503380714;
	}

	public static function extractModulesTo($_405564040)
	{
		if (!is_array($_405564040)) {
			return array();
		}
		$_1491357081 = array();
		foreach ($_405564040 as $_1310272241 => $_2138824924) {
			if (isset($_2138824924["to"])) {
				$_1491357081[$_1310272241] = $_2138824924["to"];
			}
		}
		return $_1491357081;
	}

	public static function isIncludeTmpUpdatesEnabled()
	{
		return static::isEnabled() && COption::GetOptionString('main', 'update_system_expert_mode_include_tmp_updates', 'N') === 'Y';
	}

	public static function enableIncludeTmpUpdates()
	{
		COption::SetOptionString('main', 'update_system_expert_mode_include_tmp_updates', 'Y');
	}

	public static function disableIncludeTmpUpdates()
	{
		COption::SetOptionString('main', 'update_system_expert_mode_include_tmp_updates', 'N');
	}
} ?>