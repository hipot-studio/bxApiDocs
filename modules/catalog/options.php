<?php
/** @global CMain $APPLICATION */
/** @global CUser $USER */
/** @global CDatabase $DB */
$module_id = 'catalog';

use Bitrix\Catalog;
use Bitrix\Currency;
use Bitrix\Main;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;
use Bitrix\Sale;

// define('CATALOG_NEW_OFFERS_IBLOCK_NEED','-1');

$bReadOnly = !$USER->CanDoOperation('catalog_settings');
if (!$USER->CanDoOperation('catalog_read') && $bReadOnly) {
    return;
}

Loader::includeModule('catalog');
Loc::loadMessages(__FILE__);

$useSaleDiscountOnly = false;
$saleIsInstalled = ModuleManager::isModuleInstalled('sale');
if ($saleIsInstalled) {
    $useSaleDiscountOnly = 'Y' === (string) Option::get('sale', 'use_sale_discount_only');
}

$applyDiscSaveModeList = CCatalogDiscountSave::GetApplyModeList(true);

$saleSettingsUrl = 'settings.php?lang='.LANGUAGE_ID.'&mid=sale&mid_menu=1';

if ('GET' === $_SERVER['REQUEST_METHOD'] && !empty($_REQUEST['RestoreDefaults']) && !$bReadOnly && check_bitrix_sessid()) {
    $strValTmp = '';
    if (!$USER->IsAdmin()) {
        $strValTmp = Option::get('catalog', 'avail_content_groups');
    }

    Option::delete('catalog', []);
    $v1 = 'id';
    $v2 = 'asc';
    $z = CGroup::GetList($v1, $v2, ['ACTIVE' => 'Y', 'ADMIN' => 'N']);
    while ($zr = $z->Fetch()) {
        $APPLICATION->DelGroupRight($module_id, [$zr['ID']]);
    }

    if (!$USER->IsAdmin()) {
        Option::set('catalog', 'avail_content_groups', $strValTmp, '');
    }
}

$arAllOptions = [
    ['export_default_path', Loc::getMessage('CAT_EXPORT_DEFAULT_PATH'), '/bitrix/catalog_export/', ['text', 30]],
    ['default_catalog_1c', Loc::getMessage('CAT_DEF_IBLOCK'), '', ['text', 30]],
    ['deactivate_1c_no_price', Loc::getMessage('CAT_DEACT_NOPRICE'), 'N', ['checkbox']],
    ['yandex_xml_period', Loc::getMessage('CAT_YANDEX_XML_PERIOD'), '24', ['text', 5]],
];

$strWarning = '';
$strOK = '';
if ('POST' === $_SERVER['REQUEST_METHOD'] && !empty($_POST['Update']) && !$bReadOnly && check_bitrix_sessid()) {
    for ($i = 0, $cnt = count($arAllOptions); $i < $cnt; ++$i) {
        $name = $arAllOptions[$i][0];
        $val = (isset($_POST[$name]) ? trim($_POST[$name]) : '');
        if ('checkbox' === $arAllOptions[$i][3][0] && 'Y' !== $val) {
            $val = 'N';
        }
        if ('' === $val) {
            $val = $arAllOptions[$i][2];
        }
        if ('export_default_path' === $name) {
            $boolExpPath = true;
            if (empty($val)) {
                $boolExpPath = false;
            }
            if ($boolExpPath) {
                $val = str_replace('//', '/', Rel2Abs('/', $val.'/'));
                if (preg_match(BX_CATALOG_FILENAME_REG, $val)) {
                    $boolExpPath = false;
                }
            }
            if ($boolExpPath) {
                if (empty($val) || '/' === $val) {
                    $boolExpPath = false;
                }
            }
            if ($boolExpPath) {
                if (!file_exists($_SERVER['DOCUMENT_ROOT'].$val) || !is_dir($_SERVER['DOCUMENT_ROOT'].$val)) {
                    $boolExpPath = false;
                }
            }
            if ($boolExpPath) {
                if ($APPLICATION->GetFileAccessPermission($val) < 'W') {
                    $boolExpPath = false;
                }
            }

            if ($boolExpPath) {
                Option::set('catalog', $name, $val, '');
            } else {
                $strWarning .= Loc::getMessage('CAT_PATH_ERR_EXPORT_FOLDER_BAD').'<br />';
            }
        } else {
            Option::set('catalog', $name, $val, '');
        }
    }

    $default_outfile_action = (isset($_REQUEST['default_outfile_action']) ? (string) $_REQUEST['default_outfile_action'] : '');
    if ('D' !== $default_outfile_action && 'H' !== $default_outfile_action && 'F' !== $default_outfile_action) {
        $default_outfile_action = 'D';
    }
    Option::set('catalog', 'default_outfile_action', $default_outfile_action, '');

    $strYandexAgent = '';
    $strYandexAgent = trim($_POST['yandex_agent_file']);
    if (!empty($strYandexAgent)) {
        $strYandexAgent = Rel2Abs('/', $strYandexAgent);
        if (preg_match(BX_CATALOG_FILENAME_REG, $strYandexAgent) || (!file_exists($_SERVER['DOCUMENT_ROOT'].$strYandexAgent) || !is_file($_SERVER['DOCUMENT_ROOT'].$strYandexAgent))) {
            $strWarning .= Loc::getMessage('CAT_PATH_ERR_YANDEX_AGENT').'<br />';
            $strYandexAgent = '';
        }
    }
    Option::set('catalog', 'yandex_agent_file', $strYandexAgent, '');

    $num_catalog_levels = (isset($_POST['num_catalog_levels']) ? (int) $_POST['num_catalog_levels'] : 3);
    if ($num_catalog_levels <= 0) {
        $num_catalog_levels = 3;
    }
    Option::set('catalog', 'num_catalog_levels', $num_catalog_levels, '');

    $serialSelectFields = [
        'allowed_product_fields',
        'allowed_price_fields',
        'allowed_group_fields',
        'allowed_currencies',
    ];
    foreach ($serialSelectFields as &$oneSelect) {
        $fieldsClear = [];
        $fieldsRaw = (isset($_POST[$oneSelect]) ? $_POST[$oneSelect] : []);
        if (!is_array($fieldsRaw)) {
            $fieldsRaw = [$fieldsRaw];
        }
        if (!empty($fieldsRaw)) {
            foreach ($fieldsRaw as &$oneValue) {
                $oneValue = trim($oneValue);
                if ('' !== $oneValue) {
                    $fieldsClear[] = $oneValue;
                }
            }
            unset($oneValue);
        }
        Option::set('catalog', $oneSelect, implode(',', $fieldsClear), '');
    }
    unset($oneSelect);

    $viewedPeriodChange = false;
    $viewedTimeChange = false;
    if (isset($_POST['viewed_period'])) {
        $viewedPeriod = (int) $_POST['viewed_period'];
        if ($viewedPeriod > 0) {
            $oldViewedPeriod = (int) Option::get('catalog', 'viewed_period');
            $viewedPeriodChange = ($viewedPeriod !== $oldViewedPeriod);
            Option::set('catalog', 'viewed_period', $viewedPeriod, '');
        }
    }

    if (isset($_POST['viewed_time'])) {
        $viewedTime = (int) $_POST['viewed_time'];
        if ($viewedTime > 0) {
            $oldViewedTime = (int) Option::get('catalog', 'viewed_time');
            $viewedTimeChange = ($viewedTime !== $oldViewedTime);
            Option::set('catalog', 'viewed_time', $viewedTime, '');
        }
    }

    if ($viewedPeriodChange || $viewedTimeChange) {
        CAgent::RemoveAgent('\Bitrix\Catalog\CatalogViewedProductTable::clearAgent();', 'catalog');
        CAgent::AddAgent('\Bitrix\Catalog\CatalogViewedProductTable::clearAgent();', 'catalog', 'N', (int) Option::get('catalog', 'viewed_period') * 24 * 3_600);
    }

    if (isset($_POST['viewed_count'])) {
        $viewedCount = (int) $_POST['viewed_count'];
        if ($viewedCount > 0) {
            Option::set('catalog', 'viewed_count', $viewedCount, '');
        }
    }

    if ($USER->IsAdmin() && CBXFeatures::IsFeatureEnabled('SaleRecurring')) {
        $arOldAvailContentGroups = [];
        $oldAvailContentGroups = (string) Option::get('catalog', 'avail_content_groups');
        if ('' !== $oldAvailContentGroups) {
            $arOldAvailContentGroups = explode(',', $oldAvailContentGroups);
        }
        if (!empty($arOldAvailContentGroups)) {
            $arOldAvailContentGroups = array_fill_keys($arOldAvailContentGroups, true);
        }

        $fieldsClear = [];
        if (isset($_POST['AVAIL_CONTENT_GROUPS']) && is_array($_POST['AVAIL_CONTENT_GROUPS'])) {
            $fieldsClear = $_POST['AVAIL_CONTENT_GROUPS'];
            CatalogClearArray($fieldsClear);
            foreach ($fieldsClear as &$oneValue) {
                if (isset($arOldAvailContentGroups[$oneValue])) {
                    unset($arOldAvailContentGroups[$oneValue]);
                }
            }
            if (isset($oneValue)) {
                unset($oneValue);
            }
        }
        Option::set('catalog', 'avail_content_groups', implode(',', $fieldsClear), '');
        if (!empty($arOldAvailContentGroups)) {
            $arOldAvailContentGroups = array_keys($arOldAvailContentGroups);
            foreach ($arOldAvailContentGroups as &$oneValue) {
                CCatalogProductGroups::DeleteByGroup($oneValue);
            }
            unset($oneValue);
        }
    }

    $oldSimpleSearch = Option::get('catalog', 'product_form_simple_search');
    $newSimpleSearch = $oldSimpleSearch;
    $checkboxFields = [
        'save_product_without_price',
        'save_product_with_empty_price_range',
        'show_catalog_tab_with_offers',
        'default_product_vat_included',
        'product_form_show_offers_iblock',
        'product_form_simple_search',
        'product_form_show_offer_name',
    ];

    foreach ($checkboxFields as &$oneCheckbox) {
        if (empty($_POST[$oneCheckbox]) || !is_string($_POST[$oneCheckbox])) {
            continue;
        }
        $value = (string) $_POST[$oneCheckbox];
        if ('Y' !== $value && 'N' !== $value) {
            continue;
        }
        Option::set('catalog', $oneCheckbox, $value, '');

        if ('product_form_simple_search' === $oneCheckbox) {
            $newSimpleSearch = $value;
        }
    }
    unset($value, $oneCheckbox);

    if ($oldSimpleSearch !== $newSimpleSearch) {
        if ('Y' === $newSimpleSearch) {
            UnRegisterModuleDependences('search', 'BeforeIndex', 'catalog', '\Bitrix\Catalog\Product\Search', 'onBeforeIndex');
        } else {
            RegisterModuleDependences('search', 'BeforeIndex', 'catalog', '\Bitrix\Catalog\Product\Search', 'onBeforeIndex');
        }
    }
    unset($oldSimpleSearch, $newSimpleSearch);

    $strUseStoreControlBeforeSubmit = (string) Option::get('catalog', 'default_use_store_control');
    $strUseStoreControl = (isset($_POST['use_store_control']) && 'Y' === (string) $_POST['use_store_control'] ? 'Y' : 'N');

    if ($strUseStoreControlBeforeSubmit !== $strUseStoreControl) {
        if ('Y' === $strUseStoreControl) {
            $countStores = (int) CCatalogStore::GetList([], ['ACTIVE' => 'Y'], []);
            if ($countStores <= 0) {
                $arStoreFields = ['TITLE' => Loc::getMessage('CAT_STORE_NAME'), 'ADDRESS' => ' '];
                $newStoreId = CCatalogStore::Add($arStoreFields);
                if ($newStoreId) {
                    CCatalogDocs::synchronizeStockQuantity($newStoreId);
                } else {
                    $strWarning .= Loc::getMessage('CAT_STORE_ACTIVE_ERROR');
                    $strUseStoreControl = 'N';
                }
            } else {
                $strWarning .= Loc::getMessage('CAT_STORE_SYNCHRONIZE_WARNING');
            }
        } elseif ('N' === $strUseStoreControl) {
            $strWarning .= Loc::getMessage('CAT_STORE_DEACTIVATE_NOTICE');
        }
    }

    Option::set('catalog', 'default_use_store_control', $strUseStoreControl, '');

    if ('Y' === $strUseStoreControl) {
        $strEnableReservation = 'Y';
    } else {
        $strEnableReservation = (isset($_POST['enable_reservation']) && 'Y' === (string) $_POST['enable_reservation'] ? 'Y' : 'N');
    }
    Option::set('catalog', 'enable_reservation', $strEnableReservation, '');

    if (!$useSaleDiscountOnly) {
        if (CBXFeatures::IsFeatureEnabled('CatDiscountSave')) {
            $strDiscSaveApply = '';
            if (isset($_REQUEST['discsave_apply'])) {
                $strDiscSaveApply = (string) $_REQUEST['discsave_apply'];
            }
            if ('' !== $strDiscSaveApply && isset($applyDiscSaveModeList[$strDiscSaveApply])) {
                Option::set('catalog', 'discsave_apply', $strDiscSaveApply, '');
            }
        }
        if (!$saleIsInstalled) {
            $discountPercent = '';
            if (isset($_REQUEST['get_discount_percent_from_base_price'])) {
                $discountPercent = (string) $_REQUEST['get_discount_percent_from_base_price'];
            }
            if ('Y' === $discountPercent || 'N' === $discountPercent) {
                Option::set('catalog', 'get_discount_percent_from_base_price', $discountPercent, '');
            }
            unset($discountPercent);
        }
        /*		$strDiscountVat = (!empty($_REQUEST['discount_vat']) && $_REQUEST['discount_vat'] == 'N' ? 'N' : 'Y');
                Option::set('catalog', 'discount_vat', $strDiscountVat, ''); */
    }

    $bNeedAgent = false;

    $boolFlag = true;
    $arCurrentIBlocks = [];
    $arNewIBlocksList = [];
    $rsIBlocks = CIBlock::GetList([]);
    while ($arOneIBlock = $rsIBlocks->Fetch()) {
        // Current info
        $arOneIBlock['ID'] = (int) $arOneIBlock['ID'];
        $arIBlockItem = [];
        $arIBlockSitesList = [];
        $rsIBlockSites = CIBlock::GetSite($arOneIBlock['ID']);
        while ($arIBlockSite = $rsIBlockSites->Fetch()) {
            $arIBlockSitesList[] = htmlspecialcharsbx($arIBlockSite['SITE_ID']);
        }

        $strInfo = '['.$arOneIBlock['IBLOCK_TYPE_ID'].'] '.htmlspecialcharsbx($arOneIBlock['NAME']).' ('.implode(' ', $arIBlockSitesList).')';

        $arIBlockItem = [
            'INFO' => $strInfo,
            'ID' => $arOneIBlock['ID'],
            'NAME' => $arOneIBlock['NAME'],
            'SITE_ID' => $arIBlockSitesList,
            'IBLOCK_TYPE_ID' => $arOneIBlock['IBLOCK_TYPE_ID'],
            'CATALOG' => 'N',
            'PRODUCT_IBLOCK_ID' => 0,
            'SKU_PROPERTY_ID' => 0,
            'OFFERS_IBLOCK_ID' => 0,
            'OFFERS_PROPERTY_ID' => 0,
        ];
        $arCurrentIBlocks[$arOneIBlock['ID']] = $arIBlockItem;
    }
    $arCatalogList = [];
    $catalogIterator = Catalog\CatalogIblockTable::getList([
        'select' => ['IBLOCK_ID', 'PRODUCT_IBLOCK_ID', 'SKU_PROPERTY_ID', 'SUBSCRIPTION', 'YANDEX_EXPORT', 'VAT_ID'],
    ]);
    while ($arCatalog = $catalogIterator->fetch()) {
        $arCatalog['IBLOCK_ID'] = (int) $arCatalog['IBLOCK_ID'];
        $arCatalog['PRODUCT_IBLOCK_ID'] = (int) $arCatalog['PRODUCT_IBLOCK_ID'];
        $arCatalog['SKU_PROPERTY_ID'] = (int) $arCatalog['SKU_PROPERTY_ID'];
        $arCatalog['VAT_ID'] = (int) $arCatalog['VAT_ID'];

        $arCatalogList[$arCatalog['IBLOCK_ID']] = $arCatalog;

        $arCurrentIBlocks[$arCatalog['IBLOCK_ID']]['CATALOG'] = 'Y';
        $arCurrentIBlocks[$arCatalog['IBLOCK_ID']]['PRODUCT_IBLOCK_ID'] = $arCatalog['PRODUCT_IBLOCK_ID'];
        $arCurrentIBlocks[$arCatalog['IBLOCK_ID']]['SKU_PROPERTY_ID'] = $arCatalog['SKU_PROPERTY_ID'];
        if (0 < $arCatalog['PRODUCT_IBLOCK_ID']) {
            $arCurrentIBlocks[$arCatalog['PRODUCT_IBLOCK_ID']]['OFFERS_IBLOCK_ID'] = $arCatalog['IBLOCK_ID'];
            $arCurrentIBlocks[$arCatalog['PRODUCT_IBLOCK_ID']]['OFFERS_PROPERTY_ID'] = $arCatalog['SKU_PROPERTY_ID'];
        }
    }
    unset($arCatalog, $catalogIterator);

    foreach ($arCurrentIBlocks as &$arOneIBlock) {
        // From form
        $is_cat = (('Y' === ${'IS_CATALOG_'.$arOneIBlock['ID']}) ? 'Y' : 'N');
        $is_cont = (('Y' !== ${'IS_CONTENT_'.$arOneIBlock['ID']}) ? 'N' : 'Y');
        $yan_exp = (('Y' !== ${'YANDEX_EXPORT_'.$arOneIBlock['ID']}) ? 'N' : 'Y');
        $cat_vat = (int) ${'VAT_ID_'.$arOneIBlock['ID']};

        $offer_name = trim(${'OFFERS_NAME_'.$arOneIBlock['ID']});
        $offer_type = trim(${'OFFERS_TYPE_'.$arOneIBlock['ID']});
        $offer_new_type = '';
        $offer_new_type = trim(${'OFFERS_NEWTYPE_'.$arOneIBlock['ID']});
        $flag_new_type = ('Y' === ${'CREATE_OFFERS_TYPE_'.$arOneIBlock['ID']} ? 'Y' : 'N');

        $offers_iblock_id = (int) ${'OFFERS_IBLOCK_ID_'.$arOneIBlock['ID']};

        $arNewIBlockItem = [
            'ID' => $arOneIBlock['ID'],
            'CATALOG' => $is_cat,
            'SUBSCRIPTION' => $is_cont,
            'YANDEX_EXPORT' => $yan_exp,
            'VAT_ID' => $cat_vat,
            'OFFERS_IBLOCK_ID' => $offers_iblock_id,
            'OFFERS_NAME' => $offer_name,
            'OFFERS_TYPE' => $offer_type,
            'OFFERS_NEW_TYPE' => $offer_new_type,
            'CREATE_OFFERS_NEW_TYPE' => $flag_new_type,
            'NEED_IS_REQUIRED' => 'N',
            'NEED_UPDATE' => 'N',
            'NEED_LINK' => 'N',
            'OFFERS_PROP' => 0,
        ];
        $arNewIBlocksList[$arOneIBlock['ID']] = $arNewIBlockItem;
    }
    if (isset($arOneIBlock)) {
        unset($arOneIBlock);
    }

    // check for offers is catalog
    foreach ($arCurrentIBlocks as $intIBlockID => $arIBlockInfo) {
        if ((0 < $arIBlockInfo['PRODUCT_IBLOCK_ID']) && ('Y' !== $arNewIBlocksList[$intIBlockID]['CATALOG'])) {
            $arNewIBlocksList[$intIBlockID]['CATALOG'] = 'Y';
        }
    }
    // check for double using iblock and selfmade
    $arOffersIBlocks = [];
    foreach ($arNewIBlocksList as $intIBlockID => $arIBlockInfo) {
        if (0 < $arIBlockInfo['OFFERS_IBLOCK_ID']) {
            // double
            if (isset($arOffersIBlocks[$arIBlockInfo['OFFERS_IBLOCK_ID']])) {
                $boolFlag = false;
                $strWarning .= Loc::getMessage(
                    'CAT_IBLOCK_OFFERS_ERR_TOO_MANY_PRODUCT_IBLOCK',
                    ['#OFFER#' => $arCurrentIBlocks[$arIBlockInfo['OFFERS_IBLOCK_ID']]['INFO']]
                ).'<br />';
            } else {
                $arOffersIBlocks[$arIBlockInfo['OFFERS_IBLOCK_ID']] = true;
            }
            // selfmade
            if ($arIBlockInfo['OFFERS_IBLOCK_ID'] === $intIBlockID) {
                $boolFlag = false;
                $strWarning .= Loc::getMessage(
                    'CAT_IBLOCK_OFFERS_ERR_SELF_MADE',
                    ['#PRODUCT#' => $arCurrentIBlocks[$intIBlockID]['INFO']]
                ).'<br />';
            }
        }
    }
    unset($arOffersIBlocks);
    // check for rights
    if ($boolFlag) {
        if (!$USER->IsAdmin()) {
            foreach ($arNewIBlocksList as $intIBlockID => $arIBlockInfo) {
                if (CATALOG_NEW_OFFERS_IBLOCK_NEED === $arIBlockInfo['OFFERS_IBLOCK_ID']) {
                    $boolFlag = false;
                    $strWarning .= Loc::getMessage(
                        'CAT_IBLOCK_OFFERS_ERR_CANNOT_CREATE_IBLOCK',
                        ['#PRODUCT#' => $arCurrentIBlocks[$intIBlockID]['INFO']]
                    ).'<br />';
                }
            }
        }
    }
    // check for offers next offers
    if ($boolFlag) {
        foreach ($arCurrentIBlocks as $intIBlockID => $arIBlockInfo) {
            if (0 < $arIBlockInfo['PRODUCT_IBLOCK_ID'] && 0 !== $arNewIBlocksList[$intIBlockID]['OFFERS_IBLOCK_ID']) {
                $boolFlag = false;
                $strWarning .= Loc::getMessage(
                    'CAT_IBLOCK_OFFERS_ERR_PRODUCT_AND_OFFERS',
                    ['#PRODUCT#' => $arIBlockInfo['INFO']]
                ).'<br />';
            }
        }
    }
    // check for product as offer
    if ($boolFlag) {
        foreach ($arNewIBlocksList as $intIBlockID => $arIBlockInfo) {
            if (0 < $arIBlockInfo['OFFERS_IBLOCK_ID'] && 0 < $arCurrentIBlocks[$arIBlockInfo['OFFERS_IBLOCK_ID']]['OFFERS_IBLOCK_ID']) {
                $boolFlag = false;
                $strWarning .= Loc::getMessage(
                    'CAT_IBLOCK_OFFERS_ERR_PRODUCT_AND_OFFERS',
                    ['#PRODUCT#' => $arCurrentIBlocks[$arIBlockInfo['OFFERS_IBLOCK_ID']]['INFO']]
                ).'<br />';
            }
        }
    }
    if ($boolFlag) {
        foreach ($arNewIBlocksList as $intIBlockID => $arIBlockInfo) {
            if (0 < $arIBlockInfo['OFFERS_IBLOCK_ID'] && 0 < $arNewIBlocksList[$arIBlockInfo['OFFERS_IBLOCK_ID']]['OFFERS_IBLOCK_ID']) {
                $boolFlag = false;
                $strWarning .= Loc::getMessage(
                    'CAT_IBLOCK_OFFERS_ERR_PRODUCT_AND_OFFERS',
                    ['#PRODUCT#' => $arNewIBlocksList[$arIBlockInfo['OFFERS_IBLOCK_ID']]['INFO']]
                ).'<br />';
            }
        }
    }
    if ($boolFlag) {
        foreach ($arNewIBlocksList as $intIBlockID => $arIBlockInfo) {
            if (0 < $arIBlockInfo['OFFERS_IBLOCK_ID'] && CATALOG_NEW_OFFERS_IBLOCK_NEED === $arNewIBlocksList[$arIBlockInfo['OFFERS_IBLOCK_ID']]['OFFERS_IBLOCK_ID']) {
                $boolFlag = false;
                $strWarning .= Loc::getMessage(
                    'CAT_IBLOCK_OFFERS_ERR_PRODUCT_AND_OFFERS',
                    ['#PRODUCT#' => $arNewIBlocksList[$arIBlockInfo['OFFERS_IBLOCK_ID']]['INFO']]
                ).'<br />';
            }
        }
    }

    // check name and new iblock_type
    if ($boolFlag) {
        foreach ($arNewIBlocksList as $intIBlockID => $arIBlockInfo) {
            if (CATALOG_NEW_OFFERS_IBLOCK_NEED === $arIBlockInfo['OFFERS_IBLOCK_ID']) {
                if ('' === trim($arIBlockInfo['OFFERS_NAME'])) {
                    $arNewIBlocksList[$intIBlockID]['OFFERS_NAME'] = Loc::getMessage(
                        'CAT_IBLOCK_OFFERS_NAME_TEPLATE',
                        ['#PRODUCT#' => $arCurrentIBlocks[$intIBlockID]['NAME']]
                    );
                }
                if ('Y' === $arIBlockInfo['CREATE_OFFERS_NEW_TYPE'] && '' === trim($arIBlockInfo['OFFERS_NEW_TYPE'])) {
                    $arNewIBlocksList[$intIBlockID]['CREATE_OFFERS_NEW_TYPE'] = 'N';
                    $arNewIBlocksList[$intIBlockID]['OFFERS_TYPE'] = $arCurrentIBlocks[$intIBlockID]['IBLOCK_TYPE_ID'];
                }
                if ('N' === $arIBlockInfo['CREATE_OFFERS_NEW_TYPE'] && '' === trim($arIBlockInfo['OFFERS_TYPE'])) {
                    $arNewIBlocksList[$intIBlockID]['CREATE_OFFERS_NEW_TYPE'] = 'N';
                    $arNewIBlocksList[$intIBlockID]['OFFERS_TYPE'] = $arCurrentIBlocks[$intIBlockID]['IBLOCK_TYPE_ID'];
                }
            }
        }
    }
    // check for sites
    if ($boolFlag) {
        foreach ($arNewIBlocksList as $intIBlockID => $arIBlockInfo) {
            if (0 < $arIBlockInfo['OFFERS_IBLOCK_ID']) {
                $arDiffParent = [];
                $arDiffParent = array_diff($arCurrentIBlocks[$intIBlockID]['SITE_ID'], $arCurrentIBlocks[$arIBlockInfo['OFFERS_IBLOCK_ID']]['SITE_ID']);
                $arDiffOffer = [];
                $arDiffOffer = array_diff($arCurrentIBlocks[$arIBlockInfo['OFFERS_IBLOCK_ID']]['SITE_ID'], $arCurrentIBlocks[$intIBlockID]['SITE_ID']);
                if (!empty($arDiffParent) || !empty($arDiffOffer)) {
                    $boolFlag = false;
                    $strWarning .= Loc::getMessage(
                        'CAT_IBLOCK_OFFERS_ERR_SITELIST_DEFFERENT',
                        [
                            '#PRODUCT#' => $arCurrentIBlocks[$intIBlockID]['INFO'],
                            '#OFFERS#' => $arCurrentIBlocks[$arIBlockInfo['OFFERS_IBLOCK_ID']]['INFO'],
                        ]
                    ).'<br />';
                }
            }
        }
    }
    // check properties
    if ($boolFlag) {
        foreach ($arNewIBlocksList as $intIBlockID => $arIBlockInfo) {
            if (0 < $arIBlockInfo['OFFERS_IBLOCK_ID']) {
                // search properties
                $intCountProp = 0;
                $arLastProp = false;
                $rsProps = CIBlockProperty::GetList([], ['IBLOCK_ID' => $arIBlockInfo['OFFERS_IBLOCK_ID'], 'PROPERTY_TYPE' => 'E', 'LINK_IBLOCK_ID' => $intIBlockID, 'ACTIVE' => 'Y', 'USER_TYPE' => 'SKU']);
                if ($arProp = $rsProps->Fetch()) {
                    ++$intCountProp;
                    $arLastProp = $arProp;
                    while ($arProp = $rsProps->Fetch()) {
                        if (false !== $arProp) {
                            $arLastProp = $arProp;
                            ++$intCountProp;
                        }
                    }
                }
                if (1 < $intCountProp) {
                    // too many links for catalog
                    $boolFlag = false;
                    $strWarning .= Loc::getMessage(
                        'CAT_IBLOCK_OFFERS_ERR_TOO_MANY_LINKS',
                        [
                            '#OFFER#' => $arCurrentIBlocks[$arIBlockInfo['OFFERS_IBLOCK_ID']]['INFO'],
                            '#PRODUCT#' => $arCurrentIBlocks[$intIBlockID]['INFO'],
                        ]
                    ).'<br />';
                } elseif (1 === $intCountProp) {
                    if ('Y' === $arLastProp['MULTIPLE']) {
                        // link must single property
                        $boolFlag = false;
                        $strWarning .= Loc::getMessage(
                            'CAT_IBLOCK_OFFERS_ERR_LINKS_MULTIPLE',
                            [
                                '#OFFER#' => $arCurrentIBlocks[$arIBlockInfo['OFFERS_IBLOCK_ID']]['INFO'],
                                '#PRODUCT#' => $arCurrentIBlocks[$intIBlockID]['INFO'],
                            ]
                        ).'<br />';
                    } elseif (('SKU' !== $arLastProp['USER_TYPE']) || ('CML2_LINK' !== $arLastProp['XML_ID'])) {
                        // link must is updated
                        $arNewIBlocksList[$intIBlockID]['NEED_UPDATE'] = 'Y';
                        $arNewIBlocksList[$intIBlockID]['OFFERS_PROP'] = $arLastProp['ID'];
                    } else {
                        $arNewIBlocksList[$intIBlockID]['OFFERS_PROP'] = $arLastProp['ID'];
                    }
                } elseif (0 === $intCountProp) {
                    // create offers iblock
                    $arNewIBlocksList[$intIBlockID]['NEED_IS_REQUIRED'] = 'N';
                    $arNewIBlocksList[$intIBlockID]['NEED_UPDATE'] = 'Y';
                    $arNewIBlocksList[$intIBlockID]['NEED_LINK'] = 'Y';
                }
            }
        }
    }
    // create iblock
    $arNewOffers = [];
    if ($boolFlag) {
        $DB->StartTransaction();
        foreach ($arNewIBlocksList as $intIBlockID => $arIBlockInfo) {
            if (CATALOG_NEW_OFFERS_IBLOCK_NEED === $arIBlockInfo['OFFERS_IBLOCK_ID']) {
                // need new offers
                $arResultNewCatalogItem = [];
                if ('Y' === $arIBlockInfo['CREATE_OFFERS_NEW_TYPE']) {
                    $rsIBlockTypes = CIBlockType::GetByID($arIBlockInfo['OFFERS_NEW_TYPE']);
                    if ($arIBlockType = $rsIBlockTypes->Fetch()) {
                        $arIBlockInfo['OFFERS_TYPE'] = $arIBlockInfo['OFFERS_NEW_TYPE'];
                    } else {
                        $arFields = [
                            'ID' => $arIBlockInfo['OFFERS_NEW_TYPE'],
                            'SECTIONS' => 'N',
                            'IN_RSS' => 'N',
                            'SORT' => 500,
                        ];

                        $languageIterator = Main\Localization\LanguageTable::getList([
                            'select' => ['ID', 'SORT'],
                            'filter' => ['=ACTIVE' => 'Y'],
                            'order' => ['SORT' => 'ASC'],
                        ]);
                        while ($language = $languageIterator->fetch()) {
                            $arFields['LANG'][$language['ID']]['NAME'] = $arIBlockInfo['OFFERS_NEW_TYPE'];
                        }
                        unset($language, $languageIterator);

                        $obIBlockType = new CIBlockType();
                        $mxOffersType = $obIBlockType->Add($arFields);
                        if (!$mxOffersType) {
                            $boolFlag = false;
                            $strWarning .= Loc::getMessage(
                                'CAT_IBLOCK_OFFERS_ERR_NEW_IBLOCK_TYPE_NOT_ADD',
                                [
                                    '#PRODUCT#' => $arCurrentIBlocks[$intIBlockID]['INFO'],
                                    '#ERROR#' => $obIBlockType->LAST_ERROR,
                                ]
                            ).'<br />';
                        } else {
                            $arIBlockInfo['OFFERS_TYPE'] = $arIBlockInfo['OFFERS_NEW_TYPE'];
                        }
                    }
                }
                if ($boolFlag) {
                    $arParentRights = CIBlock::GetGroupPermissions($intIBlockID);
                    foreach ($arParentRights as $keyRight => $valueRight) {
                        if ('U' === $valueRight) {
                            $arParentRights[$keyRight] = 'W';
                        }
                    }
                    $arFields = [
                        'SITE_ID' => $arCurrentIBlocks[$intIBlockID]['SITE_ID'],
                        'IBLOCK_TYPE_ID' => $arIBlockInfo['OFFERS_TYPE'],
                        'NAME' => $arIBlockInfo['OFFERS_NAME'],
                        'ACTIVE' => 'Y',
                        'GROUP_ID' => $arParentRights,
                        'WORKFLOW' => 'N',
                        'BIZPROC' => 'N',
                        'LIST_PAGE_URL' => '',
                        'SECTION_PAGE_URL' => '',
                        'DETAIL_PAGE_URL' => '#PRODUCT_URL#',
                        'INDEX_SECTION' => 'N',
                    ];
                    $obIBlock = new CIBlock();
                    $mxOffersID = $obIBlock->Add($arFields);
                    if (false === $mxOffersID) {
                        $boolFlag = false;
                        $strWarning .= Loc::getMessage(
                            'CAT_IBLOCK_OFFERS_ERR_IBLOCK_ADD',
                            [
                                '#PRODUCT#' => $arCurrentIBlocks[$intIBlockID]['INFO'],
                                '#ERR#' => $obIBlock->LAST_ERROR,
                            ]
                        ).'<br />';
                    } else {
                        $arResultNewCatalogItem = [
                            'INFO' => '['.$arFields['IBLOCK_TYPE_ID'].'] '.htmlspecialcharsbx($arFields['NAME']).' ('.implode(' ', $arCurrentIBlocks[$intIBlockID]['SITE_ID']).')',
                            'SITE_ID' => $arCurrentIBlocks[$intIBlockID]['SITE_ID'],
                            'IBLOCK_TYPE_ID' => $arFields['IBLOCK_TYPE_ID'],
                            'ID' => $mxOffersID,
                            'NAME' => $arFields['NAME'],
                            'CATALOG' => 'Y',
                            'IS_CONTENT' => 'N',
                            'YANDEX_EXPORT' => 'N',
                            'VAT_ID' => 0,
                            'PRODUCT_IBLOCK_ID' => $intIBlockID,
                            'SKU_PROPERTY_ID' => 0,
                            'NEED_IS_REQUIRED' => 'N',
                            'NEED_UPDATE' => 'Y',
                            'LINK_PROP' => false,
                            'NEED_LINK' => 'Y',
                        ];
                        $arFields = [
                            'IBLOCK_ID' => $mxOffersID,
                            'NAME' => Loc::getMessage('CAT_IBLOCK_OFFERS_TITLE_LINK_NAME'),
                            'ACTIVE' => 'Y',
                            'PROPERTY_TYPE' => 'E',
                            'MULTIPLE' => 'N',
                            'LINK_IBLOCK_ID' => $intIBlockID,
                            'CODE' => 'CML2_LINK',
                            'XML_ID' => 'CML2_LINK',
                            'FILTRABLE' => 'Y',
                            'USER_TYPE' => 'SKU',
                        ];
                        $obProp = new CIBlockProperty();
                        $mxPropID = $obProp->Add($arFields);
                        if (!$mxPropID) {
                            $boolFlag = false;
                            $strWarning .= Loc::getMessage(
                                'CAT_IBLOCK_OFFERS_ERR_CANNOT_CREATE_LINK',
                                [
                                    '#OFFERS#' => $arResultNewCatalogItem['INFO'],
                                    '#ERR#' => $obProp->LAST_ERROR,
                                ]
                            ).'<br />';
                        } else {
                            $arResultNewCatalogItem['SKU_PROPERTY_ID'] = $mxPropID;
                            $arResultNewCatalogItem['NEED_IS_REQUIRED'] = 'N';
                            $arResultNewCatalogItem['NEED_UPDATE'] = 'N';
                            $arResultNewCatalogItem['NEED_LINK'] = 'N';
                        }
                    }
                }
                if ($boolFlag) {
                    $arNewOffers[$mxOffersID] = $arResultNewCatalogItem;
                } else {
                    break;
                }
            }
        }
        if (!$boolFlag) {
            $DB->Rollback();
        } else {
            $DB->Commit();
        }
    }
    // create properties
    if ($boolFlag) {
        $DB->StartTransaction();
        foreach ($arNewIBlocksList as $intIBlockID => $arIBlockInfo) {
            if (0 < $arIBlockInfo['OFFERS_IBLOCK_ID']) {
                if ('Y' === $arIBlockInfo['NEED_LINK']) {
                    $arFields = [
                        'IBLOCK_ID' => $arIBlockInfo['OFFERS_IBLOCK_ID'],
                        'NAME' => Loc::getMessage('CAT_IBLOCK_OFFERS_TITLE_LINK_NAME'),
                        'ACTIVE' => 'Y',
                        'PROPERTY_TYPE' => 'E',
                        'MULTIPLE' => 'N',
                        'LINK_IBLOCK_ID' => $intIBlockID,
                        'CODE' => 'CML2_LINK',
                        'XML_ID' => 'CML2_LINK',
                        'FILTRABLE' => 'Y',
                        'USER_TYPE' => 'SKU',
                    ];
                    $obProp = new CIBlockProperty();
                    $mxPropID = $obProp->Add($arFields);
                    if (!$mxPropID) {
                        $boolFlag = false;
                        $strWarning .= Loc::getMessage(
                            'CAT_IBLOCK_OFFERS_ERR_CANNOT_CREATE_LINK',
                            [
                                '#OFFERS#' => $arCurrentIBlocks[$arIBlockInfo['OFFERS_IBLOCK_ID']]['INFO'],
                                '#ERR#' => $obProp->LAST_ERROR,
                            ]
                        ).'<br />';
                    } else {
                        $arNewIBlocksList[$intIBlockID]['OFFERS_PROP'] = $mxPropID;
                        $arNewIBlocksList[$intIBlockID]['NEED_IS_REQUIRED'] = 'N';
                        $arNewIBlocksList[$intIBlockID]['NEED_UPDATE'] = 'N';
                        $arNewIBlocksList[$intIBlockID]['NEED_LINK'] = 'N';
                    }
                } elseif (0 < $arIBlockInfo['OFFERS_PROP']) {
                    if ('Y' === $arIBlockInfo['NEED_UPDATE']) {
                        $arPropFields = [
                            'USER_TYPE' => 'SKU',
                            'XML_ID' => 'CML2_LINK',
                        ];
                        $obProp = new CIBlockProperty();
                        $mxPropID = $obProp->Update($arIBlockInfo['OFFERS_PROP'], $arPropFields);
                        if (!$mxPropID) {
                            $boolFlag = false;
                            $strWarning .= Loc::getMessage(
                                'CAT_IBLOCK_OFFERS_ERR_MODIFY_PROP_IS_REQ',
                                [
                                    '#OFFERS#' => $arCurrentIBlocks[$arIBlockInfo['OFFERS_IBLOCK_ID']]['INFO'],
                                    '#ERR#' => $obProp->LAST_ERROR,
                                ]
                            ).'<br />';

                            break;
                        }
                    }
                }
            }
        }
        if (!$boolFlag) {
            $DB->Rollback();
        } else {
            $DB->Commit();
        }
    }
    // reverse array
    if ($boolFlag) {
        foreach ($arNewIBlocksList as $intIBlockID => $arIBlockInfo) {
            $arCurrentIBlocks[$intIBlockID]['CATALOG'] = $arIBlockInfo['CATALOG'];
            $arCurrentIBlocks[$intIBlockID]['SUBSCRIPTION'] = $arIBlockInfo['SUBSCRIPTION'];
            $arCurrentIBlocks[$intIBlockID]['YANDEX_EXPORT'] = $arIBlockInfo['YANDEX_EXPORT'];
            $arCurrentIBlocks[$intIBlockID]['VAT_ID'] = $arIBlockInfo['VAT_ID'];
        }
        foreach ($arNewIBlocksList as $intIBlockID => $arIBlockInfo) {
            if (0 < $arIBlockInfo['OFFERS_IBLOCK_ID']) {
                $arCurrentIBlocks[$arIBlockInfo['OFFERS_IBLOCK_ID']]['CATALOG'] = 'Y';
                $arCurrentIBlocks[$arIBlockInfo['OFFERS_IBLOCK_ID']]['PRODUCT_IBLOCK_ID'] = $intIBlockID;
                $arCurrentIBlocks[$arIBlockInfo['OFFERS_IBLOCK_ID']]['SKU_PROPERTY_ID'] = $arIBlockInfo['OFFERS_PROP'];
            }
        }
    }
    // check old offers
    if ($boolFlag) {
        foreach ($arCurrentIBlocks as $intIBlockID => $arIBlockInfo) {
            if (0 < $arIBlockInfo['PRODUCT_IBLOCK_ID']) {
                if ($intIBlockID !== $arNewIBlocksList[$arIBlockInfo['PRODUCT_IBLOCK_ID']]['OFFERS_IBLOCK_ID']) {
                    $arCurrentIBlocks[$intIBlockID]['UNLINK'] = 'Y';
                }
            }
        }
    }
    // go exist iblock
    $boolCatalogUpdate = false;
    if ($boolFlag) {
        $DB->StartTransaction();
        $obCatalog = new CCatalog();
        foreach ($arCurrentIBlocks as $intIBlockID => $arIBlockInfo) {
            $boolAttr = true;
            if (isset($arIBlockInfo['UNLINK']) && 'Y' === $arIBlockInfo['UNLINK']) {
                $boolFlag = $obCatalog->UnLinkSKUIBlock($arIBlockInfo['PRODUCT_IBLOCK_ID']);
                if ($boolFlag) {
                    $arIBlockInfo['PRODUCT_IBLOCK_ID'] = 0;
                    $arIBlockInfo['SKU_PROPERTY_ID'] = 0;
                    $boolCatalogUpdate = true;
                } else {
                    $boolFlag = false;
                    $ex = $APPLICATION->GetException();
                    $strError = $ex->GetString();
                    $strWarning .= Loc::getMessage(
                        'CAT_IBLOCK_OFFERS_ERR_UNLINK_SKU',
                        [
                            '#PRODUCT#' => $arIBlockInfo['INFO'],
                            '#ERROR#' => $strError,
                        ]
                    ).'<br />';
                }
            }
            if ($boolFlag) {
                $boolExists = isset($arCatalogList[$intIBlockID]);
                $arCurValues = ($boolExists ? $arCatalogList[$intIBlockID] : []);

                if ($boolExists && ('Y' === $arIBlockInfo['CATALOG'] || 'Y' === $arIBlockInfo['SUBSCRIPTION'] || 0 < $arIBlockInfo['PRODUCT_IBLOCK_ID'])) {
                    $boolAttr = $obCatalog->Update(
                        $intIBlockID,
                        [
                            'IBLOCK_ID' => $arIBlockInfo['ID'],
                            'YANDEX_EXPORT' => $arIBlockInfo['YANDEX_EXPORT'],
                            'SUBSCRIPTION' => $arIBlockInfo['SUBSCRIPTION'],
                            'VAT_ID' => $arIBlockInfo['VAT_ID'],
                            'PRODUCT_IBLOCK_ID' => $arIBlockInfo['PRODUCT_IBLOCK_ID'],
                            'SKU_PROPERTY_ID' => $arIBlockInfo['SKU_PROPERTY_ID'],
                        ]
                    );
                    if (!$boolAttr) {
                        $ex = $APPLICATION->GetException();
                        $strError = $ex->GetString();
                        $strWarning .= Loc::getMessage(
                            'CAT_IBLOCK_OFFERS_ERR_CAT_UPDATE',
                            [
                                '#PRODUCT#' => $arIBlockInfo['INFO'],
                                '#ERROR#' => $strError,
                            ]
                        ).'<br />';
                        $boolFlag = false;
                    } else {
                        if (
                            $arCurValues['SUBSCRIPTION'] !== $arIBlockInfo['SUBSCRIPTION']
                            || $arCurValues['PRODUCT_IBLOCK_ID'] !== $arIBlockInfo['PRODUCT_IBLOCK_ID']
                            || $arCurValues['YANDEX_EXPORT'] !== $arIBlockInfo['YANDEX_EXPORT']
                            || $arCurValues['VAT_ID'] !== $arIBlockInfo['VAT_ID']
                        ) {
                            $boolCatalogUpdate = true;
                        }
                        if ('Y' === $arIBlockInfo['YANDEX_EXPORT']) {
                            $bNeedAgent = true;
                        }
                    }
                } elseif ($boolExists && 'Y' !== $arIBlockInfo['CATALOG'] && 'Y' !== $arIBlockInfo['SUBSCRIPTION'] && 0 === $arIBlockInfo['PRODUCT_IBLOCK_ID']) {
                    if (!CCatalog::Delete($arIBlockInfo['ID'])) {
                        $boolFlag = false;
                        $strWarning .= Loc::getMessage('CAT_DEL_CATALOG1').' '.$arIBlockInfo['INFO'].' '.Loc::getMessage('CAT_DEL_CATALOG2').'.<br />';
                    } else {
                        $boolCatalogUpdate = true;
                    }
                } elseif ('Y' === $arIBlockInfo['CATALOG'] || 'Y' === $arIBlockInfo['SUBSCRIPTION'] || 0 < $arIBlockInfo['PRODUCT_IBLOCK_ID']) {
                    $boolAttr = $obCatalog->Add([
                        'IBLOCK_ID' => $arIBlockInfo['ID'],
                        'YANDEX_EXPORT' => $arIBlockInfo['YANDEX_EXPORT'],
                        'SUBSCRIPTION' => $arIBlockInfo['SUBSCRIPTION'],
                        'VAT_ID' => $arIBlockInfo['VAT_ID'],
                        'PRODUCT_IBLOCK_ID' => $arIBlockInfo['PRODUCT_IBLOCK_ID'],
                        'SKU_PROPERTY_ID' => $arIBlockInfo['SKU_PROPERTY_ID'],
                    ]);
                    if (!$boolAttr) {
                        $ex = $APPLICATION->GetException();
                        $strError = $ex->GetString();
                        $strWarning .= str_replace(
                            ['#PRODUCT#', '#ERROR#'],
                            [$arIBlockInfo['INFO'], $strError],
                            Loc::getMessage('CAT_IBLOCK_OFFERS_ERR_CAT_ADD')
                        ).'<br />';
                        $strWarning .= Loc::getMessage(
                            'CAT_IBLOCK_OFFERS_ERR_CAT_ADD',
                            [
                                '#PRODUCT#' => $arIBlockInfo['INFO'],
                                '#ERROR#' => $strError,
                            ]
                        ).'<br />';
                        $boolFlag = false;
                    } else {
                        if ('Y' === $arIBlockInfo['YANDEX_EXPORT']) {
                            $bNeedAgent = true;
                        }
                        $boolCatalogUpdate = true;
                    }
                }
            }
            if (!$boolFlag) {
                break;
            }
        }
        if (!$boolFlag) {
            $DB->Rollback();
        } else {
            $DB->Commit();
        }
    }
    if ($boolFlag) {
        if (!empty($arNewOffers)) {
            $DB->StartTransaction();
            foreach ($arNewOffers as $IntIBlockID => $arIBlockInfo) {
                $boolAttr = $obCatalog->Add(['IBLOCK_ID' => $arIBlockInfo['ID'], 'YANDEX_EXPORT' => $arIBlockInfo['YANDEX_EXPORT'], 'SUBSCRIPTION' => $arIBlockInfo['SUBSCRIPTION'], 'VAT_ID' => $arIBlockInfo['VAT_ID'], 'PRODUCT_IBLOCK_ID' => $arIBlockInfo['PRODUCT_IBLOCK_ID'], 'SKU_PROPERTY_ID' => $arIBlockInfo['SKU_PROPERTY_ID']]);
                if (!$boolAttr) {
                    $ex = $APPLICATION->GetException();
                    $strError = $ex->GetString();
                    $strWarning .= Loc::getMessage(
                        'CAT_IBLOCK_OFFERS_ERR_CAT_ADD',
                        [
                            '#PRODUCT#' => $arIBlockInfo['INFO'],
                            '#ERROR#' => $strError,
                        ]
                    ).'<br />';
                    $boolFlag = false;

                    break;
                }

                if ('Y' === $arIBlockInfo['YANDEX_EXPORT']) {
                    $bNeedAgent = true;
                }
                $boolCatalogUpdate = true;
            }
            if (!$boolFlag) {
                $DB->Rollback();
            } else {
                $DB->Commit();
            }
        }
    }

    if ($boolFlag && $boolCatalogUpdate) {
        $strOK .= Loc::getMessage('CAT_IBLOCK_CATALOG_SUCCESSFULLY_UPDATE').'<br />';
    }

    CAgent::RemoveAgent('CCatalog::PreGenerateXML("yandex");', 'catalog');
    if ($bNeedAgent) {
        CAgent::AddAgent('CCatalog::PreGenerateXML("yandex");', 'catalog', 'N', (int) Option::get('catalog', 'yandex_xml_period') * 3_600);
    }

    if (isset($_POST['catalog_subscribe_repeated_notify'])) {
        $postValue = (string) $_POST['catalog_subscribe_repeated_notify'];
        if ('Y' === $postValue || 'N' === $postValue) {
            Option::set('catalog', 'subscribe_repeated_notify', $postValue);
        }
    }
}

if ('POST' === $_SERVER['REQUEST_METHOD'] && !empty($_POST['agent_start']) && !$bReadOnly && check_bitrix_sessid()) {
    CAgent::RemoveAgent('CCatalog::PreGenerateXML("yandex");', 'catalog');
    $intCount = (int) CCatalog::GetList([], ['YANDEX_EXPORT' => 'Y'], []);
    if ($intCount > 0) {
        CAgent::AddAgent('CCatalog::PreGenerateXML("yandex");', 'catalog', 'N', (int) Option::get('catalog', 'yandex_xml_period') * 3_600);
        $strOK .= Loc::getMessage('CAT_AGENT_ADD_SUCCESS').'. ';
    } else {
        $strWarning .= Loc::getMessage('CAT_AGENT_ADD_NO_EXPORT').'. ';
    }
}

if (!empty($strWarning)) {
    CAdminMessage::ShowMessage($strWarning);
}

if (!empty($strOK)) {
    CAdminMessage::ShowNote($strOK);
}

$aTabs = [
    ['DIV' => 'edit5', 'TAB' => Loc::getMessage('CO_TAB_5'), 'ICON' => 'catalog_settings', 'TITLE' => Loc::getMessage('CO_TAB_5_TITLE')],
    ['DIV' => 'edit1', 'TAB' => Loc::getMessage('CO_TAB_1'), 'ICON' => 'catalog_settings', 'TITLE' => Loc::getMessage('CO_TAB_1_TITLE')],
    ['DIV' => 'edit2', 'TAB' => Loc::getMessage('CO_TAB_2'), 'ICON' => 'catalog_settings', 'TITLE' => Loc::getMessage('CO_TAB_2_TITLE')],
];

if ($USER->IsAdmin()) {
    if (CBXFeatures::IsFeatureEnabled('SaleRecurring')) {
        $aTabs[] = ['DIV' => 'edit3', 'TAB' => Loc::getMessage('CO_TAB_3'), 'ICON' => 'catalog_settings', 'TITLE' => Loc::getMessage('CO_SALE_GROUPS')];
    }
    $aTabs[] = ['DIV' => 'edit4', 'TAB' => Loc::getMessage('CO_TAB_RIGHTS'), 'ICON' => 'catalog_settings', 'TITLE' => Loc::getMessage('CO_TAB_RIGHTS_TITLE')];
}

$tabControl = new CAdminTabControl('tabControl', $aTabs, true, true);

$currentSettings = [];
$currentSettings['discsave_apply'] = (string) Option::get('catalog', 'discsave_apply');
$currentSettings['get_discount_percent_from_base_price'] = (string) Option::get($saleIsInstalled ? 'sale' : 'catalog', 'get_discount_percent_from_base_price');
$currentSettings['save_product_with_empty_price_range'] = (string) Option::get('catalog', 'save_product_with_empty_price_range');
$currentSettings['default_product_vat_included'] = (string) Option::get('catalog', 'default_product_vat_included');

$strShowCatalogTab = Option::get('catalog', 'show_catalog_tab_with_offers');
$strSaveProductWithoutPrice = Option::get('catalog', 'save_product_without_price');

$strQuantityTrace = Option::get('catalog', 'default_quantity_trace');
$strAllowCanBuyZero = Option::get('catalog', 'default_can_buy_zero');
$strSubscribe = Option::get('catalog', 'default_subscribe');

$strEnableReservation = Option::get('catalog', 'enable_reservation');
$strUseStoreControl = Option::get('catalog', 'default_use_store_control');

$strShowOffersIBlock = Option::get('catalog', 'product_form_show_offers_iblock');
$strSimpleSearch = Option::get('catalog', 'product_form_simple_search');
$searchShowOfferName = Option::get('catalog', 'product_form_show_offer_name');

$tabControl->Begin();
?>
<script type="text/javascript">
function showReservation(show)
{
	var obRowReservationPeriod = BX('tr_reservation_period'),
		obReservationType = BX('td_reservation_type'),
		titleQuantityDecrease = '<?php echo CUtil::JSEscape(Loc::getMessage('CAT_PRODUCT_QUANTITY_DECREASE')); ?>',
		titleProductReserved = '<?php echo CUtil::JSEscape(Loc::getMessage('CAT_PRODUCT_RESERVED')); ?>';

	show = !!show;
	if (!!obRowReservationPeriod)
		BX.style(obRowReservationPeriod, 'display', (show ? 'table-row' : 'none'));
	obRowReservationPeriod = null;
	if (!!obReservationType)
		obReservationType.innerHTML = (show ? titleProductReserved : titleQuantityDecrease);
	obReservationType = null;
}

function onClickReservation(el)
{
	showReservation(el.checked);
}

function onClickStoreControl(el)
{
	var obEnableReservation = BX('enable_reservation_y'),
		oldValue = '';

	if (!obEnableReservation)
	{
		return;
	}

	if (el.checked)
	{
		obEnableReservation.checked = true;
	}
	else
	{
		if (obEnableReservation.hasAttribute('data-oldvalue'))
		{
			oldValue = obEnableReservation.getAttribute('data-oldvalue');
			obEnableReservation.checked = (oldValue === 'Y');
		}
	}
	showReservation(obEnableReservation.checked);
	obEnableReservation.disabled = el.checked;
}

function RestoreDefaults()
{
	if (confirm('<?echo CUtil::JSEscape(Loc::getMessage('CAT_OPTIONS_BTN_HINT_RESTORE_DEFAULT_WARNING')); ?>'))
		window.location = "<?php echo $APPLICATION->GetCurPage(); ?>?RestoreDefaults=Y&lang=<?php echo LANGUAGE_ID; ?>&mid=<?php echo urlencode($mid); ?>&<?php echo bitrix_sessid_get(); ?>";
}
</script>
<form method="POST" action="<?echo $APPLICATION->GetCurPage(); ?>?lang=<?php echo LANGUAGE_ID; ?>&mid=<?php echo htmlspecialcharsbx($mid); ?>&mid_menu=1" name="ara">
<?echo bitrix_sessid_post(); ?><?php
$tabControl->BeginNextTab();
?>
<tr class="heading">
	<td colspan="2"><?php echo Loc::getMessage('CAT_PRODUCT_CARD'); ?></td>
</tr>
<tr>
	<td width="40%"><label for="save_product_without_price_y"><?php echo Loc::getMessage('CAT_SAVE_PRODUCTS_WITHOUT_PRICE'); ?></label></td>
	<td width="60%">
		<input type="hidden" name="save_product_without_price" id="save_product_without_price_n" value="N">
		<input type="checkbox" name="save_product_without_price" id="save_product_without_price_y" value="Y"<?if ('Y' === $strSaveProductWithoutPrice) {
		    echo ' checked';
		}?>>
	</td>
</tr>
<tr>
	<td width="40%"><label for="save_product_with_empty_price_range_y"><?php echo Loc::getMessage('CAT_SAVE_PRODUCT_WITH_EMPTY_PRICE_RANGE'); ?></label></td>
	<td width="60%">
		<input type="hidden" name="save_product_with_empty_price_range" id="save_product_with_empty_price_range_n" value="N">
		<input type="checkbox" name="save_product_with_empty_price_range" id="save_product_with_empty_price_range_y" value="Y"<?if ('Y' === $currentSettings['save_product_with_empty_price_range']) {
		    echo ' checked';
		}?>>
	</td>
</tr>
<tr>
	<td width="40%"><label for="show_catalog_tab_with_offers"><?php echo Loc::getMessage('CAT_SHOW_CATALOG_TAB'); ?></label></td>
	<td width="60%">
		<input type="hidden" name="show_catalog_tab_with_offers" id="show_catalog_tab_with_offers_n" value="N">
		<input type="checkbox" name="show_catalog_tab_with_offers" id="show_catalog_tab_with_offers_y" value="Y"<?if ('Y' === $strShowCatalogTab) {
		    echo ' checked';
		}?>>
	</td>
</tr>
<tr>
	<td width="40%"><label for="default_product_vat_included"><?php echo Loc::getMessage('CAT_PRODUCT_DEFAULT_VAT_INCLUDED'); ?></label></td>
	<td width="60%">
		<input type="hidden" name="default_product_vat_included" id="default_product_vat_included_n" value="N">
		<input type="checkbox" name="default_product_vat_included" id="default_product_vat_included_y" value="Y"<?if ('Y' === $currentSettings['default_product_vat_included']) {
		    echo ' checked';
		}?>>
	</td>
</tr>
<tr class="heading">
	<td colspan="2"><?php echo Loc::getMessage('CAT_PRODUCT_CARD_DEFAULT_VALUES'); ?></td>
</tr>
<tr>
	<td width="40%"><?php echo Loc::getMessage('CAT_ENABLE_QUANTITY_TRACE'); ?></td>
	<td width="60%">
		<span id="default_quantity_trace"><?php echo 'Y' === $strQuantityTrace ? Loc::getMessage('CAT_PRODUCT_SETTINGS_STATUS_YES') : Loc::getMessage('CAT_PRODUCT_SETTINGS_STATUS_NO'); ?></span>
	</td>
</tr>
<tr>
	<td width="40%"><?php echo Loc::getMessage('CAT_ALLOW_CAN_BUY_ZERO_EXT'); ?></td>
	<td width="60%">
		<span id="default_can_buy_zero"><?php echo 'Y' === $strAllowCanBuyZero ? Loc::getMessage('CAT_PRODUCT_SETTINGS_STATUS_YES') : Loc::getMessage('CAT_PRODUCT_SETTINGS_STATUS_NO'); ?></span>
	</td>
</tr>
<tr>
	<td width="40%"><?php echo Loc::getMessage('CAT_PRODUCT_SUBSCRIBE'); ?></td>
	<td width="60%">
		<span id="default_subscribe"><?php echo 'Y' === $strSubscribe ? Loc::getMessage('CAT_PRODUCT_SETTINGS_STATUS_YES') : Loc::getMessage('CAT_PRODUCT_SETTINGS_STATUS_NO'); ?></span>
	</td>
</tr>
<?php
if (!$readOnly) {
    ?>
<tr>
	<td width="40%">&nbsp;</td>
	<td width="60%">
		<input class="adm-btn-save" type="button" id="product_settings" value="<?php echo Loc::getMessage('CAT_PRODUCT_SETTINGS_CHANGE'); ?>">
	</td>
</tr>
<?php
}
?>
<tr class="heading">
	<td colspan="2" valign="top" align="center"><?php echo Loc::getMessage('CAT_STORE'); ?></td>
</tr>
<tr id='cat_store_tr'>
	<td width="40%"><label for="use_store_control_y"><?php echo Loc::getMessage('CAT_USE_STORE_CONTROL'); ?></label></td>
	<td width="60%">
		<input type="hidden" name="use_store_control" id="use_store_control_n" value="N">
		<input type="checkbox" onclick="onClickStoreControl(this);" name="use_store_control" id="use_store_control_y" value="Y"<?if ('Y' === $strUseStoreControl) {
		    echo ' checked';
		}?>>
	</td>
</tr>
<tr>
	<td width="40%">
		<span id="hint_reservation"></span>&nbsp;<label for="enable_reservation"><?php echo Loc::getMessage('CAT_ENABLE_RESERVATION'); ?></label></td>
	<td width="60%">
		<input type="hidden" name="enable_reservation" id="enable_reservation_n" value="N">
		<input type="checkbox" onclick="onClickReservation(this);" name="enable_reservation" id="enable_reservation_y" value="Y" data-oldvalue="<?php echo $strEnableReservation; ?>" <?if ('Y' === $strEnableReservation || 'Y' === $strUseStoreControl) {
		    echo ' checked';
		}?> <?if ('Y' === $strUseStoreControl) {
		    echo ' disabled';
		}?>>
	</td>
</tr>
<?php
if ($saleIsInstalled && Loader::includeModule('sale')) {
    ?>
	<tr>
		<td id="td_reservation_type"><?php
            echo Loc::getMessage('Y' === $strUseStoreControl || 'Y' === $strEnableReservation ? 'CAT_PRODUCT_RESERVED' : 'CAT_PRODUCT_QUANTITY_DECREASE');
    ?></td>
		<td>
			<?php
        $currentReserveCondition = Sale\Configuration::getProductReservationCondition();
    $reserveConditions = Sale\Configuration::getReservationConditionList(true);
    if (isset($reserveConditions[$currentReserveCondition])) {
        echo htmlspecialcharsex($reserveConditions[$currentReserveCondition]);
    } else {
        echo Loc::getMessage('BX_CAT_RESERVE_CONDITION_EMPTY');
    }
    unset($reserveConditions, $currentReserveCondition);
    ?>&nbsp;<a href="<?php echo $saleSettingsUrl; ?>#section_reservation"><?php echo Loc::getMessage('CAT_DISCOUNT_PERCENT_FROM_BASE_SALE'); ?></a>
		</td>
	</tr>
	<tr id="tr_reservation_period" style="display: <?php echo 'Y' === $strUseStoreControl || 'Y' === $strEnableReservation ? 'table-row' : 'none'; ?>;">
		<td>
			<?echo Loc::getMessage('CAT_RESERVATION_CLEAR_PERIOD'); ?>
		</td>
		<td>
			<?php echo Sale\Configuration::getProductReserveClearPeriod(); ?>
		</td>
	</tr>
	<?php
}
if (!$useSaleDiscountOnly) {
    if (CBXFeatures::IsFeatureEnabled('CatDiscountSave')) {
        ?>
<tr class="heading">
	<td colspan="2"><?php echo Loc::getMessage('CAT_DISCOUNT'); ?></td>
</tr>
<tr>
	<td width="40%"><label for="discsave_apply"><?php echo Loc::getMessage('CAT_DISCSAVE_APPLY'); ?></label></td>
	<td width="60%">
		<select name="discsave_apply" id="discsave_apply"><?php
            foreach ($applyDiscSaveModeList as $applyMode => $applyTitle) {
                ?><option value="<?php echo $applyMode; ?>" <?php echo $applyMode === $currentSettings['discsave_apply'] ? 'selected' : ''; ?>><?php echo $applyTitle; ?></option><?php
            }
        ?>
		</select>
	</td>
</tr>
<?php
    }
    ?>
<tr>
	<td width="40%"><?php echo Loc::getMessage('CAT_DISCOUNT_PERCENT_FROM_BASE_PRICE'); ?></td>
	<td width="60%"><?php
        if ($saleIsInstalled) {
            echo
                'Y' === $currentSettings['get_discount_percent_from_base_price']
                ? Loc::getMessage('CAT_DISCOUNT_PERCENT_FROM_BASE_PRICE_YES')
                : Loc::getMessage('CAT_DISCOUNT_PERCENT_FROM_BASE_PRICE_NO'); ?>&nbsp;<a href="<?php echo $saleSettingsUrl; ?>#section_discount"><?php echo Loc::getMessage('CAT_DISCOUNT_PERCENT_FROM_BASE_SALE'); ?></a><?php
        } else {
            ?>
		<input type="hidden" name="get_discount_percent_from_base_price" id="get_discount_percent_from_base_price_N" value="N">
		<input type="checkbox" name="get_discount_percent_from_base_price" id="get_discount_percent_from_base_price_Y" value="Y"<?php echo 'Y' === $currentSettings['get_discount_percent_from_base_price'] ? ' checked' : ''; ?>>
		<?php
        }
    ?></td>

</tr>
<?php
/*
$strDiscountVat = Option::get('catalog', 'discount_vat');
?>
<tr>
    <td width="40%"><label for="discount_vat_y"><? echo Loc::getMessage("CAT_DISCOUNT_VAT"); ?></label></td>
    <td width="60%"><input type="hidden" name="discount_vat" id="discount_vat_n" value="N"><input type="checkbox" name="discount_vat" id="discount_vat_y" value="Y"<?if ('Y' == $strDiscountVat) echo " checked";?>></td>
</tr>
<?
*/
}
$viewedTime = (int) Option::get('catalog', 'viewed_time');
$viewedCount = (int) Option::get('catalog', 'viewed_count');
$viewedPeriod = (int) Option::get('catalog', 'viewed_period');
?>
<tr class="heading">
	<td colspan="2"><?php echo Loc::getMessage('CAT_VIEWED_PRODUCTS_TITLE'); ?></td>
</tr>
<tr>
	<td width="40%"><label for="viewed_time"><?php echo Loc::getMessage('CAT_VIEWED_TIME'); ?></label></td>
	<td width="60%">
		<input type="text" name="viewed_time" id="viewed_time" value="<?php echo $viewedTime; ?>" size="10">
	</td>
</tr>
<tr>
	<td width="40%"><label for="viewed_count"><?php echo Loc::getMessage('CAT_VIEWED_COUNT'); ?></label></td>
	<td width="60%">
		<input type="text" name="viewed_count" id="viewed_count" value="<?php echo $viewedCount; ?>" size="10">
	</td>
</tr>
<tr>
	<td width="40%"><label for="viewed_period"><?php echo Loc::getMessage('CAT_VIEWED_PERIOD'); ?></label></td>
	<td width="60%">
		<input type="text" name="viewed_period" id="viewed_period" value="<?php echo $viewedPeriod; ?>" size="10">
	</td>
</tr>
<tr class="heading">
	<td colspan="2"><?php echo Loc::getMessage('CAT_PRODUCT_FORM_SETTINGS'); ?></td>
</tr>
<tr>
	<td width="40%"><?php echo Loc::getMessage('CAT_SHOW_OFFERS_IBLOCK'); ?></td>
	<td width="60%">
		<input type="hidden" name="product_form_show_offers_iblock" id="product_form_show_offers_iblock_n" value="N">
		<input type="checkbox" name="product_form_show_offers_iblock" id="product_form_show_offers_iblock_y" value="Y" <?if ('Y' === $strShowOffersIBlock) {
		    echo ' checked';
		}?>>
	</td>
</tr>
<tr>
	<td width="40%"><?php echo Loc::getMessage('CAT_SIMPLE_SEARCH'); ?></td>
	<td width="60%">
		<input type="hidden" name="product_form_simple_search" id="product_form_simple_search_n" value="N">
		<input type="checkbox" name="product_form_simple_search" id="product_form_simple_search_y" value="Y" <?if ('Y' === $strSimpleSearch) {
		    echo ' checked';
		}?>>
	</td>
</tr>
<tr>
	<td width="40%"><?php echo Loc::getMessage('CAT_SHOW_OFFERS_NAME'); ?></td>
	<td width="60%">
		<input type="hidden" name="product_form_show_offer_name" id="product_form_show_offer_name_n" value="N">
		<input type="checkbox" name="product_form_show_offer_name" id="product_form_show_offer_name_y" value="Y" <?if ('Y' === $searchShowOfferName) {
		    echo ' checked';
		}?>>
	</td>
</tr>
<tr class="heading">
	<td colspan="2"><?php echo Loc::getMessage('CAT_PRODUCT_SUBSCRIBE_TITLE'); ?></td>
</tr>
<tr>
	<td width="40%"><?php echo Loc::getMessage('CAT_PRODUCT_SUBSCRIBE_LABLE_REPEATED_NOTIFY'); ?></td>
	<td width="60%">
		<input type="hidden" name="catalog_subscribe_repeated_notify" value="N">
		<input type="checkbox" name="catalog_subscribe_repeated_notify" value="Y"
			<?if ('Y' === Option::get('catalog', 'subscribe_repeated_notify')) {
			    echo ' checked';
			}?>>
	</td>
</tr>
<?php
    $tabControl->BeginNextTab();
?>
<tr class="heading">
	<td colspan="2"><?php echo Loc::getMessage('CAT_COMMON_EXPIMP_SETTINGS'); ?></td>
</tr><?php
for ($i = 0, $intCount = count($arAllOptions); $i < $intCount; ++$i) {
    $Option = $arAllOptions[$i];
    $val = Option::get('catalog', $Option[0], $Option[2]);
    $type = $Option[3];
    ?>
	<tr>
		<td width="40%"><?php echo 'checkbox' === $type[0] ? '<label for="'.htmlspecialcharsbx($Option[0]).'">'.$Option[1].'</label>' : $Option[1]; ?></td>
		<td width="60%">
			<?php
            if ('export_default_path' === $Option[0]) {
                CAdminFileDialog::ShowScript(
                    [
                        'event' => 'BtnClickExpPath',
                        'arResultDest' => ['FORM_NAME' => 'ara', 'FORM_ELEMENT_NAME' => $Option[0]],
                        'arPath' => ['PATH' => GetDirPath($val)],
                        'select' => 'D', // F - file only, D - folder only
                        'operation' => 'O', // O - open, S - save
                        'showUploadTab' => false,
                        'showAddToMenuTab' => false,
                        'fileFilter' => '',
                        'allowAllFiles' => true,
                        'SaveConfig' => true,
                    ]
                );
                ?><input type="text" name="<?php echo htmlspecialcharsbx($Option[0]); ?>" size="50" maxlength="255" value="<?echo htmlspecialcharsbx($val); ?>">&nbsp;<input type="button" name="browseExpPath" value="..." onClick="BtnClickExpPath()"><?php
            } else {
                if ('checkbox' === $type[0]) { ?>
					<input type="checkbox" name="<?echo htmlspecialcharsbx($Option[0]); ?>" id="<?echo htmlspecialcharsbx($Option[0]); ?>" value="Y"<?if ('Y' === $val) {
					    echo ' checked';
					}?>>
				<?} elseif ('text' === $type[0]) { ?>
					<input type="text" size="<?echo $type[1]; ?>" maxlength="255" value="<?echo htmlspecialcharsbx($val); ?>" name="<?echo htmlspecialcharsbx($Option[0]); ?>">
				<?} elseif ('textarea' === $type[0]) { ?>
					<textarea rows="<?echo $type[1]; ?>" cols="<?echo $type[2]; ?>" name="<?echo htmlspecialcharsbx($Option[0]); ?>"><?echo htmlspecialcharsbx($val); ?></textarea>
				<?}
				}
    ?>
		</td>
	</tr>
<?php
}
?>
<tr>
	<td width="40%"><?php echo Loc::getMessage('CAT_DEF_OUTFILE'); ?></td>
	<td width="60%">
		<?$default_outfile_action = Option::get('catalog', 'default_outfile_action'); ?>
		<select name="default_outfile_action">
			<option value="D" <?if ('D' === $default_outfile_action || '' === $default_outfile_action) {
			    echo 'selected';
			} ?>><?echo Loc::getMessage('CAT_DEF_OUTFILE_D'); ?></option>
			<option value="H" <?if ('H' === $default_outfile_action) {
			    echo 'selected';
			} ?>><?php echo Loc::getMessage('CAT_DEF_OUTFILE_H'); ?></option>
			<option value="F" <?if ('F' === $default_outfile_action) {
			    echo 'selected';
			} ?>><?php echo Loc::getMessage('CAT_DEF_OUTFILE_F'); ?></option>
		</select>
	</td>
</tr>
<tr>
	<td width="40%">
	<?php
    $yandex_agent_file = Option::get('catalog', 'yandex_agent_file');
CAdminFileDialog::ShowScript(
    [
        'event' => 'BtnClick',
        'arResultDest' => ['FORM_NAME' => 'ara', 'FORM_ELEMENT_NAME' => 'yandex_agent_file'],
        'arPath' => ['PATH' => GetDirPath($yandex_agent_file)],
        'select' => 'F', // F - file only, D - folder only
        'operation' => 'O', // O - open, S - save
        'showUploadTab' => true,
        'showAddToMenuTab' => false,
        'fileFilter' => 'php',
        'allowAllFiles' => true,
        'SaveConfig' => true,
    ]
);
?>
	<?echo Loc::getMessage('CAT_AGENT_FILE'); ?></td>
	<td width="60%"><input type="text" name="yandex_agent_file" size="50" maxlength="255" value="<?echo $yandex_agent_file; ?>">&nbsp;<input type="button" name="browse" value="..." onClick="BtnClick()"></td>
</tr>
<tr class="heading">
	<td colspan="2"><?echo Loc::getMessage('CO_PAR_IE_CSV'); ?></td>
</tr>
<tr>
	<td width="40%" valign="top"><?echo Loc::getMessage('CO_PAR_DPP_CSV'); ?></td>
	<td width="60%" valign="top">
<?php
$arVal = [];
$strVal = (string) Option::get('catalog', 'allowed_product_fields');
if ('' !== $strVal) {
    $arVal = array_fill_keys(explode(',', $strVal), true);
}
$productFields = array_merge(
    CCatalogCSVSettings::getSettingsFields(CCatalogCSVSettings::FIELDS_ELEMENT),
    CCatalogCSVSettings::getSettingsFields(CCatalogCSVSettings::FIELDS_CATALOG)
);
?><select name="allowed_product_fields[]" multiple size="8"><?php
foreach ($productFields as &$oneField) {
    ?><option value="<?php echo htmlspecialcharsbx($oneField['value']); ?>"<?php echo isset($arVal[$oneField['value']]) ? ' selected' : ''; ?>><?php echo htmlspecialcharsex($oneField['name']); ?></option><?php
}
if (isset($oneField)) {
    unset($oneField, $productFields);
}

?></select>
	</td>
</tr>
<tr>
	<td width="40%" valign="top"><?php echo Loc::getMessage('CO_AVAIL_PRICE_FIELDS'); ?></td>
	<td width="60%" valign="top">
<?php
$arVal = [];
$strVal = (string) Option::get('catalog', 'allowed_price_fields');
if ('' !== $strVal) {
    $arVal = array_fill_keys(explode(',', $strVal), true);
}
$priceFields = CCatalogCSVSettings::getSettingsFields(CCatalogCSVSettings::FIELDS_PRICE);
?><select name="allowed_price_fields[]" multiple size="3"><?php
foreach ($priceFields as &$oneField) {
    ?><option value="<?php echo htmlspecialcharsbx($oneField['value']); ?>"<?php echo isset($arVal[$oneField['value']]) ? ' selected' : ''; ?>><?php echo htmlspecialcharsex($oneField['name']); ?></option><?php
}
if (isset($oneField)) {
    unset($oneField, $priceFields);
}

?></select>
	</td>
</tr>
<tr>
	<td width="40%"><?echo Loc::getMessage('CAT_NUM_CATALOG_LEVELS'); ?></td>
	<td width="60%"><?php
        $strVal = (int) Option::get('catalog', 'num_catalog_levels');
?><input type="text" size="5" maxlength="5" value="<?php echo $strVal; ?>" name="num_catalog_levels">
	</td>
</tr>
<tr>
	<td width="40%" valign="top"><?echo Loc::getMessage('CO_PAR_DPG_CSV'); ?></td>
	<td width="60%">
<?php
$arVal = [];
$strVal = (string) Option::get('catalog', 'allowed_group_fields');
if ('' !== $strVal) {
    $arVal = array_fill_keys(explode(',', $strVal), true);
}
$sectionFields = CCatalogCSVSettings::getSettingsFields(CCatalogCSVSettings::FIELDS_SECTION);
?><select name="allowed_group_fields[]" multiple size="9"><?php
foreach ($sectionFields as &$oneField) {
    ?><option value="<?php echo htmlspecialcharsbx($oneField['value']); ?>"<?php echo isset($arVal[$oneField['value']]) ? ' selected' : ''; ?>><?php echo htmlspecialcharsex($oneField['name']); ?></option><?php
}
if (isset($oneField)) {
    unset($oneField, $sectionFields);
}

?></select>
	</td>
</tr>
<tr>
	<td width="40%" valign="top"><?echo Loc::getMessage('CO_PAR_DV1_CSV'); ?></td>
	<td width="60%" valign="top">
<?php
$arVal = [];
$strVal = (string) Option::get('catalog', 'allowed_currencies');
if ('' !== $strVal) {
    $arVal = array_fill_keys(explode(',', $strVal), true);
}
?><select name="allowed_currencies[]" multiple size="5"><?php
$currencyIterator = Currency\CurrencyTable::getList([
    'select' => ['CURRENCY', 'FULL_NAME' => 'RT_LANG.FULL_NAME', 'SORT'],
    'order' => ['SORT' => 'ASC', 'CURRENCY' => 'ASC'],
    'runtime' => [
        'RT_LANG' => [
            'data_type' => 'Bitrix\Currency\CurrencyLang',
            'reference' => [
                '=this.CURRENCY' => 'ref.CURRENCY',
                '=ref.LID' => new Main\DB\SqlExpression('?', LANGUAGE_ID),
            ],
        ],
    ],
]);
while ($currency = $currencyIterator->fetch()) {
    $currency['FULL_NAME'] = (string) $currency['FULL_NAME'];
    ?><option value="<?php echo $currency['CURRENCY']; ?>"<?php echo isset($arVal[$currency['CURRENCY']]) ? ' selected' : ''; ?>><?php
    echo $currency['CURRENCY'];
    if ('' !== $currency['FULL_NAME']) {
        echo ' ('.htmlspecialcharsex($currency['FULL_NAME']).')';
    } ?></option><?php
}
unset($currency, $currencyIterator);
?></select>
	</td>
</tr>
<?php
$tabControl->BeginNextTab();
$arVATRef = CatalogGetVATArray([], true);

$arCatalogList = [];
$arIBlockSitesList = [];

$arIBlockFullInfo = [];

$arRecurring = [];
$arRecurringKey = [];

$rsIBlocks = CIBlock::GetList(['IBLOCK_TYPE' => 'ASC', 'ID' => 'ASC']);
while ($arIBlock = $rsIBlocks->Fetch()) {
    $arIBlock['ID'] = (int) $arIBlock['ID'];
    if (!isset($arIBlockSitesList[$arIBlock['ID']])) {
        $arLIDList = [];
        $arWithLinks = [];
        $arWithoutLinks = [];
        $rsIBlockSites = CIBlock::GetSite($arIBlock['ID']);
        while ($arIBlockSite = $rsIBlockSites->Fetch()) {
            $arLIDList[] = $arIBlockSite['LID'];
            $arWithLinks[] = '<a href="/bitrix/admin/site_edit.php?LID='.urlencode($arIBlockSite['LID']).'&lang='.LANGUAGE_ID.'" title="'.Loc::getMessage('CO_SITE_ALT').'">'.htmlspecialcharsbx($arIBlockSite['LID']).'</a>';
            $arWithoutLinks[] = htmlspecialcharsbx($arIBlockSite['LID']);
        }
        $arIBlockSitesList[$arIBlock['ID']] = [
            'SITE_ID' => $arLIDList,
            'WITH_LINKS' => implode('&nbsp;', $arWithLinks),
            'WITHOUT_LINKS' => implode(' ', $arWithoutLinks),
        ];
    }
    $arIBlockItem = [
        'ID' => $arIBlock['ID'],
        'IBLOCK_TYPE_ID' => $arIBlock['IBLOCK_TYPE_ID'],
        'SITE_ID' => $arIBlockSitesList[$arIBlock['ID']]['SITE_ID'],
        'NAME' => htmlspecialcharsbx($arIBlock['NAME']),
        'ACTIVE' => $arIBlock['ACTIVE'],
        'FULL_NAME' => '['.$arIBlock['IBLOCK_TYPE_ID'].'] '.htmlspecialcharsbx($arIBlock['NAME']).' ('.$arIBlockSitesList[$arIBlock['ID']]['WITHOUT_LINKS'].')',
        'IS_CATALOG' => 'N',
        'IS_CONTENT' => 'N',
        'YANDEX_EXPORT' => 'N',
        'VAT_ID' => 0,
        'PRODUCT_IBLOCK_ID' => 0,
        'SKU_PROPERTY_ID' => 0,
        'OFFERS_IBLOCK_ID' => 0,
        'IS_OFFERS' => 'N',
        'OFFERS_PROPERTY_ID' => 0,
    ];
    $arIBlockFullInfo[$arIBlock['ID']] = $arIBlockItem;
}

$catalogIterator = Catalog\CatalogIblockTable::getList([
    'select' => ['IBLOCK_ID', 'PRODUCT_IBLOCK_ID', 'SKU_PROPERTY_ID', 'SUBSCRIPTION', 'YANDEX_EXPORT', 'VAT_ID'],
]);
while ($arOneCatalog = $catalogIterator->fetch()) {
    $arOneCatalog['IBLOCK_ID'] = (int) $arOneCatalog['IBLOCK_ID'];
    $arOneCatalog['VAT_ID'] = (int) $arOneCatalog['VAT_ID'];
    $arOneCatalog['PRODUCT_IBLOCK_ID'] = (int) $arOneCatalog['PRODUCT_IBLOCK_ID'];
    $arOneCatalog['SKU_PROPERTY_ID'] = (int) $arOneCatalog['SKU_PROPERTY_ID'];

    if (!CBXFeatures::IsFeatureEnabled('SaleRecurring') && 'Y' === $arOneCatalog['SUBSCRIPTION']) {
        $arRecurring[] = '['.$arIBlockItem['ID'].'] '.$arIBlockItem['NAME'];
        $arRecurringKey[$arIBlockItem['ID']] = true;
    }

    $arIBlock = $arIBlockFullInfo[$arOneCatalog['IBLOCK_ID']];
    $arIBlock['IS_CATALOG'] = 'Y';
    $arIBlock['IS_CONTENT'] = (CBXFeatures::IsFeatureEnabled('SaleRecurring') ? $arOneCatalog['SUBSCRIPTION'] : 'N');
    $arIBlock['YANDEX_EXPORT'] = $arOneCatalog['YANDEX_EXPORT'];
    $arIBlock['VAT_ID'] = $arOneCatalog['VAT_ID'];
    $arIBlock['PRODUCT_IBLOCK_ID'] = $arOneCatalog['PRODUCT_IBLOCK_ID'];
    $arIBlock['SKU_PROPERTY_ID'] = $arOneCatalog['SKU_PROPERTY_ID'];
    if (0 < $arOneCatalog['PRODUCT_IBLOCK_ID']) {
        $arIBlock['IS_OFFERS'] = 'Y';
        $arOwnBlock = $arIBlockFullInfo[$arOneCatalog['PRODUCT_IBLOCK_ID']];
        $arOwnBlock['OFFERS_IBLOCK_ID'] = $arOneCatalog['IBLOCK_ID'];
        $arOwnBlock['OFFERS_PROPERTY_ID'] = $arOneCatalog['SKU_PROPERTY_ID'];
        $arIBlockFullInfo[$arOneCatalog['PRODUCT_IBLOCK_ID']] = $arOwnBlock;
        unset($arOwnBlock);
    }
    $arIBlockFullInfo[$arOneCatalog['IBLOCK_ID']] = $arIBlock;
    if ('Y' === $arIBlock['IS_CATALOG']) {
        $arCatalogList[$arOneCatalog['IBLOCK_ID']] = $arIBlock;
    }
    unset($arIBlock);
}
unset($arCatalog, $catalogIterator);

$arIBlockTypeIDList = [];
$arIBlockTypeNameList = [];
$rsIBlockTypes = CIBlockType::GetList(['sort' => 'asc'], ['ACTIVE' => 'Y']);
while ($arIBlockType = $rsIBlockTypes->Fetch()) {
    if ($ar = CIBlockType::GetByIDLang($arIBlockType['ID'], LANGUAGE_ID, true)) {
        $arIBlockTypeIDList[] = htmlspecialcharsbx($arIBlockType['ID']);
        $arIBlockTypeNameList[] = htmlspecialcharsbx('['.$arIBlockType['ID'].'] '.$ar['~NAME']);
    }
}

$arDoubleIBlockFullInfo = $arIBlockFullInfo;
?>
<tr><td><?php
if (!empty($arRecurring)) {
    $strRecurring = Loc::getMessage('SMALL_BUSINESS_RECURRING_ERR_LIST').'<ul><li>'.implode('</li><li>', $arRecurring).'</li></ul>'.Loc::getMessage('SMALL_BUSINESS_RECURRING_ERR_LIST_CLEAR');
    CAdminMessage::ShowMessage([
        'MESSAGE' => Loc::getMessage('SMALL_BUSINESS_RECURRING_ERR'),
        'DETAILS' => $strRecurring,
        'HTML' => true,
        'TYPE' => 'ERROR',
    ]);
}

/*// define('B_ADMIN_IBLOCK_CATALOGS', 1);
// define('B_ADMIN_IBLOCK_CATALOGS_LIST', false);
$readOnly = false;
require($_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/catalog/tools/iblock_catalog_list.php'); */

?>
<script type="text/javascript">
function ib_checkFldActivity(id, flag)
{
	var Cat = BX('IS_CATALOG_' + id + '_Y');
	var Cont = BX('IS_CONTENT_' + id + '_Y');
	var Yand = BX('YANDEX_EXPORT_' + id + '_Y');
	var Vat = BX('VAT_ID_' + id);

	if (flag == 0)
	{
		if (!!Cat && !!Cont)
		{
			if (!Cat.checked)
				Cont.checked = false;
		}
	}

	if (flag == 1)
	{
		if (!!Cat && !!Cont)
		{
			if (Cont.checked)
				Cat.checked = true;
		}
	}

	var bActive = Cat.checked;
	if (!!Yand)
		Yand.disabled = !bActive;
	if (!!Vat)
		Vat.disabled = !bActive;
}

function show_add_offers(id, obj)
{
	var value = obj.options[obj.selectedIndex].value;
	var add_form = document.getElementById('offers_add_info_'+id);
	if (undefined !== add_form)
	{
		if (<?php echo CATALOG_NEW_OFFERS_IBLOCK_NEED; ?> == value)
		{
			add_form.style.display = 'block';
		}
		else
		{
			add_form.style.display = 'none';
		}
	}
}
function change_offers_ibtype(obj,ID)
{
	var value = obj.value;
	if ('Y' == value)
	{
		document.forms.ara['OFFERS_TYPE_' + ID].disabled = true;
		document.forms.ara['OFFERS_NEWTYPE_' + ID].disabled = false;
	}
	else if ('N' == value)
	{
		document.forms.ara['OFFERS_TYPE_' + ID].disabled = false;
		document.forms.ara['OFFERS_NEWTYPE_' + ID].disabled = true;
	}
}
</script>
<table width="100%" cellspacing="0" cellpadding="0" border="0" class="internal">
	<tr class="heading">
		<td><?php echo Loc::getMessage('CAT_IBLOCK_SELECT_NAME'); ?></td>
		<td><?php echo Loc::getMessage('CAT_IBLOCK_SELECT_CAT'); ?></td>
		<td><?php echo Loc::getMessage('CAT_IBLOCK_SELECT_OFFERS'); ?></td><?php
        if (CBXFeatures::IsFeatureEnabled('SaleRecurring')) {
            ?><td><?php echo Loc::getMessage('CO_SALE_CONTENT'); ?></td><?php
        }
?><td><?php echo Loc::getMessage('CAT_IBLOCK_SELECT_YAND'); ?></td>
		<td><?php echo Loc::getMessage('CAT_IBLOCK_SELECT_VAT'); ?></td>
	</tr>
	<?php
    foreach ($arIBlockFullInfo as &$res) {
        ?>
		<tr>
			<td>[<a title="<?php echo Loc::getMessage('CO_IB_TYPE_ALT'); ?>" href="/bitrix/admin/iblock_admin.php?type=<?php echo urlencode($res['IBLOCK_TYPE_ID']); ?>&lang=<?php echo LANGUAGE_ID; ?>&admin=Y"><?php echo $res['IBLOCK_TYPE_ID']; ?></a>]
				&nbsp;[<?php echo $res['ID']; ?>] <a title="<?php echo Loc::getMessage('CO_IB_ELEM_ALT'); ?>" href="<?php echo CIBlock::GetAdminElementListLink($res['ID'], ['find_section_section' => '0', 'admin' => 'Y']); ?>"><?php echo $res['NAME']; ?></a> (<?php echo $arIBlockSitesList[$res['ID']]['WITH_LINKS']; ?>)
				<input type="hidden" name="IS_OFFERS_<?php echo $res['ID']; ?>" value="<?php echo $res['IS_OFFERS']; ?>" />
			</td>
			<td align="center" style="text-align: center;"><input type="hidden" name="IS_CATALOG_<?echo $res['ID']; ?>" id="IS_CATALOG_<?echo $res['ID']; ?>_N" value="N"><input type="checkbox" name="IS_CATALOG_<?echo $res['ID']; ?>" id="IS_CATALOG_<?echo $res['ID']; ?>_Y" onclick="ib_checkFldActivity('<?php echo $res['ID']; ?>', 0)" <?if ('Y' === $res['IS_CATALOG']) {
			    echo 'checked="checked"';
			}?> <?php if ('Y' === $res['IS_OFFERS']) {
			    echo 'disabled="disabled"';
			} ?>value="Y" /></td>
			<td align="center"><select id="OFFERS_IBLOCK_ID_<?php echo $res['ID']; ?>" name="OFFERS_IBLOCK_ID_<?php echo $res['ID']; ?>" class="typeselect" <?php echo 'Y' === $res['IS_OFFERS'] ? 'disabled="disabled"' : 'onchange="show_add_offers('.$res['ID'].',this);"'; ?> style="width: 100%;">
			<option value="0" <?php echo 0 === $res['OFFERS_IBLOCK_ID'] ? 'selected' : ''; ?>><?php echo Loc::getMessage('CAT_IBLOCK_OFFERS_EMPTY'); ?></option>
			<?php
            if ('Y' !== $res['IS_OFFERS']) {
                if ($USER->IsAdmin()) {
                    ?><option value="<?php echo CATALOG_NEW_OFFERS_IBLOCK_NEED; ?>"><?php echo Loc::getMessage('CAT_IBLOCK_OFFERS_NEW'); ?></option><?php
                }
                foreach ($arDoubleIBlockFullInfo as &$value) {
                    if ($value['ID'] !== $res['OFFERS_IBLOCK_ID']) {
                        if (
                            ('Y' !== $value['IS_CATALOG'])
                            || ('N' === $value['ACTIVE'])
                            || ('Y' === $value['IS_OFFERS'])
                            || (0 < $value['OFFERS_IBLOCK_ID'])
                            || ($res['ID'] === $value['ID'])
                            || (0 < $value['PRODUCT_IBLOCK_ID'])
                        ) {
                            continue;
                        }

                        $arDiffParent = [];
                        $arDiffParent = array_diff($value['SITE_ID'], $res['SITE_ID']);
                        $arDiffOffer = [];
                        $arDiffOffer = array_diff($res['SITE_ID'], $value['SITE_ID']);
                        if (!empty($arDiffParent) || !empty($arDiffOffer)) {
                            continue;
                        }
                    }
                    ?><option value="<?php echo (int) $value['ID']; ?>"<?php echo $value['ID'] === $res['OFFERS_IBLOCK_ID'] ? ' selected' : ''; ?>><?php echo $value['FULL_NAME']; ?></option><?php
                }
                if (isset($value)) {
                    unset($value);
                }
            }
        ?>
			</select>
			<div id="offers_add_info_<?php echo $res['ID']; ?>" style="display: none; width: 98%; margin: 0 1%;"><table class="internal" style="width: 100%;"><tbody>
				<tr><td style="text-align: right; width: 25%;"><?php echo Loc::getMessage('CAT_IBLOCK_OFFERS_TITLE'); ?>:</td><td style="text-align: left; width: 75%;"><input type="text" name="OFFERS_NAME_<?php echo $res['ID']; ?>" value="" style="width: 98%; margin: 0 1%;" /></td></tr>
				<tr><td style="text-align: left; width: 100%;" colspan="2"><input type="radio" value="N" id="CREATE_OFFERS_TYPE_N_<?php echo $res['ID']; ?>" name="CREATE_OFFERS_TYPE_<?php echo $res['ID']; ?>" checked="checked" onclick="change_offers_ibtype(this,<?php echo $res['ID']; ?>);"><label for="CREATE_OFFERS_TYPE_N_<?php echo $res['ID']; ?>"><?php echo Loc::getMessage('CAT_IBLOCK_OFFERS_OLD_IBTYPE'); ?></label></td></tr>
				<tr><td style="text-align: right; width: 25%;"><?php echo Loc::getMessage('CAT_IBLOCK_OFFERS_TYPE'); ?>:</td><td style="text-align: left; width: 75%;"><?php echo SelectBoxFromArray('OFFERS_TYPE_'.$res['ID'], ['REFERENCE' => $arIBlockTypeNameList, 'REFERENCE_ID' => $arIBlockTypeIDList], '', '', 'style="width: 98%;  margin: 0 1%;"'); ?></td></tr>
				<tr><td style="text-align: left; width: 100%;" colspan="2"><input type="radio" value="Y" id="CREATE_OFFERS_TYPE_Y_<?php echo $res['ID']; ?>" name="CREATE_OFFERS_TYPE_<?php echo $res['ID']; ?>" onclick="change_offers_ibtype(this,<?php echo $res['ID']; ?>);"><label for="CREATE_OFFERS_TYPE_Y_<?php echo $res['ID']; ?>"><?php echo Loc::getMessage('CAT_IBLOCK_OFFERS_NEW_IBTYPE'); ?></label></td></tr>
				<tr><td style="text-align: right; width: 25%;"><?php echo Loc::getMessage('CAT_IBLOCK_OFFERS_NEWTYPE'); ?>:</td><td style="text-align: left; width: 75%;"><input type="text" name="OFFERS_NEWTYPE_<?php echo $res['ID']; ?>" value="" style="width: 98%; margin: 0 1%;" disabled="disabled" /></td></tr>
			</tbody></table></div></td><?php
        if (CBXFeatures::IsFeatureEnabled('SaleRecurring')) {
            ?><td align="center" style="text-align: center;"><input type="hidden" name="IS_CONTENT_<?echo $res['ID']; ?>" id="IS_CONTENT_<?echo $res['ID']; ?>_N" value="N"><input type="checkbox" name="IS_CONTENT_<?echo $res['ID']; ?>" id="IS_CONTENT_<?echo $res['ID']; ?>_Y" onclick="ib_checkFldActivity('<?php echo $res['ID']; ?>', 1)" <?if ('Y' === $res['IS_CONTENT']) {
                echo 'checked';
            }?> value="Y" /></td><?php
        } else {
            ?><input type="hidden" name="IS_CONTENT_<?echo $res['ID']; ?>" value="N" id="IS_CONTENT_<?echo $res['ID']; ?>_N"><?php
        }
        ?><td align="center" style="text-align: center;"><input type="hidden" name="YANDEX_EXPORT_<?echo $res['ID']; ?>" id="YANDEX_EXPORT_<?echo $res['ID']; ?>_N"><input type="checkbox" name="YANDEX_EXPORT_<?echo $res['ID']; ?>" id="YANDEX_EXPORT_<?echo $res['ID']; ?>_Y" <?if ('N' === $res['IS_CATALOG']) {
            echo 'disabled="disabled"';
        }?> <?if ('Y' === $res['YANDEX_EXPORT']) {
            echo 'checked';
        }?> value="Y" /></td>
			<td align="center"><?php echo SelectBoxFromArray('VAT_ID_'.$res['ID'], $arVATRef, $res['VAT_ID'], '', 'N' === $res['IS_CATALOG'] ? 'disabled="disabled"' : ''); ?></td>
		</tr>
		<?php
    }
if (isset($res)) {
    unset($res);
}
?>
</table>
</td></tr>
<?php

if ($USER->IsAdmin()) {
    if (CBXFeatures::IsFeatureEnabled('SaleRecurring')) {
        $tabControl->BeginNextTab();

        $arVal = [];
        $strVal = (string) Option::get('catalog', 'avail_content_groups');
        if ('' !== $strVal) {
            $arVal = explode(',', $strVal);
        }

        $dbUserGroups = CGroup::GetList($b = 'c_sort', $o = 'asc', ['ANONYMOUS' => 'N']);
        while ($arUserGroups = $dbUserGroups->Fetch()) {
            $arUserGroups['ID'] = (int) $arUserGroups['ID'];
            if (2 === $arUserGroups['ID']) {
                continue;
            }
            ?>
		<tr>
			<td width="40%"><label for="user_group_<?php echo $arUserGroups['ID']; ?>"><?php echo htmlspecialcharsEx($arUserGroups['NAME']); ?></label> [<a href="group_edit.php?ID=<?php echo $arUserGroups['ID']; ?>&lang=<?php echo LANGUAGE_ID; ?>" title="<?php echo Loc::getMessage('CO_USER_GROUP_ALT'); ?>"><?php echo $arUserGroups['ID']; ?></a>]:</td>
			<td width="60%"><input type="checkbox" id="user_group_<?php echo $arUserGroups['ID']; ?>" name="AVAIL_CONTENT_GROUPS[]"<?if (in_array($arUserGroups['ID'], $arVal, true)) {
			    echo ' checked';
			}?> value="<?php echo $arUserGroups['ID']; ?>"></td>
		</tr>
		<?php
        }
    }

    $tabControl->BeginNextTab();

    require_once $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/admin/group_rights2.php';
}

$tabControl->Buttons();
?>
<input type="submit" class="adm-btn-save" <?php if ($bReadOnly) {
    echo 'disabled';
} ?> name="Update" value="<?php echo Loc::getMessage('CAT_OPTIONS_BTN_SAVE'); ?>">
<input type="hidden" name="Update" value="Y">
<input type="reset" name="reset" value="<?echo Loc::getMessage('CAT_OPTIONS_BTN_RESET'); ?>">
<input type="button" <?if ($bReadOnly) {
    echo 'disabled';
} ?> title="<?echo Loc::getMessage('CAT_OPTIONS_BTN_HINT_RESTORE_DEFAULT'); ?>" onclick="RestoreDefaults();" value="<?echo Loc::getMessage('CAT_OPTIONS_BTN_RESTORE_DEFAULT'); ?>">
</form>
<script type="text/javascript">
BX.hint_replace(BX('hint_reservation'), '<?php echo Loc::getMessage('CAT_ENABLE_RESERVATION_HINT'); ?>');
</script>
<?php
$tabControl->End();

if ($bReadOnly) {
    return;
}

$catalogData = Catalog\CatalogIblockTable::getList([
    'select' => ['CNT'],
    'runtime' => [
        new Main\Entity\ExpressionField('CNT', 'COUNT(*)'),
    ],
])->fetch();
$catalogCount = (isset($catalogData['CNT']) ? (int) $catalogData['CNT'] : 0);
unset($catalogData);
?><h2><?php echo Loc::getMessage('COP_SYS_ROU'); ?></h2>
<?php
$aTabs = [
    ['DIV' => 'fedit2', 'TAB' => Loc::getMessage('COP_TAB2_AGENT'), 'ICON' => 'catalog_settings', 'TITLE' => Loc::getMessage('COP_TAB2_AGENT_TITLE')],
    ['DIV' => 'fedit4', 'TAB' => Loc::getMessage('COP_TAB_RECALC'), 'ICON' => 'catalog_settings', 'TITLE' => Loc::getMessage('COP_TAB_RECALC_TITLE')],
];
if ('N' === $strUseStoreControl && $catalogCount > 0) {
    $aTabs[] = ['DIV' => 'fedit3', 'TAB' => Loc::getMessage('CAT_QUANTITY_CONTROL_TAB'), 'ICON' => 'catalog_settings', 'TITLE' => Loc::getMessage('CAT_QUANTITY_CONTROL')];
    ?>
<script type="text/javascript">
	function catClearQuantity(el, action)
	{
		var waiter_parent = BX.findParent(el, BX.is_relative),
			pos = BX.pos(el, !!waiter_parent);
		var iblockId = BX("catalogs_id").value;
		if(action == 'clearStore')
		{
			iblockId = BX("catalogs_store_id").value;
		}
		var dateURL = {
			sessid: BX.bitrix_sessid(),
			iblockId: iblockId,
			action: action,
			elId: el.id
		};
		if (action === 'clearStore')
		{
			var obStore = BX('stores_id');
			if (!!obStore)
			{
				dateURL.storeId = obStore.value;
			}
			else
			{
				return;
			}
		}
		el.disabled = true;
		el.bxwaiter = (waiter_parent || document.body).appendChild(BX.create('DIV', {
			props: {className: 'adm-btn-load-img'},
			style: {
				top: parseInt((pos.bottom + pos.top)/2 - 5, 10) + 'px',
				left: parseInt((pos.right + pos.left)/2 - 9, 10) + 'px'
			}
		}));
		BX.addClass(el, 'adm-btn-load');
		BX.ajax.post(
			'/bitrix/admin/cat_quantity_control.php?lang=<?php echo LANGUAGE_ID; ?>',
			dateURL,
			catClearQuantityResult
		);
	}

	function catClearQuantityResult(result)
	{
		if (result.length > 0)
		{
			var res = eval( '('+result+')' );
			var el = BX(res);
			BX(res).setAttribute('class', 'adm-btn');
			if (el.bxwaiter && el.bxwaiter.parentNode)
			{
				el.bxwaiter.parentNode.removeChild(el.bxwaiter);
				el.bxwaiter = null;
			}
			el.disabled = false;
		}
	}
</script>
<?php
}

$systemTabControl = new CAdminTabControl('tabControl2', $aTabs, true, true);

$systemTabControl->Begin();
$systemTabControl->BeginNextTab();
?><tr><td align="left"><?php
$arAgentInfo = false;
$rsAgents = CAgent::GetList([], ['MODULE_ID' => 'catalog', 'NAME' => 'CCatalog::PreGenerateXML("yandex");']);
if ($arAgent = $rsAgents->Fetch()) {
    $arAgentInfo = $arAgent;
}
if (!is_array($arAgentInfo) || empty($arAgentInfo)) {
    ?><form name="agent_form" method="POST" action="<?echo $APPLICATION->GetCurPage(); ?>?mid=<?php echo htmlspecialcharsbx($mid); ?>&lang=<?php echo LANGUAGE_ID; ?>">
	<?echo bitrix_sessid_post(); ?>
	<input type="submit" class="adm-btn-save" name="agent_start" value="<?php echo Loc::getMessage('CAT_AGENT_START'); ?>" <?if ($bReadOnly) {
	    echo 'disabled';
	} ?>>
	</form><?php
} else {
    echo Loc::getMessage('CAT_AGENT_ACTIVE').':&nbsp;'.('Y' === $arAgentInfo['ACTIVE'] ? Loc::getMessage('MAIN_YES') : Loc::getMessage('MAIN_NO')).'<br>';
    if ($arAgentInfo['LAST_EXEC']) {
        echo Loc::getMessage('CAT_AGENT_LAST_EXEC').':&nbsp;'.($arAgentInfo['LAST_EXEC'] ?: '').'<br>';
        echo Loc::getMessage('CAT_AGENT_NEXT_EXEC').':&nbsp;'.($arAgentInfo['NEXT_EXEC'] ?: '').'<br>';
    } else {
        echo Loc::getMessage('CAT_AGENT_WAIT_START').'<br>';
    }
}
?><br><?php
$strYandexFile = str_replace('//', '/', Option::get('catalog', 'export_default_path').'/yandex.php');
if (file_exists($_SERVER['DOCUMENT_ROOT'].$strYandexFile)) {
    echo Loc::getMessage(
        'CAT_AGENT_FILEPATH',
        [
            '#FILE#' => '<a href="'.$strYandexFile.'">'.$strYandexFile.'</a>',
        ]
    ).'<br>';
} else {
    echo Loc::getMessage('CAT_AGENT_FILE_ABSENT').'<br>';
}
?><br><?php
echo Loc::getMessage('CAT_AGENT_EVENT_LOG').':&nbsp;';

?><a href="/bitrix/admin/event_log.php?lang=<?php echo LANGUAGE_ID; ?>&set_filter=Y<?php echo CCatalogEvent::GetYandexAgentFilter(); ?>"><?php echo Loc::getMessage('CAT_AGENT_EVENT_LOG_SHOW_ERROR'); ?></a>
</td></tr>
<?php
$systemTabControl->BeginNextTab();
?><tr><td align="left"><?php
$firstTop = ' style="margin-top: 0;"';
if (!$useSaleDiscountOnly) {
    ?><h4<?php echo $firstTop; ?>><?php echo Loc::getMessage('CAT_PROC_REINDEX_DISCOUNT'); ?></h4>
	<input class="adm-btn-save" type="button" id="discount_reindex" value="<?php echo Loc::getMessage('CAT_PROC_REINDEX_DISCOUNT_BTN'); ?>">
	<p><?php echo Loc::getMessage('CAT_PROC_REINDEX_DISCOUNT_ALERT'); ?></p><?php
    $firstTop = '';
}
if ($catalogCount > 0) {
    ?><h4<?php echo $firstTop; ?>><?php echo Loc::getMessage('CAT_PROC_REINDEX_CATALOG'); ?></h4>
	<input class="adm-btn-save" type="button" id="catalog_reindex" value="<?php echo Loc::getMessage('CAT_PROC_REINDEX_CATALOG_BTN'); ?>">
	<p><?php echo Loc::getMessage('CAT_PROC_REINDEX_CATALOG_ALERT'); ?></p><?php
    if (CBXFeatures::IsFeatureEnabled('CatCompleteSet') && CCatalogProductSetAvailable::getAllCounter() > 0) {
        ?><h4><?php echo Loc::getMessage('CAT_PROC_REINDEX_SETS_AVAILABLE'); ?></h4>
		<input class="adm-btn-save" type="button" id="sets_reindex" value="<?php echo Loc::getMessage('CAT_PROC_REINDEX_SETS_AVAILABLE_BTN'); ?>">
		<p><?php echo Loc::getMessage('CAT_PROC_REINDEX_SETS_AVAILABLE_ALERT'); ?></p><?php
    }
}
?>
</td></tr><?php
    if ('N' === $strUseStoreControl && $catalogCount > 0) {
        $userListID = [];
        $strQuantityUser = '';
        $strQuantityReservedUser = '';
        $strStoreUser = '';
        $strClearQuantityDate = '';
        $strClearQuantityReservedDate = '';
        $strClearStoreDate = '';

        $clearQuantityUser = (int) Option::get('catalog', 'clear_quantity_user');
        if ($clearQuantityUser < 0) {
            $clearQuantityUser = 0;
        }
        $userListID[$clearQuantityUser] = true;

        $clearQuantityReservedUser = (int) Option::get('catalog', 'clear_reserved_quantity_user');
        if ($clearQuantityReservedUser < 0) {
            $clearQuantityReservedUser = 0;
        }
        $userListID[$clearQuantityReservedUser] = true;

        $clearStoreUser = (int) Option::get('catalog', 'clear_store_user');
        if ($clearStoreUser < 0) {
            $clearStoreUser = 0;
        }
        $userListID[$clearStoreUser] = true;

        if (isset($userListID[0])) {
            unset($userListID[0]);
        }
        if (!empty($userListID)) {
            $strClearQuantityDate = Option::get('catalog', 'clear_quantity_date');
            $strClearQuantityReservedDate = Option::get('catalog', 'clear_reserved_quantity_date');
            $strClearStoreDate = Option::get('catalog', 'clear_store_date');

            $arUserList = [];
            $strNameFormat = CSite::GetNameFormat(true);

            $canViewUserList = (
                $USER->CanDoOperation('view_subordinate_users')
                || $USER->CanDoOperation('view_all_users')
                || $USER->CanDoOperation('edit_all_users')
                || $USER->CanDoOperation('edit_subordinate_users')
            );
            $userIterator = Main\UserTable::getList([
                'select' => ['ID', 'LOGIN', 'NAME', 'LAST_NAME', 'SECOND_NAME'],
                'filter' => ['ID' => array_keys($userListID)],
            ]);
            while ($arOneUser = $userIterator->fetch()) {
                $arOneUser['ID'] = (int) $arOneUser['ID'];
                if ($canViewUserList) {
                    $arUserList[$arOneUser['ID']] = '<a href="/bitrix/admin/user_edit.php?lang='.LANGUAGE_ID.'&ID='.$arOneUser['ID'].'">'.CUser::FormatName($strNameFormat, $arOneUser).'</a>';
                } else {
                    $arUserList[$arOneUser['ID']] = CUser::FormatName($strNameFormat, $arOneUser);
                }
            }
            unset($arOneUser, $userIterator, $canViewUserList);
            if (isset($arUserList[$clearQuantityUser])) {
                $strQuantityUser = $arUserList[$clearQuantityUser];
            }
            if (isset($arUserList[$clearQuantityReservedUser])) {
                $strQuantityReservedUser = $arUserList[$clearQuantityReservedUser];
            }
            if (isset($arUserList[$clearStoreUser])) {
                $strStoreUser = $arUserList[$clearStoreUser];
            }
        }
        $boolStoreExists = false;
        $arStores = [];
        $arStores[] = ['ID' => -1, 'ADDRESS' => Loc::getMessage('CAT_ALL_STORES')];
        $rsStores = CCatalogStore::GetList(
            ['SORT' => 'ASC', 'ID' => 'ASC'],
            ['ACTIVE' => 'Y'],
            false,
            false,
            ['ID', 'TITLE', 'ADDRESS']
        );
        while ($arStore = $rsStores->GetNext()) {
            $boolStoreExists = true;
            $arStores[] = $arStore;
        }

        $systemTabControl->BeginNextTab();
        ?>
	<tr>
		<td><?php echo Loc::getMessage('CAT_SELECT_CATALOG'); ?>:</td>
		<td>
			<select style="max-width: 300px" id="catalogs_id" name="catalogs_id" <?php echo $bReadOnly ? ' disabled' : ''; ?>>
				<?php
                    // TODO: need get catalog list
                    foreach ($arCatalogList as &$arOneCatalog) {
                        echo '<option value="'.$arOneCatalog['ID'].'">'.htmlspecialcharsex($arOneCatalog['NAME']).' ('.$arIBlockSitesList[$arOneCatalog['ID']]['WITHOUT_LINKS'].')</option>';
                    }
        unset($arOneCatalog);
        ?>
			</select>
		</td>
	</tr>

	<tr>
		<td width="40%"><?php echo Loc::getMessage('CAT_CLEAR_QUANTITY'); ?>:</td>
		<td width="60%">
			<input type="button" value="<?php echo Loc::getMessage('CAT_CLEAR_ACTION'); ?>" id="cat_clear_quantity_btn" onclick="catClearQuantity(this, 'clearQuantity')">
			<?php
            if (0 < $clearQuantityUser) {
                ?><span style="font-size: smaller;"><?php echo $strQuantityUser; ?>&nbsp;<?php echo $strClearQuantityDate; ?></span><?php
            }
        ?>
		</td>
	</tr>
	<tr>
		<td width="40%"><?php echo Loc::getMessage('CAT_CLEAR_RESERVED_QUANTITY'); ?></td>
		<td>
			<input type="button" value="<?php echo Loc::getMessage('CAT_CLEAR_ACTION'); ?>" id="cat_clear_reserved_quantity_btn" onclick="catClearQuantity(this, 'clearReservedQuantity')">
			<?php
        if (0 < $clearQuantityUser) {
            ?><span style="font-size: smaller;"><?php echo $strQuantityReservedUser; ?>&nbsp;<?php echo $strClearQuantityReservedDate; ?></span><?php
        }
        ?>
		</td>
	</tr>
	<tr class="heading">
		<td colspan="2"><?php echo Loc::getMessage('CAT_CLEAR_STORE'); ?></td>
	</tr>
<?php
        if ($boolStoreExists) {
            ?>
	<tr>
		<td><?php echo Loc::getMessage('CAT_SELECT_CATALOG'); ?>:</td>
		<td>
			<select style="max-width: 300px" id="catalogs_store_id" name="catalogs_store_id" <?php echo $bReadOnly ? ' disabled' : ''; ?>>
				<?foreach ($arCatalogList as &$arOneCatalog) {
				    echo '<option value="'.$arOneCatalog['ID'].'">'.htmlspecialcharsex($arOneCatalog['NAME']).' ('.$arIBlockSitesList[$arOneCatalog['ID']]['WITHOUT_LINKS'].')</option>';
				}
            unset($arOneCatalog);
            ?>
			</select>
		</td>
	</tr>
	<tr>
		<td><?php echo Loc::getMessage('CAT_SELECT_STORE'); ?>:</td>
		<td>
			<select style="max-width: 300px;" id="stores_id" name="stores_id" <?php echo $bReadOnly ? ' disabled' : ''; ?>>
				<?php
            foreach ($arStores as $key => $val) {
                $store = ('' !== $val['TITLE']) ? $val['TITLE'].' ('.$val['ADDRESS'].')' : $val['ADDRESS'];
                echo '<option value="'.$val['ID'].'">'.$store.'</option>';
            }
            ?>
			</select>

		</td>
	</tr>
	<tr>
		<td><?php echo Loc::getMessage('CAT_CLEAR_STORE'); ?>:</td>
		<td>
			<input type="button" value="<?php echo Loc::getMessage('CAT_CLEAR_ACTION'); ?>" id="cat_clear_store_btn" onclick="catClearQuantity(this, 'clearStore')">
			<?php
            if (0 < $clearStoreUser) {
                ?><span style="font-size: smaller;"><?php echo $strStoreUser; ?>&nbsp;<?php echo $strClearStoreDate; ?></span><?php
            }
            ?>
		</td>
	</tr>
<?php
        } else {
            ?>
	<tr>
		<td colspan="2"><?php echo Loc::getMessage('CAT_STORE_LIST_IS_EMPTY'); ?></td>
	</tr>
<?php
        }
    }
$systemTabControl->End();
?>
<script type="text/javascript">
function showDiscountReindex()
{
	var obDiscount, params;

	params = {
		bxpublic: 'Y',
		sessid: BX.bitrix_sessid()
	};

	var obBtn = {
		title: '<?php echo CUtil::JSEscape(Loc::getMessage('CAT_POPUP_WINDOW_CLOSE_BTN')); ?>',
		id: 'close',
		name: 'close',
		action: function () {
			this.parentWindow.Close();
		}
	};

	obDiscount = new BX.CAdminDialog({
		'content_url': '/bitrix/admin/cat_discount_convert.php?lang=<?php echo LANGUAGE_ID; ?>&format=Y',
		'content_post': params,
		'draggable': true,
		'resizable': true,
		'buttons': [obBtn]
	});
	obDiscount.Show();
	return false;
}
function showSetsAvailableReindex()
{
	var obWindow, params;

	params = {
		bxpublic: 'Y',
		sessid: BX.bitrix_sessid()
	};

	var obBtn = {
		title: '<?php echo CUtil::JSEscape(Loc::getMessage('CAT_POPUP_WINDOW_CLOSE_BTN')); ?>',
		id: 'close',
		name: 'close',
		action: function () {
			this.parentWindow.Close();
		}
	};

	obWindow = new BX.CAdminDialog({
		'content_url': '/bitrix/tools/catalog/sets_available.php?lang=<?php echo LANGUAGE_ID; ?>',
		'content_post': params,
		'draggable': true,
		'resizable': true,
		'buttons': [obBtn]
	});
	obWindow.Show();
	return false;
}

function showCatalogReindex()
{
	var obWindow, params;

	params = {
		bxpublic: 'Y',
		sessid: BX.bitrix_sessid()
	};

	var obBtn = {
		title: '<?php echo CUtil::JSEscape(Loc::getMessage('CAT_POPUP_WINDOW_CLOSE_BTN')); ?>',
		id: 'close',
		name: 'close',
		action: function () {
			this.parentWindow.Close();
		}
	};

	obWindow = new BX.CAdminDialog({
		'content_url': '/bitrix/tools/catalog/catalog_reindex.php?lang=<?php echo LANGUAGE_ID; ?>',
		'content_post': params,
		'draggable': true,
		'resizable': true,
		'buttons': [obBtn]
	});
	obWindow.Show();
	return false;
}

function showProductSettings()
{
	var obWindow, params;

	params = {
		bxpublic: 'Y',
		sessid: BX.bitrix_sessid()
	};

	var obBtn = {
		title: '<?php echo CUtil::JSEscape(Loc::getMessage('CAT_POPUP_WINDOW_CLOSE_BTN')); ?>',
		id: 'close',
		name: 'close',
		action: function () {
			this.parentWindow.Close();
		}
	};

	obWindow = new BX.CAdminDialog({
		'content_url': '/bitrix/tools/catalog/product_settings.php?lang=<?php echo LANGUAGE_ID; ?>',
		'content_post': params,
		'draggable': true,
		'resizable': true,
		'buttons': [obBtn]
	});
	obWindow.Show();
	return false;
}

function changeProductSettings(params)
{
	var i, ob;
	if (!BX.type.isPlainObject(params))
		return;
	for (i in params)
	{
		ob = BX(i);
		if (!!ob)
			ob.innerHTML = BX.util.htmlspecialchars(params[i]);
	}
}

BX.ready(function(){
	var discountReindex = BX('discount_reindex'),
		setsReindex = BX('sets_reindex'),
		catalogReindex = BX('catalog_reindex'),
		productSettings = BX('product_settings');

	if (!!discountReindex)
		BX.bind(discountReindex, 'click', showDiscountReindex);
	if (!!setsReindex)
		BX.bind(setsReindex, 'click', showSetsAvailableReindex);
	if (!!catalogReindex)
		BX.bind(catalogReindex, 'click', showCatalogReindex);
	if (!!productSettings)
		BX.bind(productSettings, 'click', showProductSettings);
});
</script>