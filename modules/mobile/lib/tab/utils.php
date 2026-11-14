<?php
namespace Bitrix\Mobile\Tab;

use Bitrix\Main\Web\Json;

class Utils
{
	static function getComponentJSCode($config = [])
	{
		$code = $config["componentCode"];
		$title = Json::encode((string)$config["title"]);
		$rootWidget = Json::encode($config["rootWidget"]);
		$params = Json::encode($config["params"]);
		$scriptPath = $config["scriptPath"];

		$jsCode = <<<JS
		PageManager.openComponent("JSStackComponent",
				{
					title:$title,
					scriptPath:"$scriptPath",
					componentCode: "$code",
					params : $params,
					rootWidget:$rootWidget
				}
			)
JS;
		return $jsCode;
	}

}