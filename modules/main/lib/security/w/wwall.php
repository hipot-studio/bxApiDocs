<? namespace Bitrix\Main\Security\W;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Security\PublicKeyCipher;
use Bitrix\Main\SystemException;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;
use Bitrix\Main\Security\W\Rules\Rule;
use Bitrix\Main\Security\W\Rules\Results\RuleAction;
use Bitrix\Main\Security\W\Rules\Results\RuleResult;
use Bitrix\Main\Security\W\Rules\Results\CheckResult;
use Bitrix\Main\Security\W\Rules\Results\ModifyResult;
use Bitrix\Main\Type\ArrayHelper;
use Bitrix\Main\Security\W\Rules\RuleRecordTable;
use CSecuritySystemInformation;
use ReflectionExtension;

class WWall
{
	const CACHE_RULES_TTL = 10800;
	private static $_1595112910 = 'https://wwall.bitrix.info/rules.php';
	protected $_1207803951 = true;

	public function handle()
	{
		try {
			$_113360321 = RuleRecordTable::getList(['cache' => ['ttl' => 3600 * 24 * 7]])->fetchAll();
			if (empty($_113360321)) {
				return;
			}
			$_1294581399 = Cache::createInstance();
			$_771971797  = false;
			if ($_1294581399->initCache(static::CACHE_RULES_TTL, 'WWALL_LOCK', 'security')) {
				$_1910314668 = $_1294581399->getVars();
				if (time() - $_1910314668 > 20) {
					$_2116576364 = Application::getConnection();
					$_1646610982 = RuleRecordTable::getTableName();
					$_2116576364->truncateTable($_1646610982);
					RuleRecordTable::cleanCache();
					$_1294581399->clean("WWALL_LOCK", "security");
				}
			} elseif ($_1294581399->startDataCache()) {
				$_1294581399->endDataCache(time());
				$_771971797 = true;
			}
			foreach ($_113360321 as $_307347118) {
				$_1944900867 = new PublicKeyCipher;
				$_996464131  = $_1944900867->decrypt($_307347118["DATA"], static::__1647648653());
				if (!str_starts_with($_996464131, "{\"")) {
					continue;
				}
				$_1263106608 = json_decode($_996464131, true);
				if (!empty($_1263106608)) {
					$_1543394599 = Rule::make($_1263106608);
					$_1644096946 = $this->handleRule($_1543394599);
					$this->applyHandlingResults($_1644096946);
				}
			}
			if ($_771971797) {
				$_1294581399->clean("WWALL_LOCK", "security");
			}
		} catch (\Throwable $_1906511821) {
			$this->logEvent("SECURITY_WWALL_EXCEPTION", "FAIL_CHECKING", "Can not execute wwall rules: " . $_1906511821->getMessage() . " Trace: " . $_1906511821->getTraceAsString());
		}
	}

	public function handleRule(Rule $_1543394599): array
	{
		$_1644096946 = [];
		if ($_1543394599->matchPath($_SERVER["REQUEST_URI"])) {
			$_1150178498 = $this->getContextElements($_1543394599->getContext());
			foreach ($_1150178498 as $_959964502 => &$_671473638) {
				$_1644096946 = array_merge($_1644096946, $this->recursiveContextKeyHandle($_959964502, $_671473638, [], $_1543394599));
			}
		}
		return $_1644096946;
	}

	public function applyHandlingResults(array $_1644096946)
	{
		$_1150178498 = $this->getContextElements(['get', 'post', 'cookie', 'request', 'global']);
		foreach ($_1644096946 as $_1762542951) {
			$_671473638  =& $_1150178498[$_1762542951->getContextName()];
			$_915688640  = $_1762542951->getRuleResult();
			$_1543394599 = $_1762542951->getRule();
			if ($_915688640 instanceof ModifyResult) {
				if ($_1543394599->getProcess() === "keys") {
					static::rewriteContextKey($_1762542951->getContextName(), $_671473638, $_1762542951->getContextKey(), $_915688640->getCleanValue());
				} elseif ($_1543394599->getProcess() === "values") {
					static::rewriteContextValue($_1762542951->getContextName(), $_671473638, $_1762542951->getContextKey(), $_915688640->getCleanValue());
				}
				$this->logEvent("SECURITY_WWALL_MODIFY", $_1762542951->getContextName(), join(" . ", $_1762542951->getContextKey()));
			} elseif ($_915688640 instanceof CheckResult && !$_915688640->isSuccess()) {
				if ($_915688640->getAction() === RuleAction::UNSET) {
					static::unsetContextValue($_1762542951->getContextName(), $_671473638, $_1762542951->getContextKey());
					$this->logEvent("SECURITY_WWALL_UNSET", $_1762542951->getContextName(), join(" . ", $_1762542951->getContextKey()));
				} elseif ($_915688640->getAction() === RuleAction::EXIT) {
					$this->logEvent("SECURITY_WWALL_EXIT", $_1762542951->getContextName(), join(" . ", $_1762542951->getContextKey()));
					exit;
				}
			}
		}
	}

	public function disableEventLogging()
	{
		$this->_1207803951 = false;
	}

	protected function rewriteContextKey($_959964502, &$_671473638, $_1478718078, $_1886277116)
	{
		$_1151456999 = $_1478718078;
		array_pop($_1151456999);
		$_1151456999[] = $_1886277116;
		if ($_959964502 === "global") {
			$_1368653070 = array_shift($_1478718078);
			array_shift($_1151456999);
			if (empty($_1478718078)) {
				$GLOBALS[$_1886277116] = $GLOBALS[$_1368653070];
				unset($GLOBALS[$_1368653070]);
			} else {
				$_671473638  =& $GLOBALS[$_1368653070];
				$_1393135192 = ArrayHelper::getByNestedKey($_671473638, $_1478718078);
				ArrayHelper::setByNestedKey($_671473638, $_1151456999, $_1393135192);
				ArrayHelper::unsetByNestedKey($_671473638, $_1478718078);
			}
		} else {
			$_1393135192 = ArrayHelper::getByNestedKey($_671473638, $_1478718078);
			ArrayHelper::setByNestedKey($_671473638, $_1151456999, $_1393135192);
			ArrayHelper::unsetByNestedKey($_671473638, $_1478718078);
		}
	}

	protected function rewriteContextValue($_959964502, &$_671473638, $_157129296, $_1393135192)
	{
		if ($_959964502 === 'global') {
			$_1368653070 = array_shift($_157129296);
			if (empty($_157129296)) {
				$GLOBALS[$_1368653070] = $_1393135192;
			} else {
				$_671473638 =& $GLOBALS[$_1368653070];
				ArrayHelper::setByNestedKey($_671473638, $_157129296, $_1393135192);
			}
		} else {
			ArrayHelper::setByNestedKey($_671473638, $_157129296, $_1393135192);
		}
	}

	protected function unsetContextValue($_959964502, &$_671473638, $_157129296)
	{
		if ($_959964502 === 'global') {
			$_1368653070 = array_shift($_157129296);
			if (empty($_157129296)) {
				unset($GLOBALS[$_1368653070]);
			} else {
				$_671473638 =& $GLOBALS[$_1368653070];
				ArrayHelper::unsetByNestedKey($_671473638, $_157129296);
			}
		} else {
			ArrayHelper::unsetByNestedKey($_671473638, $_157129296);
		}
	}

	protected function recursiveContextKeyHandle(string $_959964502, array &$_671473638, array $_648515286, Rule $_1543394599): array
	{
		$_1644096946 = [];
		foreach ($_671473638 as $_1801931574 => $_1393135192) {
			$_157129296 = array_merge($_648515286, [$_1801931574]);
			if ($_1543394599->matchKey($_157129296)) {
				if ($_1543394599->getProcess() === "keys") {
					$_915688640 = $_1543394599->evaluate($_1801931574);
				} elseif ($_1543394599->getProcess() === "values") {
					$_915688640 = $_1543394599->evaluateValue($_1393135192);
				}
				if (!empty($_915688640) && $_915688640 instanceof RuleResult) {
					$_1644096946[] = new HandlingResult($_959964502, $_157129296, $_915688640, $_1543394599);
				}
			}
			if (is_array($_1393135192)) {
				$_1644096946 = array_merge($_1644096946, $this->recursiveContextKeyHandle($_959964502, $_671473638[$_1801931574], $_157129296, $_1543394599));
			}
		}
		return $_1644096946;
	}

	protected function getContextElements(array $_2058598413)
	{
		$_1966765654 = [];
		if (in_array("get", $_2058598413, true)) {
			$_1966765654["get"] = &$_GET;
		}
		if (in_array("post", $_2058598413, true)) {
			$_1966765654["post"] = &$_POST;
		}
		if (in_array("cookie", $_2058598413, true)) {
			$_1966765654["cookie"] = &$_COOKIE;
		}
		if (in_array("request", $_2058598413, true)) {
			$_1966765654["request"] = &$_REQUEST;
		}
		if (in_array("global", $_2058598413, true)) {
			$_1966765654["global"] = $GLOBALS;
		}
		return $_1966765654;
	}

	public static function refreshRules()
	{
		try {
			$_675619171 = Option::get('main_sec', 'WWALL_ACTUALIZE_RULES', 0);
			if ((time() - $_675619171) < static::CACHE_RULES_TTL) {
				return;
			}
			Option::set("main_sec", "WWALL_ACTUALIZE_RULES", time());
			$_1050811176 = null;
			$_1394968919 = array_map(function ($_267001391) {
				return ["v" => $_267001391["version"], "i" => (int)$_267001391["isInstalled"]];
			}, ModuleManager::getModulesFromDisk());
			$_1450157244 = [];
			foreach (get_loaded_extensions() as $_570933349) {
				$_980337926               = new ReflectionExtension($_570933349);
				$_1450157244[$_570933349] = ["v" => $_980337926->getVersion(), "ini" => $_980337926->getINIEntries()];
			}
			$_215934176 = ["modules" => json_encode($_1394968919), "license" => Application::getInstance()->getLicense()->getHashLicenseKey(), "php" => json_encode(["v" => phpversion(), "ext" => $_1450157244])];
			if (Loader::includeModule("security")) {
				$_2089364499 = CSecuritySystemInformation::getSystemInformation();
				if (isset($_2089364499["db"]["type"]) && isset($_2089364499["db"]["version"])) {
					$_215934176["db"] = ["type" => $_2089364499["db"]["type"], "version" => $_2089364499["db"]["version"]];
				}
				if (isset($_2089364499["environment"]["vm_version"])) {
					$_215934176["vm"] = ["v" => $_2089364499["environment"]["vm_version"]];
				}
			}
			$_361252813  = new HttpClient(["socketTimeout" => 5, "streamTimeout" => 5]);
			$_1159474220 = $_361252813->post(static::$_1595112910, $_215934176);
			if ($_361252813->getStatus() == 200 && !empty($_1159474220)) {
				$_1050811176 = Json::decode($_1159474220);
			}
			if ($_1050811176 !== null) {
				$_2116576364 = Application::getConnection();
				$_1646610982 = RuleRecordTable::getTableName();
				if (!empty($_1050811176)) {
					foreach ($_1050811176 as $_494527507) {
						if (!static::checkRuleSign($_494527507)) {
							throw new SystemException('Invalid sign for rule ' . json_encode($_494527507));
						}
					}
				}
				$_2116576364->truncateTable($_1646610982);
				if (!empty($_1050811176)) {
					$_628617619 = [];
					foreach ($_1050811176 as $_494527507) {
						$_628617619[] = "('" . $_2116576364->getSqlHelper()->forSql($_494527507["data"]) . "', '" . $_2116576364->getSqlHelper()->forSql($_494527507["module"]) . "', '" . $_2116576364->getSqlHelper()->forSql($_494527507["module_version"]) . "')";
					}
					$_1805010675 = join(", ", $_628617619);
					$_2116576364->query("INSERT INTO {
					$_1646610982} (DATA, MODULE, MODULE_VERSION) VALUES {
					$_1805010675}");
					RuleRecordTable::cleanCache();
				}
			}
		} catch (\Throwable $_1906511821) {
			\CEventLog::log(\CEventLog::SEVERITY_SECURITY, "SECURITY_WWALL_EXCEPTION", "main", "FAIL_REFRESHING", "Can not refresh wwall rules: " . $_1906511821->getMessage() . " Trace: " . $_1906511821->getTraceAsString());
		}
	}

	protected static function checkRuleSign($_1543394599)
	{
		$_1944900867 = new PublicKeyCipher;
		$_1263106608 = $_1944900867->decrypt($_1543394599["data"], static::__1647648653());
		return str_starts_with($_1263106608, "{\"");
	}

	private static function __1647648653()
	{
		$_590100083 = '';
		$_590100083 .= "-----BEGIN public KEY-----";
		$_590100083 .= "
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAq8QE0HjmHJUStWV6n0za
RVoLx02KzbfrbS / P6sWaxTzw8SeGTtbTCOrpHi5QF6ORyjZ / Xxz / KLU1Gbof9CZ3
4z7SkqUt66ibXvOFBx4fw / APPRGDqtm0nD3fgGsu3RePgw29i8 + vm7mtBKJUYl4r
Vpb6sfZET9KEb6T1HDYmEvc1hq / iiuyxLrZZi5Q6Uff4UEvTI + 68ssFRkQ + owTRy
eOIMbFhM / UTmfVYbTRFy2oUQ8WMza2nJ5Sahzi1UKO1jAjXTPRrzc7Aju639j1O0
ppqfm5xgWlFAJkHQTgbdd5AWqDFQkt9HKkY + TnfBLGVMvVyPwTHNWQYAw4xpg / wA
ZwIDAQAB
-----END public KEY-----";
		return $_590100083;
	}

	protected function logEvent($_1613270556, $_2090284532, $_2030123088)
	{
		if ($this->_1207803951) {
			\CEventLog::log(\CEventLog::SEVERITY_SECURITY, $_1613270556, 'main', $_2090284532, $_2030123088);
		}
	}
} ?>