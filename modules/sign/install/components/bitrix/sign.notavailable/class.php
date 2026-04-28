<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/**
 * @see Bitrix\Sign\Trait\Components\NotAvailableStubTrait
 */
final class SignNotAvailableComponent extends \CBitrixComponent
{
	public function executeComponent(): void
	{
		$this->arResult['PAGE_TITLE'] = (string)($this->arParams['PAGE_TITLE'] ?? Loc::getMessage('SIGN_CMP_NOT_AVAILABLE_PAGE_TITLE'));

		$this->arResult['STUB_TYPE'] = (string)($this->arParams['STUB_TYPE'] ?? 'notAvailable');

		$this->arResult['STUB_TITLE'] = (string)($this->arParams['STUB_TITLE'] ?? '');
		$this->arResult['STUB_DESC'] = (string)($this->arParams['STUB_DESC'] ?? '');

		$this->includeComponentTemplate();
	}
}
