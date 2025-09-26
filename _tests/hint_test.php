<?php
defined('B_PROLOG_INCLUDED') || die();
/**
 * @global $APPLICATION \CMain
 * @global $USER \CUser
 * @global $DB \CDatabase
 * @global $USER_FIELD_MANAGER \CUserTypeManager
 * @global $BX_MENU_CUSTOM \CMenuCustom
 * @global $stackCacheManager \CStackCacheManager
 */

(new SaleOrderAjax)->executeComponent();
(new CatalogElementComponent)->onPrepareComponentParams();

\Bitrix\Main\EventManager::getInstance()->addEventHandler('main', _CEventsMain::OnEndBufferContent(), static fn($content) => $content);

_CEventsMain::OnEpilog();
_CEventsSale::OnSaleComponentOrderOneStepOrderProps();
AddEventHandler('main', 'OnPageNotFound', static fn() => '');

new CAdminTabControl();

CIBlockElement::GetList([], ['ID' => 1]);
CIblockSection::GetList([], ['ID' => 1]);
CIBlockElement::SetPropertyValuesEx(1, 2, ['test' => 'test'], ['NewElement' => true]);

CUpdateClient::UpdateUpdate();

$APPLICATION->IncludeFile();
$APPLICATION->IncludeComponent();

$APPLICATION->ShowTitle();

AddEventHandler('abtest');
\Bitrix\Main\EventManager::getInstance()->addEventHandler('workflow');
?>

<script>
	BX.ready(function(){
		BX.message({
		});
	});
	BX.UI.Hint.init();
</script>

