<?php

IncludeModuleLangFile(__FILE__);
class CEShop
{
    public static function ShowPanel()
    {
        if ($GLOBALS['USER']->IsAdmin() && 'eshop' === COption::GetOptionString('main', 'wizard_solution', '', SITE_ID)) {
            $GLOBALS['APPLICATION']->SetAdditionalCSS('/bitrix/wizards/bitrix/eshop/css/panel.css');

            $arMenu = [
                [
                    'ACTION' => "jsUtils.Redirect([], '".CUtil::JSEscape('/bitrix/admin/wizard_install.php?lang='.LANGUAGE_ID.'&wizardSiteID='.SITE_ID.'&wizardName=bitrix:eshop&'.bitrix_sessid_get())."')",
                    'ICON' => 'bx-popup-item-wizard-icon',
                    'TITLE' => GetMessage('STOM_BUTTON_TITLE_W1'),
                    'TEXT' => GetMessage('STOM_BUTTON_NAME_W1'),
                ],
            ];

            $GLOBALS['APPLICATION']->AddPanelButton([
                'HREF' => '/bitrix/admin/wizard_install.php?lang='.LANGUAGE_ID.'&wizardName=bitrix:eshop&wizardSiteID='.SITE_ID.'&'.bitrix_sessid_get(),
                'ID' => 'eshop_wizard',
                'ICON' => 'bx-panel-site-wizard-icon',
                'MAIN_SORT' => 2_500,
                'TYPE' => 'BIG',
                'SORT' => 10,
                'ALT' => GetMessage('SCOM_BUTTON_DESCRIPTION'),
                'TEXT' => GetMessage('SCOM_BUTTON_NAME'),
                'MENU' => $arMenu,
            ]);
        }
    }
}
