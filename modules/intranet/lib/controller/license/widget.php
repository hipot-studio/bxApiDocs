<?php

namespace Bitrix\Intranet\Controller\license;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Engine\Controller;
use Bitrix\Intranet\License;
use Bitrix\Main\Engine\Response\AjaxJson;
use Bitrix\Main\Error;

class Widget extends Controller
{
	public function getDynamicContentAction(): AjaxJson
	{
		try
		{
			$dynamicContentCollection = (new License\Widget())->getDynamicContentCollection();

			return AjaxJson::createSuccess($dynamicContentCollection);
		}
		catch (ArgumentException $e)
		{
			$this->errorCollection->add([new Error($e->getMessage())]);

			return AjaxJson::createError($this->errorCollection);
		}
	}
}