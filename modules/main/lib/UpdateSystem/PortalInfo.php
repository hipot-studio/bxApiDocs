<? namespace Bitrix\Main\UpdateSystem;

use Bitrix\Main\Application;
use Bitrix\Main\License;
use Bitrix\Main\Loader;
use Bitrix\Main\SystemException;
use Bitrix\Main\SiteTable;

class PortalInfo
{
	private License $_1953244723;

	public function __construct()
	{
		$this->_1953244723 = Application::getInstance()->getLicense();
	}

	public function common(): array
	{
		global $DB;
		return [
			"LICENSE_KEY" => $this->_1953244723->getHashLicenseKey(), "lang" => LANGUAGE_ID, "utf" => "Y", "stable" => \COption::GetOptionString("main", "stable_versions_only", "Y"), "CANGZIP" => function_exists("gzcompress") ? "Y" : "N", "SUPD_DBS" => $DB->type, "XE" => (isset($DB->_593443655) && $DB->_593443655) ? "Y" : "N", "SUPD_URS" => $this->_1953244723->getActiveUsersCount(), "CLIENT_SITE" => $_SERVER["SERVER_NAME"], "spd" => \COption::GetOptionString("main", "crc_code", ""), "dbv" => $this->__1273681288(), "SUPD_VER" => defined("UPDATE_SYSTEM_VERSION_A") ? UPDATE_SYSTEM_VERSION_A : "", "SUPD_SRS" => $this->__2014184289() ?? "RU", "SUPD_CMP" => "N", "SUPD_STS" => $this->__1292707509() ?? "RA", "LICENSE_SIGNED" => $this->__1853504303(), "CLIENT_PHPVER" => phpversion(), "NGINX" => \COption::GetOptionString("main", "update_use_nginx", "Y"), "SMD" => \COption::GetOptionString("main", "update_safe_mode", "N"), "VERSION" => SM_VERSION, "TYPENC" => $this->getLicenseType(), "CHHB" => $_SERVER["HTTP_HOST"], "CSAB" => $_SERVER["SERVER_ADDR"], "SUID" => $GLOBALS["APPLICATION"]->GetServerUniqID(),
		];
	}

	private function __1273681288(): string
	{
		global $DB;
		$_1038629042 = $DB->GetVersion();
		return $_1038629042 !== false ? $_1038629042 : "";
	}

	private function __2014184289(): ?int
	{
		if (Loader::includeModule("cluster") && class_exists("CCluster")) {
			return \CCluster::getServersCount();
		}
		return null;
	}

	private function __1292707509(): ?int
	{
		return SiteTable::getCount(["=ACTIVE" => "Y"]);
	}

	private function __1853504303(): string
	{
		require_once(Application::getDocumentRoot() . "/bitrix/modules/main/classes/general/update_client.php");
		$_282391392 = \CUpdateClient::getNewLicenseSignedKey();
		return $_282391392 . "-" . \COption::GetOptionString("main", $_282391392, "N");
	}

	public function getLicenseType(): string
	{
		if ($this->_1953244723->isDemo()) {
			return "D";
		} elseif ($this->_1953244723->isEncoded()) {
			return "E";
		} elseif ($this->_1953244723->isTimeBound()) {
			return "T";
		} else {
			return "F";
		}
	}

	public function getModules(): array
	{
		require_once(Application::getDocumentRoot() . "/bitrix/modules/main/classes/general/update_client.php");
		$_997149111  = "";
		$_939492339  = \CUpdateClient::GetCurrentModules($_997149111);
		$_1893466875 = (\CUpdateExpertMode::isEnabled() && \CUpdateExpertMode::isCorrectModulesStructure([]));
		if ($_1893466875) {
			$_939492339 = \CUpdateExpertMode::processModulesFrom([], $_939492339);
		}
		if (!empty($_997149111)) {
			throw new SystemException($_997149111);
		}
		return $_939492339;
	}

	public function getLanguages(): array
	{
		require_once(Application::getDocumentRoot() . "/bitrix/modules/main/classes/general/update_client.php");
		$_997149111 = "";
		$_294351698 = \CUpdateClient::GetCurrentLanguages($_997149111);
		if (!empty($_997149111)) {
			throw new SystemException($_997149111);
		}
		return $_294351698;
	}
} ?>