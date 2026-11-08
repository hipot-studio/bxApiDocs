<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\CallAssessment\Analytics;

use Bitrix\UI\Buttons\JsCode;

final class ClickEventWrapper
{
	public static function wrap(?JsCode $originalOnClick, ClickEventBuilder $builder): ?JsCode
	{
		if ($originalOnClick === null)
		{
			return null;
		}

		$originalCode = $originalOnClick->getCode();
		if (trim($originalCode) === '')
		{
			return null;
		}

		$analyticsJs = "BX.Runtime?.loadExtension(['crm.integration.analytics','ui.analytics'])"
			. "?.then(function(){"
			. "BX.UI.Analytics.sendData("
			. $builder->jsBuilderClass() . ".createDefault().buildData()"
			. ");"
			. "});";

		return new JsCode(
			'(function(){'
			. 'const result=(function(){'
			. $originalCode
			. "\n"
			. '}).apply(this, arguments);'
			. $analyticsJs
			. 'return result;'
			. '}).apply(this, arguments);',
		);
	}
}
