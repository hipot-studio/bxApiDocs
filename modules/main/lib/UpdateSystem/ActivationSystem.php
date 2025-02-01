<? namespace Bitrix\Main\UpdateSystem;

use Bitrix\Main\Application;
use Bitrix\Main\Result;
use Bitrix\Main\Security\SecurityException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Web\Json;

class ActivationSystem
{
	public function reincarnate(Coupon $_1856566385): Result
	{
		$_1246010339 = new ReincarnationRequestBuilder($_1856566385);
		$_1981628340 = (new RequestFactory($_1246010339))->build();
		$_591299158  = $_1981628340->send();
		$_2090073553 = new UpdateServerDataParser($_591299158);
		$_1640032011 = $_2090073553->parse();
		if (isset($_1640032011["ERROR"])) {
			throw new SystemException(($_1640032011["ERROR"]["_VALUE"] ?? "Unknown error") . " [ASR01]");
		}
		$_1640032011 = $_1640032011["RENT"] ?? [];
		if (empty($_1640032011)) {
			throw new SystemException("Not found license info [ASR02]");
		}
		$this->applyLicenseInfo($_1640032011, $_1856566385->getKey());
		$_56207193 = new Result();
		return $_56207193->setData($_1640032011);
	}

	protected function applyLicenseInfo(array $_1640032011, string $_1171064020): void
	{
		if (isset($_1640032011["V1"], $_1640032011["V2"])) {
			$_50487634  = $_1640032011["V1"];
			$_197809861 = $_1640032011["V2"];
			if (empty($_50487634) || empty($_197809861)) {
				throw new SystemException("Server response is not recognized [ASALI01]");
			}
			\COption::SetOptionString("main", "admin_passwordh", $_50487634);
			if (is_writable($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/admin")) {
				if ($_395512824 = fopen($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/admin/define.php", "w")) {
					fwrite($_395512824, "<" . "?Define(\"TEMPORARY_CACHE\", \"".$_197809861."\");?" . ">"); fclose($_395512824);} else {
					throw new SystemException("File open fails [ASALI02]");
				}
			} else {
				throw new SystemException("Folder is not writable [ASALI03]");
			}
		}
		if (isset($_1640032011["DATE_TO_SOURCE"])) {
			\COption::SetOptionString("main", "~support_finish_date", $_1640032011["DATE_TO_SOURCE"]);
		}
		if (isset($_1640032011["MAX_SITES"])) {
			\COption::SetOptionString("main", "PARAM_MAX_SITES", intval($_1640032011["MAX_SITES"]));
		}
		if (isset($_1640032011["MAX_USERS"])) {
			\COption::SetOptionString("main", "PARAM_MAX_USERS", intval($_1640032011["MAX_USERS"]));
		}
		if (isset($_1640032011["MAX_USERS_STRING"])) {
			\COption::SetOptionString("main", "~PARAM_MAX_USERS", $_1640032011["MAX_USERS_STRING"]);
		}
		if (isset($_1640032011["DATE_TO_SOURCE_STRING"])) {
			\COption::SetOptionString("main", "~PARAM_FINISH_DATE", $_1640032011["DATE_TO_SOURCE_STRING"]);
		}
		if (isset($_1640032011["ISLC"])) {
			if (is_writable($_SERVER["DOCUMENT_ROOT"] . "/bitrix")) {
				if ($_395512824 = fopen($_SERVER["DOCUMENT_ROOT"] . "/bitrix/license_key.php", "wb")) {
					fputs($_395512824, "<" . "?\$LICENSE_KEY = \"".EscapePHPString($_1171064020)."\";?" . ">"); fclose($_395512824);} else {
					throw new SystemException("File open fails [ASALI04]");
				}
			} else {
				throw new SystemException("Folder is not writable [ASALI05]");
			}
		}
	}

	public function activateByHash(string $_313737624): Result
	{
		$_2090073553 = new HashCodeParser($_313737624);
		$_1640032011 = $_2090073553->parse();
		if (empty($_1640032011)) {
			throw new SystemException("Not found license info [ASAH01]");
		}
		$_1171064020 = Application::getInstance()->getLicense()->getKey();
		$this->applyLicenseInfo($_1640032011, $_1171064020);
		$_56207193 = new Result();
		return $_56207193->setData($_1640032011);
	}

	public function sendInfoToPartner(string $_650785112, string $_1913963116, string $_1545482205): Result
	{
		$_1246010339 = new PartnerInfoRequestBuilder($_650785112, $_1913963116, $_1545482205);
		$_1981628340 = (new RequestFactory($_1246010339))->build();
		$_591299158  = $_1981628340->send();
		$_591299158  = Json::decode($_591299158);
		if (!isset($_591299158["result"]) || $_591299158["result"] === "error") {
			$_408893648 = ["message" => "Error send partner info [ASSITP01]", "response" => $_591299158, "request" => $_1981628340];
			throw new SystemException(($_591299158["error"] ?? "Unknown error") . " [ASSITP01]");
		}
		return (new Result())->setData($_591299158);
	}
} ?>