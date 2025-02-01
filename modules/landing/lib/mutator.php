<? namespace Bitrix\Landing;

use Bitrix\Bitrix24\Feature;
use Bitrix\Landing\Assets;
use Bitrix\Landing\Block\Cache;
use Bitrix\Landing\Internals\HookDataTable as HookData;
use Bitrix\Landing\Restriction;
use Bitrix\Rest\AppTable;
use Bitrix\Main\Config\Configuration;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\HttpClient;

Loc::loadMessages(__FILE__);
define("LANDING_MUTATOR_MODE", true);

class Mutator
{
	public static function checkSiteVerification(int $_530825246, Error $_780694482): bool
	{
		$_1548224827 = Manager::getZone();
		if (!in_array($_1548224827, ["ru", "by", "kz"]) && !self::__987988921()) {
			if (!Restriction\Site::isPhoneConfirmed($_530825246)) {
				$_780694482->addError("PHONE_NOT_CONFIRMED", Loc::getMessage("LANDING_PHONE_NOT_CONFIRMED"));
				return false;
			}
		} else if (!self::__909396365()) {
			if (!Restriction\Site::isEmailConfirmed($_530825246)) {
				$_780694482->addError("EMAIL_NOT_CONFIRMED", Loc::getMessage("LANDING_EMAIL_NOT_CONFIRMED"));
				return false;
			}
		}
		return true;
	}

	private static function __987988921(): bool
	{
		if (Manager::getOption("allow_skip_phone_verification") === "Y") {
			return true;
		}
		if (!\Bitrix\Main\Loader::includeModule("bitrix24")) {
			return true;
		}
		return Feature::isFeatureEnabled("landing_skip_phone_verification");
	}

	private static function __909396365(): bool
	{
		if (Manager::getOption("allow_skip_email_verification") === "Y") {
			return true;
		}
		return false;
	}

	public static function landingPublication(Landing $landing, $_1329100686 = null, bool $_1722653099 = false): bool
	{
		static $_1799949398 = [];
		static $_1425184923 = [];
		Manager::disableAllFeaturesTmp();
		if (!$landing->exist()) {
			return false;
		}
		$_1395115988 = new Event("landing", "onLandingStartPublication", array("id" => $landing->getId(), "blockId" => $_1329100686, "siteId" => $landing->getSiteId()));
		$_1395115988->send();
		foreach ($_1395115988->getResults() as $_399803483) {
			if ($_399803483->getType() == EventResult::ERROR) {
				foreach ($_399803483->getErrors() as $_780694482) {
					$landing->getError()->addError($_780694482->getCode(), $_780694482->getMessage());
				}
				return false;
			}
		}
		if (0) if ($_2101281660 = Configuration::getValue("landing_urlchecker_key")) {
			$_782317109  = [];
			$_1519749599 = Block::getList(["select" => ["CONTENT"], "filter" => ["LID" => $landing->getId(), "=DELETED" => "N", "=ACTIVE" => "Y", "=PUBLIC" => "N"]]);
			while ($_1165835923 = $_1519749599->fetch()) {
				if (preg_match_all("#((http|ftp|https)://[^'\"\s <]+)#is", $_1165835923["CONTENT"], $_748005298)) {
					$_782317109 = array_merge($_782317109, $_748005298[1]);
				}
			}
			$_782317109 = array_values(array_unique($_782317109));
			$_559807253 = new HttpClient;
			if ($_782317109) {
				$_434587564 = $_559807253->post(Manager::getPreviewHost() . "/tools/urlchecker.php", ["key" => $_2101281660, "url" => $_782317109, "http_host" => \Bitrix\Main\Application::getInstance()->getContext()->getServer()->get("HTTP_HOST")]);
				if ($_434587564 && $_434587564 !== "OK") {
					$landing->getError()->addError("URLCHECKER_FAIL", Loc::getMessage("LANDING_URLCHECKER_FAIL"));
					return false;
				}
			}
		}
		if (!Manager::checkFeature(Manager::FEATURE_PUBLICATION_PAGE, array("filter" => array("!ID" => $landing->getId())))) {
			$landing->getError()->addError("PUBLIC_PAGE_REACHED", Restriction\Manager::getSystemErrorMessage("limit_sites_number_page"));
			return false;
		}
		$_639479610 = $landing->getMeta();
		if (isset($_639479610["INITIATOR_APP_CODE"]) && \Bitrix\Main\Loader::includeModule("rest")) {
			$_1519749599 = AppTable::getList(["filter" => ["=CODE" => $_639479610["INITIATOR_APP_CODE"]]]);
			if ($_1165835923 = $_1519749599->fetch()) {
				$_1808866083 = AppTable::getAppStatusInfo($_1165835923, "");
				if ($_1808866083["PAYMENT_ALLOW"] != "Y") {
					$landing->getError()->addError("LANDING_PAYMENT_FAILED", Restriction\Manager::getSystemErrorMessage("landing_payment_failed_2"));
					return false;
				}
			}
		}
		$_1386097863 = [];
		$_1519749599 = Block::getList(["select" => ["CODE"], "filter" => ["LID" => $landing->getId(), "=ACTIVE" => "Y", "=DELETED" => "N", "=PUBLIC" => "N", "%=CODE" => "repo_%"]]);
		while ($_1165835923 = $_1519749599->fetch()) {
			$_1386097863[] = substr($_1165835923["CODE"], 5);
		}
		if (!empty($_1386097863)) {
			foreach (Repo::getAppInfo($_1386097863) as $_300542055) {
				if (($_300542055["PAYMENT_ALLOW"] ?? "Y") !== "Y") {
					$landing->getError()->addError("LANDING_PAYMENT_FAILED_BLOCK", Restriction\Manager::getSystemErrorMessage("landing_payment_failed_block"));
					return false;
				}
			}
		}
		if (!in_array($landing->getSiteId(), $_1425184923)) {
			$_1425184923[] = $landing->getSiteId();
			$_1519749599   = Site::getList(array("select" => array("ID", "TYPE"), "filter" => array("ID" => $landing->getSiteId(), "=SPECIAL" => "N", "CHECK_PERMISSIONS" => "N")));
			if ($_1165835923 = $_1519749599->fetch()) {
				if (!Manager::checkFeature(Manager::FEATURE_PUBLICATION_SITE, ["filter" => ["!ID" => $_1165835923["ID"]], "type" => $_1165835923["TYPE"]])) {
					$_1564910575 = Manager::licenseIsFreeSite($_1165835923["TYPE"]) && !Manager::isFreePublicAllowed() ? "PUBLIC_SITE_REACHED_FREE" : "PUBLIC_SITE_REACHED";
					$_546276867  = Manager::licenseIsFreeSite($_1165835923["TYPE"]) && !Manager::isFreePublicAllowed() ? "limit_sites_number_free" : "limit_sites_number";
					$landing->getError()->addError($_1564910575, Restriction\Manager::getSystemErrorMessage($_546276867));
					return false;
				}
			}
		}
		if (\Bitrix\Landing\Hook\Page\HeadBlock::isLockedFeature()) {
			$_135235365  = [$landing->getId()];
			$_1519749599 = Landing::getList(["select" => ["ID"], "filter" => ["SITE_ID" => $landing->getSiteId(), "CHECK_PERMISSIONS" => "N"]]);
			while ($_1165835923 = $_1519749599->fetch()) {
				$_135235365[] = $_1165835923["ID"];
			}
			$_1519749599 = HookData::getList(["select" => ["ID", "ENTITY_TYPE", "ENTITY_ID"], "filter" => [["LOGIC" => "OR", ["=ENTITY_TYPE" => Hook::ENTITY_TYPE_SITE, "ENTITY_ID" => $landing->getSiteId()], ["=ENTITY_TYPE" => Hook::ENTITY_TYPE_LANDING, "ENTITY_ID" => $_135235365]], "=HOOK" => "HEADBLOCK", "=CODE" => "USE", "=VALUE" => "Y"], "limit" => 1]);
			if ($_1165835923 = $_1519749599->fetch()) {
				$landing->getError()->addError("PUBLIC_HTML_DISALLOWED[" . $_1165835923["ENTITY_TYPE"] . $_1165835923["ENTITY_ID"] . "]", Restriction\Manager::getSystemErrorMessage("limit_sites_html_js"));
				return false;
			}
		}
		if (!self::checkSiteVerification($landing->getSiteId(), $landing->getError())) {
			return false;
		}
		$_1395115988 = new Event("landing", "onLandingPublication", array("id" => $landing->getId(), "blockId" => $_1329100686, "tplCode" => $_639479610["TPL_CODE"],));
		$_1395115988->send();
		foreach ($_1395115988->getResults() as $_399803483) {
			if ($_399803483->getResultType() == EventResult::ERROR) {
				foreach ($_399803483->getErrors() as $_780694482) {
					$landing->getError()->addError($_780694482->getCode(), $_780694482->getMessage());
				}
				return false;
			}
		}
		if ($_1722653099) {
			return true;
		}
		if (!\Bitrix\Main\ModuleManager::isModuleInstalled("bitrix24")) {
			$_2093328194 = $GLOBALS["DB"]->Query("SELECT VALUE FROM b_option WHERE NAME='~PARAM_FINISH_DATE' AND MODULE_ID='main'", true);
			if ($_1519749599 = $_2093328194->Fetch()) {
				$_208223601 = $_1519749599["VALUE"];
				list($_842048387, $_1691119328) = explode(".", $_208223601);
				$_672505992  = pack("H*", $_842048387);
				$_2043482201 = "bitrix" . md5(constant("LICENSE_KEY"));
				$_171907104  = hash_hmac("sha256", $_1691119328, $_2043482201, true);
				if (strcmp($_171907104, $_672505992) !== 0) {
					$_1691119328 = "2018-01-01";
				}
			} else {
				$_1691119328 = "2018-01-01";
			}
			if (!empty($_1691119328)) {
				$_1974412205 = explode("-", $_1691119328);
				$_633112747  = mktime(0, 0, 0, $_1974412205[1], $_1974412205[2], $_1974412205[0]);
				if ($_633112747 <= time()) {
					$landing->getError()->addError("LICENSE_EXPIRED", Loc::getMessage("LANDING_LICENSE_EXPIRED"));
					return false;
				}
			}
		}
		if (!\Bitrix\Main\ModuleManager::isModuleInstalled("bitrix24")) {
			$_2093328194 = $GLOBALS["DB"]->Query("SELECT VALUE FROM b_option WHERE NAME='~PARAM_FINISH_DATE' AND MODULE_ID='main'", true);
			if ($_1519749599 = $_2093328194->Fetch()) {
				$_208223601 = $_1519749599["VALUE"];
				list($_842048387, $_1691119328) = explode(".", $_208223601);
				$_672505992  = pack("H*", $_842048387);
				$_2043482201 = "bitrix" . md5(constant("LICENSE_KEY"));
				$_171907104  = hash_hmac("sha256", $_1691119328, $_2043482201, true);
				if (strcmp($_171907104, $_672505992) !== 0) {
					$_1691119328 = "2018-01-01";
				}
			} else {
				$_1691119328 = "2018-01-01";
			}
			if (!empty($_1691119328)) {
				$_1974412205 = explode("-", $_1691119328);
				$_633112747  = mktime(0, 0, 0, $_1974412205[1], $_1974412205[2], $_1974412205[0]);
				if ($_633112747 <= time()) {
					$landing->getError()->addError("LICENSE_EXPIRED", Loc::getMessage("LANDING_LICENSE_EXPIRED"));
					return false;
				}
			}
		}
		if ($landing->getFolderId()) {
			Site::publicationFolder($landing->getFolderId());
		}
		if (!$_1329100686) {
			Hook::setEditMode();
			Hook::publicationSite($landing->getSiteId());
			Hook::publicationLanding($landing->getId());
		}
		Assets\Manager::rebuildWebpackForLanding($landing->getId());
		self::blocksPublication($landing, $_1329100686);
		$_999494683  = new \Bitrix\Main\Type\DateTime;
		$_165131169  = ["ACTIVE" => "Y", "PUBLIC" => "Y", "DATE_PUBLIC" => $_999494683, "DATE_MODIFY" => false];
		$_1519749599 = Landing::update($landing->getId(), $_165131169);
		$landing->setMetaData($_165131169);
		if ($_1519749599->isSuccess()) {
			if (!in_array($landing->getSiteId(), $_1799949398)) {
				$_1799949398[] = $landing->getSiteId();
				$_1519749599   = Site::update($landing->getSiteId(), array("ACTIVE" => "Y"));
				if (!$_1519749599->isSuccess()) {
					$landing->getError()->addFromResult($_1519749599);
					return false;
				}
			}
			return true;
		} else {
			$landing->getError()->addFromResult($_1519749599);
		}
		return false;
	}

	public static function blocksPublication(\Bitrix\Landing\Landing $landing, $_1329100686 = null): void
	{
		if ($landing->exist()) {
			$_1845256786 = $landing->getId();
			$_660753813  = array();
			$_1279134725 = array();
			$_1996210589 = array();
			$_804674450  = "/([\;\"]{0,1})#block([\d]+)([\&\"]{0,1})/is";
			$_1417868090 = ["LID" => $landing->getId(), "=DELETED" => "N"];
			if ($_1329100686) {
				$_1417868090["ID"] = $_1329100686;
				$_1519749599       = Block::getList(["select" => ["ID", "PARENT_ID"], "filter" => $_1417868090]);
				$_1417868090["ID"] = (array)$_1417868090["ID"];
				while ($_1165835923 = $_1519749599->fetch()) {
					$_1417868090["ID"][] = $_1165835923["PARENT_ID"];
				}
			}
			$_1519749599 = Block::getList(["select" => ["ID", "PUBLIC", "PARENT_ID", "CODE", "SORT", "ACTIVE", "ANCHOR", "ACCESS", "CONTENT", "SEARCH_CONTENT", "SOURCE_PARAMS", "ASSETS", "XML_ID", "DESIGNED"], "filter" => $_1417868090]);
			while ($_1165835923 = $_1519749599->fetch()) {
				$_660753813[$_1165835923["ID"]] = $_1165835923;
			}
			foreach ($_660753813 as $_1998393742 => $_967272749) {
				if ($_967272749["PUBLIC"] != "Y") {
					$_967272749["CONTENT"] = preg_replace_callback("/href=\"(product:) ?#catalog(Element|Section)([\d]+)\"/i", function ($_818427038) {
						return "href=\"" . PublicAction\Utils::getIblockURL($_818427038[3], mb_strtolower($_818427038[2])) . "\"";
					}, $_967272749["CONTENT"]);
					$_967272749["CONTENT"] = preg_replace_callback("/(data-pseudo-url=\"{\S * (product:)?#catalog(Element|Section)([\d]+))(\S*}\")/i", function ($_818427038) {
						$_818427038[1] = preg_replace_callback("/(product:)?#catalog(Element|Section)([\d]+)/i", function ($_484552987) {
							return PublicAction\Utils::getIblockURL($_484552987[3], mb_strtolower($_484552987[2]));
						}, $_818427038[1]);
						return $_818427038[1] . $_818427038[5];
					}, $_967272749["CONTENT"]);
					$_967272749["CONTENT"] = Subtype\Form::prepareFormsToPublication($_967272749["CONTENT"]);
					$_967272749["CONTENT"] = str_replace("contenteditable=\"true\"", "", $_967272749["CONTENT"]);
					$_1290320006           = isset($_660753813[$_967272749["PARENT_ID"]]) ? $_660753813[$_967272749["PARENT_ID"]]["ID"] : 0;
					if ($_1290320006) {
						Cache::clear($_1290320006);
						$_1519749599 = Block::update($_1290320006, array("SORT" => $_967272749["SORT"], "ACTIVE" => $_967272749["ACTIVE"], "ANCHOR" => $_967272749["ANCHOR"], "XML_ID" => $_967272749["XML_ID"], "ACCESS" => $_967272749["ACCESS"], "DESIGNED" => $_967272749["DESIGNED"], "SOURCE_PARAMS" => $_967272749["SOURCE_PARAMS"], "CONTENT" => $_967272749["CONTENT"], "SEARCH_CONTENT" => $_967272749["SEARCH_CONTENT"], "ASSETS" => $_967272749["ASSETS"]));
						$_1519749599->isSuccess();
						unset($_660753813[$_967272749["PARENT_ID"]]);
						File::replaceInBlock($_1290320006, File::getFilesFromBlockContent($_1998393742, $_967272749["CONTENT"]));
					} else {
						$_1519749599 = Block::add(array("LID" => $_1845256786, "CODE" => $_967272749["CODE"], "SORT" => $_967272749["SORT"], "ANCHOR" => $_967272749["ANCHOR"] ?: "b" . $_1998393742, "XML_ID" => $_967272749["XML_ID"], "ACTIVE" => $_967272749["ACTIVE"], "ACCESS" => $_967272749["ACCESS"], "DESIGNED" => $_967272749["DESIGNED"], "SOURCE_PARAMS" => $_967272749["SOURCE_PARAMS"], "CONTENT" => $_967272749["CONTENT"], "SEARCH_CONTENT" => $_967272749["SEARCH_CONTENT"], "ASSETS" => $_967272749["ASSETS"]));
						if ($_1519749599->isSuccess()) {
							$_1290320006 = $_1519749599->getId();
							$_1519749599 = Block::update($_1998393742, array("PARENT_ID" => $_1290320006));
							$_1519749599->isSuccess();
							File::addToBlock($_1290320006, File::getFilesFromBlockContent($_1998393742, $_967272749["CONTENT"]));
						}
					}
					if ($_1290320006) {
						$_1184009604 = new Block($_1290320006);
						Assets\PreProcessing::blockPublicationProcessing($_1184009604);
						$_967272749["CONTENT"] = $_1184009604->getContent();
						unset($_1184009604);
					}
					if (preg_match($_804674450, $_967272749["CONTENT"])) {
						$_1279134725[$_1290320006] = $_967272749["CONTENT"];
					}
					$_1996210589[$_1998393742] = $_1290320006;
					unset($_660753813[$_1998393742]);
				}
			}
			foreach ($_660753813 as $_1998393742 => $_967272749) {
				$_1519749599 = Block::delete($_1998393742);
				$_1519749599->isSuccess();
			}
			foreach ($_1279134725 as $_1998393742 => $_1709621186) {
				$_1709621186 = preg_replace_callback($_804674450, function ($_685138299) use ($_1996210589) {
					if (isset($_1996210589[$_685138299[2]])) {
						return $_685138299[1] . "#block" . $_1996210589[$_685138299[2]] . $_685138299[3];
					} else {
						return $_685138299[1] . "#block" . $_685138299[2] . $_685138299[3];
					}
				}, $_1709621186);
				$_1519749599 = Block::update($_1998393742, array("CONTENT" => $_1709621186));
				$_1519749599->isSuccess();
			}
		}
	}
}

?>