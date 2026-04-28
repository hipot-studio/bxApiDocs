<?php

namespace Bitrix\Sign\Service\Integration\Crm;

use Bitrix\Crm;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Sign\Result\Result;

class FieldSelectorService
{
	public function getFields(array $options = []): Result
	{
		$result = new Result();
		if (!Loader::includeModule('crm'))
		{
			return $result->addError(new Error('Module `crm` is not installed'));
		}

		$selector = new Crm\Controller\Form\Fields\Selector();
		$crmFieldsData = $selector->getDataAction($options);
		$result->setData($crmFieldsData);

		return $result;
	}
}

