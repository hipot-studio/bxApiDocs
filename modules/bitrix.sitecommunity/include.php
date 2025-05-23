<?php

IncludeModuleLangFile(__FILE__);
class CSiteCommunity
{
    public static function ShowPanel()
    {
        if ($GLOBALS['USER']->IsAdmin() && 'community' === COption::GetOptionString('main', 'wizard_solution', '', SITE_ID)) {
            $GLOBALS['APPLICATION']->AddPanelButton([
                'HREF' => '/bitrix/admin/wizard_install.php?lang='.LANGUAGE_ID.'&wizardName=bitrix:demo_community&wizardSiteID='.SITE_ID.'&'.bitrix_sessid_get(),
                'ID' => 'demo_community_wizard',
                'ICON' => 'bx-panel-site-wizard-icon',
                'MAIN_SORT' => 2_500,
                'TYPE' => 'BIG',
                'SORT' => 10,
                'ALT' => GetMessage('SCOM_BUTTON_DESCRIPTION'),
                'TEXT' => GetMessage('SCOM_BUTTON_NAME'),
                'MENU' => [],
            ]);
        }
    }
}
