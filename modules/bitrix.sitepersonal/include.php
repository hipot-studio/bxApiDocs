<?php

IncludeModuleLangFile(__FILE__);
class CSitePersonal
{
    public static function ShowPanel()
    {
        if ($GLOBALS['USER']->IsAdmin() && 'personal' === COption::GetOptionString('main', 'wizard_solution', '', SITE_ID)) {
            $GLOBALS['APPLICATION']->AddPanelButton([
                'HREF' => '/bitrix/admin/wizard_install.php?lang='.LANGUAGE_ID.'&wizardName=bitrix:demo_personal&wizardSiteID='.SITE_ID.'&'.bitrix_sessid_get(),
                'ID' => 'demo_personal_wizard',
                'ICON' => 'bx-panel-site-wizard-icon',
                'MAIN_SORT' => 2_500,
                'TYPE' => 'BIG',
                'SORT' => 10,
                'ALT' => GetMessage('SPER_BUTTON_DESCRIPTION'),
                'TEXT' => GetMessage('SPER_BUTTON_NAME'),
                'MENU' => [],
            ]);
        }
    }
}
