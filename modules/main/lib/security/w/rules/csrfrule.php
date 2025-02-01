<? namespace Bitrix\Main\Security\W\Rules;

use Bitrix\Main\Config\Option;
use Bitrix\Main\EventManager;
use Bitrix\Main\Security\W\Rules\Results\RuleAction;

class CsrfRule extends PregMatchRule
{
	public function __construct($_1244888263, $_124155583, $_933750740, $_1342074595, $_1935712793, $_696183146)
	{
		parent::__construct($_1244888263, $_124155583, $_933750740, $_1342074595, $_1935712793, $_696183146, RuleAction::EXIT);
	}

	public function evaluate($_1399585736)
	{
		$_2134094589 = parent::evaluate($_1399585736);
		if ($_2134094589 !== true) {
			EventManager::getInstance()->addEventHandler("main", "OnPageStart", function () {
				if (!check_bitrix_sessid()) {
					\CEventLog::log(\CEventLog::SEVERITY_SECURITY, "SECURITY_WWALL_EXIT", "main", "csrf", "csrf token is missing");
					if ($_129985600 = Option::get("security", "WWALL_EXIT_STRING")) {
						echo $_129985600;
					}
					exit;
				}
			});
		}
		return true;
	}
} ?>