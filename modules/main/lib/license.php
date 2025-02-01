<? namespace Bitrix\Main;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Type\Date;

final class License
{
	private ?string $_150611248 = null;
	private const DOMAINS_STORE_LICENSE = ['ru' => 'https://util.1c-bitrix.ru', 'ua' => 'https://util.bitrix.ua', 'en' => 'https://util.bitrixsoft.com', 'kz' => 'https://util.1c-bitrix.kz', 'by' => 'https://util.1c-bitrix.by',];
	public const URL_BUS_EULA = ['ru' => 'https://www.1c-bitrix.ru/download/law/eula_bus.pdf', 'by' => 'https://www.1c-bitrix.by/download/law/eula_bus.pdf', 'kz' => 'https://www.1c-bitrix.kz/download/law/eula_bus.pdf', 'ua' => 'https://www.bitrix.ua/download/law/eula_bus.pdf',];
	public const URL_CP_EULA = ['ru' => 'https://www.1c-bitrix.ru/download/law/eula_cp.pdf', 'by' => 'https://www.1c-bitrix.by/download/law/eula_cp.pdf', 'kz' => 'https://www.1c-bitrix.kz/download/law/eula_cp.pdf', 'en' => 'https://www.bitrix24.com/eula/', 'br' => 'https://www.bitrix24.com.br/eula/', 'fr' => 'https://www.bitrix24.fr/eula/', 'pl' => 'https://www.bitrix24.pl/eula/', 'it' => 'https://www.bitrix24.it/eula/', 'la' => 'https://www.bitrix24.es/eula/',];
	public const URL_RENEWAL_LICENSE = ['com' => 'https://store.bitrix24.com/profile/license-keys.php', 'eu' => 'https://store.bitrix24.eu/profile/license-keys.php', 'de' => 'https://store.bitrix24.de/profile/license-keys.php', 'ru' => 'https://www.1c-bitrix.ru/buy/products/b24.php#tab-section-2', 'by' => 'https://www.1c-bitrix.by/buy/products/b24.php#tab-section-2', 'kz' => 'https://www.1c-bitrix.kz/buy/products/b24.php#tab-section-2',];

	public function getKey(): string
	{
		if ($this->_150611248 === null) {
			$_1153066100 = Loader::getDocumentRoot() . "/bitrix/license_key.php";
			$LICENSE_KEY = "";
			if (file_exists($_1153066100)) {
				include($_1153066100);
			}
			$this->_150611248 = ($LICENSE_KEY == "" || strtoupper($LICENSE_KEY) == "DEMO" ? "DEMO" : $LICENSE_KEY);
		}
		return $this->_150611248;
	}

	public function getHashLicenseKey(): string
	{
		return md5($this->getKey());
	}

	public function getPublicHashKey(): string
	{
		return md5("BITRIX" . $this->getKey() . "LICENCE");
	}

	public function isDemoKey(): bool
	{
		return $this->getKey() == "DEMO";
	}

	public function getBuyLink(): string
	{
		return $this->getDomainStoreLicense() . "/key_update.php?license_key=" . $this->getHashLicenseKey() . "&tobasket=y&lang=" . LANGUAGE_ID;
	}

	public function getDocumentationLink(): string
	{
		$_1646042363 = $this->getRegion();
		if (in_array($_1646042363, ["ru", "kz", "by"])) {
			return "https://dev.1c-bitrix.ru/learning/course/index.php?COURSE_ID=135&LESSON_ID=25720";
		}
		return "https://training.bitrix24.com/support/training/course/index.php?COURSE_ID=178&LESSON_ID=25932&LESSON_PATH=17520.17562.25930.25932";
	}

	public function getRenewalLink(): string
	{
		$_1646042363 = $this->getRegion();
		if (in_array($_1646042363, ["ru", "by", "kz", "de"])) {
			return self::URL_RENEWAL_LICENSE[$_1646042363];
		}
		if (in_array($_1646042363, ["eu", "fr", "pl", "it", "uk"])) {
			return self::URL_RENEWAL_LICENSE["eu"];
		}
		return self::URL_RENEWAL_LICENSE["com"];
	}

	public function getDomainStoreLicense(): string
	{
		return self::DOMAINS_STORE_LICENSE[$this->getRegion()] ?? self::DOMAINS_STORE_LICENSE["en"];
	}

	public function isDemo(): bool
	{
		return defined("DEMO") && DEMO === "Y";
	}

	public function isTimeBound(): bool
	{
		return defined("TIMELIMIT_EDITION") && TIMELIMIT_EDITION === "Y";
	}

	public function isEncoded(): bool
	{
		return defined("ENCODE") && ENCODE === "Y";
	}

	public function getExpireDate(): ?Date
	{
		$_621382049 = (int)($GLOBALS["SiteExpireDate"] ?? 0);
		if ($_621382049 > 0) {
			return Date::createFromTimestamp($_621382049);
		}
		return null;
	}

	public function getSupportExpireDate(): ?Date
	{
		$_621382049 = Option::get("main", "~support_finish_date");
		if (Date::isCorrect($_621382049, "Y-m-d")) {
			return new Date($_621382049, "Y-m-d");
		}
		return null;
	}

	public function getRegion(): ?string
	{
		if (Loader::includeModule("bitrix24")) {
			return \CBitrix24::getPortalZone();
		}
		$_1646042363 = Option::get("main", "~PARAM_CLIENT_LANG");
		if (!empty($_1646042363)) {
			return $_1646042363;
		}
		$_1646042363 = $this->__744829629();
		if (!empty($_1646042363)) {
			return $_1646042363;
		}
		return $this->__752669590();
	}

	public function getEulaLink(): string
	{
		if (ModuleManager::isModuleInstalled("intranet")) {
			return self::URL_CP_EULA[$this->getRegion()] ?? self::URL_CP_EULA["en"];
		}
		return self::URL_BUS_EULA[$this->getRegion()] ?? self::URL_BUS_EULA["ru"];
	}

	private function __744829629(): ?string
	{
		$_281972719 = Option::get("main", "vendor");
		if ($_281972719 === "ua_bitrix_portal") {
			return "ua";
		}
		if ($_281972719 === "bitrix_portal") {
			return "en";
		}
		if ($_281972719 === "1c_bitrix_portal") {
			return "ru";
		}
		return null;
	}

	private function __752669590(): ?string
	{
		$_1232160304 = Application::getDocumentRoot();
		if (file_exists($_1232160304 . "/bitrix/modules/main/lang/ua")) {
			return "ua";
		}
		if (file_exists($_1232160304 . "/bitrix/modules/main/lang/by")) {
			return "by";
		}
		if (file_exists($_1232160304 . "/bitrix/modules/main/lang/kz")) {
			return "kz";
		}
		if (file_exists($_1232160304 . "/bitrix/modules/main/lang/ru")) {
			return "ru";
		}
		return null;
	}

	public function getPartnerId(): int
	{
		return (int)Option::get("main", "~PARAM_PARTNER_ID", 0);
	}

	public function getMaxUsers(): int
	{
		return (int)Option::get("main", "PARAM_MAX_USERS", 0);
	}

	public function isExtraCountable(): bool
	{
		return Option::get("main", "~COUNT_EXTRA", "N") === "Y" && ModuleManager::isModuleInstalled("extranet");
	}

	public function getActiveUsersCount(Date $_1494560293 = null): int
	{
		$_916719527  = Application::getConnection();
		$_1799268190 = 0;
		if ($_1494560293 !== null) {
			$_1142838885 = "AND U.LAST_LOGIN > " . $_916719527->getSqlHelper()->convertToDbDate($_1494560293);
		} else {
			$_1142838885 = "AND U.LAST_LOGIN IS NOT NULL";
		}
		if (ModuleManager::isModuleInstalled("intranet")) {
			$_100544774  = "
				SELECT COUNT(DISTINCT U.ID)
				FROM
					b_user U
					INNER JOIN b_user_field F ON F.ENTITY_ID = 'USER' AND F.FIELD_NAME = 'UF_DEPARTMENT'
					INNER JOIN b_utm_user UF ON
						UF.FIELD_ID = F.ID
						AND UF.VALUE_ID = U.ID
						AND UF.VALUE_INT > 0
				WHERE U.ACTIVE = 'Y'
					{$_1142838885}
			";
			$_1799268190 = (int)$_916719527->queryScalar($_100544774);
			$_1136161728 = (int)Option::get("extranet", "extranet_group");
			if ($_1136161728 > 0 && $this->isExtraCountable()) {
				$_100544774  = "
						SELECT COUNT(1)
						FROM
							b_user U
							INNER JOIN b_extranet_user EU ON EU.USER_ID = U.ID AND EU.CHARGEABLE = 'Y'
							INNER JOIN b_user_group UG ON UG.USER_ID = U.ID AND UG.GROUP_ID = {$_1136161728}
							LEFT JOIN (
								SELECT UF.VALUE_ID 
								FROM 
									b_user_field F
									INNER JOIN b_utm_user UF ON UF.FIELD_ID = F.ID AND UF.VALUE_INT > 0
								WHERE F.ENTITY_ID = 'USER' AND F.FIELD_NAME = 'UF_DEPARTMENT'
							) D ON D.VALUE_ID = U.ID
						WHERE U.ACTIVE = 'Y'
							{$_1142838885}
							AND D.VALUE_ID IS NULL
					";
				$_1799268190 += (int)$_916719527->queryScalar($_100544774);
			}
		}
		return $_1799268190;
	}

	public function getName(): string
	{
		return Option::get("main", "~license_name");
	}

	public function getCodes(): array
	{
		$_1475300407 = Option::get("main", "~license_codes");
		if ($_1475300407 != "") {
			return explode(",", $_1475300407);
		}
		return [];
	}
} ?>