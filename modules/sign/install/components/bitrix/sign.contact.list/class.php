<?php
if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

\CBitrixComponent::includeComponentClass('bitrix:sign.base');

class SignContactListComponent extends SignBaseComponent
{
	protected function exec(): void
	{
		$this->prepareResult();
	}

	private function prepareResult(): void
	{
		$this->arResult['MENU_ITEMS'] = $this->arParams['MENU_ITEMS'] ?? [];
	}
}
