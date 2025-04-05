<?php

use Bitrix\Intranet\CurrentUser;
use Bitrix\Main\ArgumentException;
use Bitrix\Intranet\License;
use Bitrix\Main\Loader;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

class IntranetLicenseWidgetComponent extends \CBitrixComponent
{
	public function executeComponent(): void
	{
		if (!CurrentUser::get()->canDoOperation('bitrix24_config'))
		{
			return;
		}

		if (Loader::includeModule('extranet') && \CExtranet::IsExtranetSite())
		{
			return;
		}

		try
		{
			$this->arResult['CONTENT'] = (new License\Widget())->getContentCollection();
		}
		catch (ArgumentException)
		{
			return;
		}

		global $APPLICATION;
		$APPLICATION->SetPageProperty('HeaderClass', 'intranet-header--with-controls');
		$this->includeComponentTemplate();
	}
}