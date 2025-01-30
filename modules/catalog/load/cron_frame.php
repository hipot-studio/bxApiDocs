#!#PHP_PATH# -q
<?php

$_SERVER['DOCUMENT_ROOT'] = '#DOCUMENT_ROOT#';
$DOCUMENT_ROOT = $_SERVER['DOCUMENT_ROOT'];

$siteID = '#SITE_ID#';  // your site ID - need for language ID

// define("NO_KEEP_STATISTIC", true);
// define("NOT_CHECK_PERMISSIONS",true);
// define("BX_CAT_CRON", true);
// define('NO_AGENT_CHECK', true);
if (1 === preg_match('/^[a-z0-9_]{2}$/i', $siteID)) {
    // define('SITE_ID', $siteID);
} else {
    exit('No defined site - $siteID');
}

require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php';

if (!defined('LANGUAGE_ID') || 1 !== preg_match('/^[a-z]{2}$/i', LANGUAGE_ID)) {
    exit('Language id is absent - defined site is bad');
}

set_time_limit(0);

if (CModule::IncludeModule('catalog')) {
    $profile_id = 0;
    if (isset($argv[1])) {
        $profile_id = (int) $argv[1];
    }
    if ($profile_id <= 0) {
        exit('No profile id');
    }

    $ar_profile = CCatalogExport::GetByID($profile_id);
    if (!$ar_profile) {
        exit;
    }

    $strFile = CATALOG_PATH2EXPORTS.$ar_profile['FILE_NAME'].'_run.php';
    if (!file_exists($_SERVER['DOCUMENT_ROOT'].$strFile)) {
        $strFile = CATALOG_PATH2EXPORTS_DEF.$ar_profile['FILE_NAME'].'_run.php';
        if (!file_exists($_SERVER['DOCUMENT_ROOT'].$strFile)) {
            exit;
        }
    }

    $arSetupVars = [];
    $intSetupVarsCount = 0;
    if ('Y' !== $ar_profile['DEFAULT_PROFILE']) {
        parse_str($ar_profile['SETUP_VARS'], $arSetupVars);
        if (!empty($arSetupVars) && is_array($arSetupVars)) {
            $intSetupVarsCount = extract($arSetupVars, EXTR_SKIP);
        }
    }

    global $arCatalogAvailProdFields;
    $arCatalogAvailProdFields = CCatalogCSVSettings::getSettingsFields(CCatalogCSVSettings::FIELDS_ELEMENT);
    global $arCatalogAvailPriceFields;
    $arCatalogAvailPriceFields = CCatalogCSVSettings::getSettingsFields(CCatalogCSVSettings::FIELDS_CATALOG);
    global $arCatalogAvailValueFields;
    $arCatalogAvailValueFields = CCatalogCSVSettings::getSettingsFields(CCatalogCSVSettings::FIELDS_PRICE);
    global $arCatalogAvailQuantityFields;
    $arCatalogAvailQuantityFields = CCatalogCSVSettings::getSettingsFields(CCatalogCSVSettings::FIELDS_PRICE_EXT);
    global $arCatalogAvailGroupFields;
    $arCatalogAvailGroupFields = CCatalogCSVSettings::getSettingsFields(CCatalogCSVSettings::FIELDS_SECTION);

    global $defCatalogAvailProdFields;
    $defCatalogAvailProdFields = CCatalogCSVSettings::getDefaultSettings(CCatalogCSVSettings::FIELDS_ELEMENT);
    global $defCatalogAvailPriceFields;
    $defCatalogAvailPriceFields = CCatalogCSVSettings::getDefaultSettings(CCatalogCSVSettings::FIELDS_CATALOG);
    global $defCatalogAvailValueFields;
    $defCatalogAvailValueFields = CCatalogCSVSettings::getDefaultSettings(CCatalogCSVSettings::FIELDS_PRICE);
    global $defCatalogAvailQuantityFields;
    $defCatalogAvailQuantityFields = CCatalogCSVSettings::getDefaultSettings(CCatalogCSVSettings::FIELDS_PRICE_EXT);
    global $defCatalogAvailGroupFields;
    $defCatalogAvailGroupFields = CCatalogCSVSettings::getDefaultSettings(CCatalogCSVSettings::FIELDS_SECTION);
    global $defCatalogAvailCurrencies;
    $defCatalogAvailCurrencies = CCatalogCSVSettings::getDefaultSettings(CCatalogCSVSettings::FIELDS_CURRENCY);

    CCatalogDiscountSave::Disable();

    include $_SERVER['DOCUMENT_ROOT'].$strFile;
    CCatalogDiscountSave::Enable();

    CCatalogExport::Update(
        $profile_id,
        [
            '=LAST_USE' => $DB->GetNowFunction(),
        ]
    );
}
