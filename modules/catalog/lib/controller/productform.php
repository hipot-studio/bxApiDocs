<?php

namespace Bitrix\Catalog\Controller;

use Bitrix\Main\Engine;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Iblock\PropertyTable;
use Bitrix\Highloadblock as HL;
use Bitrix\Main\Security\Random;

class ProductForm extends Engine\Controller
{
	protected function getDefaultPreFilters()
	{
		return array_merge(
			parent::getDefaultPreFilters(),
			[
				new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
				new ActionFilter\Scope(ActionFilter\Scope::AJAX),
			]
		);
	}

	public function setConfigAction($configName, $value): void
	{
		$formConfigs = [
			'showTaxBlock', 'showDiscountBlock'
		];
		if (in_array($configName, $formConfigs, true))
		{
			$value = ($value === 'N') ? 'N' : 'Y';
			\CUserOptions::SetOption("catalog.product-form", $configName, $value);
		}
	}
}
