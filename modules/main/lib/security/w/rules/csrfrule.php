<?php

namespace Bitrix\Main\Security\W\Rules;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\EventManager;
use Bitrix\Main\Security\W\Rules\Results\RuleAction;

class CsrfRule extends PregMatchRule
{
	public function __construct($path, $context, $keys, $process, $encoding, $pattern)
	{
		parent::__construct($path, $context, $keys, $process, $encoding, $pattern, RuleAction::EXIT);
	}

	public function evaluate($value)
	{
		$result = parent::evaluate($value);

		if ($result !== true)
		{
			// register callback
			EventManager::getInstance()->addEventHandler('main', 'OnPageStart', function () {

				if (!check_bitrix_sessid())
				{
					\CEventLog::log(
						\CEventLog::SEVERITY_SECURITY,
						'SECURITY_WWALL_EXIT',
						'main',
						'csrf',
						'csrf token is missing'
					);

					if ($out = Option::get('security', 'WWALL_EXIT_STRING'))
					{
						echo $out;
					}

					exit;
				}
			});
		}

		return true;
	}
}