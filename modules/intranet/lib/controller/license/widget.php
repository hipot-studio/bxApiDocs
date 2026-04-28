<?php

namespace Bitrix\Intranet\Controller\license;

use Bitrix\Intranet\ActionFilter\IntranetUser;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Engine\Controller;
use Bitrix\Intranet\License;
use Bitrix\Main\Engine\Response\AjaxJson;
use Bitrix\Main\Error;

class Widget extends Controller
{
	public function getDefaultPreFilters(): array
	{
		$prefilters = parent::getDefaultPreFilters();
		$prefilters[] = new IntranetUser();

		return $prefilters;
	}

	public function getContentAction(): AjaxJson
	{
		try
		{
			return AjaxJson::createSuccess((new License\Widget())->getContentCollection());
		}
		catch (ArgumentException $e)
		{
			$this->errorCollection->add([new Error($e->getMessage())]);

			return AjaxJson::createError($this->errorCollection);
		}
	}
}
