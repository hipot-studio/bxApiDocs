<?php

use Bitrix\Catalog;
use Bitrix\Iblock;
use Bitrix\Main;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\Collection;

Loc::loadMessages(__FILE__);

// define("CATALOG_PATH2EXPORTS", "/bitrix/php_interface/include/catalog_export/");
// define("CATALOG_PATH2EXPORTS_DEF", "/bitrix/modules/catalog/load/");
// define('CATALOG_DEFAULT_EXPORT_PATH', '/bitrix/catalog_export/');

// define("CATALOG_PATH2IMPORTS", "/bitrix/php_interface/include/catalog_import/");
// define("CATALOG_PATH2IMPORTS_DEF", "/bitrix/modules/catalog/load_import/");

// define("YANDEX_SKU_EXPORT_ALL",1);
// define("YANDEX_SKU_EXPORT_MIN_PRICE",2);
// define("YANDEX_SKU_EXPORT_PROP",3);
// define("YANDEX_SKU_TEMPLATE_PRODUCT",1);
// define("YANDEX_SKU_TEMPLATE_OFFERS",2);
// define("YANDEX_SKU_TEMPLATE_CUSTOM",3);

// define("EXPORT_VERSION_OLD", 1);
// define("EXPORT_VERSION_NEW", 2);

/*
* @deprecated deprecated since catalog 14.5.3
* @see CCatalogDiscount::ENTITY_ID
*/
// define('DISCOUNT_TYPE_STANDART',0);
/*
* @deprecated deprecated since catalog 14.5.3
* @see CCatalogDiscountSave::ENTITY_ID
*/
// define('DISCOUNT_TYPE_SAVE',1);

/*
* @deprecated deprecated since catalog 14.5.3
* @see CCatalogDiscount::OLD_FORMAT
*/
// define("CATALOG_DISCOUNT_OLD_VERSION", 1);
/*
* @deprecated deprecated since catalog 14.5.3
* @see CCatalogDiscount::CURRENT_FORMAT
*/
// define("CATALOG_DISCOUNT_NEW_VERSION", 2);

// define('BX_CATALOG_FILENAME_REG','/[^a-zA-Z0-9\s!#\$%&\(\)\[\]\{\}+\.;=@\^_\~\/\\\\\-]/i');

// Constants for the store control: //
// define('CONTRACTOR_INDIVIDUAL', 1);
// define('CONTRACTOR_JURIDICAL', 2);
// define('DOC_ARRIVAL', 'A');
// define('DOC_MOVING', 'M');
// define('DOC_RETURNS', 'R');
// define('DOC_DEDUCT', 'D');
// define('DOC_INVENTORY', 'I');

// **********************************//

global $APPLICATION;

if (!Loader::includeModule('iblock')) {
    $APPLICATION->ThrowException(Loc::getMessage('CAT_ERROR_IBLOCK_NOT_INSTALLED'));

    return false;
}

if (!Loader::includeModule('currency')) {
    $APPLICATION->ThrowException(Loc::getMessage('CAT_ERROR_CURRENCY_NOT_INSTALLED'));

    return false;
}

$arTreeDescr = [
    'js' => '/bitrix/js/catalog/core_tree.js',
    'css' => '/bitrix/panel/catalog/catalog_cond.css',
    'lang' => '/bitrix/modules/catalog/lang/'.LANGUAGE_ID.'/js_core_tree.php',
    'rel' => ['core', 'date', 'window'],
];
CJSCore::RegisterExt('core_condtree', $arTreeDescr);

global $DB;
$strDBType = strtolower($DB->type);

// define('CATALOG_VALUE_EPSILON', 1e-6);
// define('CATALOG_VALUE_PRECISION', 2);
// define('CATALOG_CACHE_DEFAULT_TIME', 10800);

Loader::registerAutoLoadClasses(
    'catalog',
    [
        'catalog' => 'install/index.php',
        'CCatalog' => $strDBType.'/catalog.php',
        'CCatalogGroup' => $strDBType.'/cataloggroup.php',
        'CExtra' => $strDBType.'/extra.php',
        'CPrice' => $strDBType.'/price.php',
        'CCatalogProduct' => $strDBType.'/product.php',
        'CCatalogProductGroups' => $strDBType.'/product_group.php',
        'CCatalogLoad' => $strDBType.'/catalog_load.php',
        'CCatalogExport' => $strDBType.'/catalog_export.php',
        'CCatalogImport' => $strDBType.'/catalog_import.php',
        'CCatalogDiscount' => $strDBType.'/discount.php',
        'CCatalogDiscountCoupon' => $strDBType.'/discount_coupon.php',
        'CCatalogVat' => $strDBType.'/vat.php',
        'CCatalogEvent' => 'general/catalog_event.php',
        'CCatalogSku' => 'general/catalog_sku.php',
        'CCatalogDiscountSave' => $strDBType.'/discount_save.php',
        'CCatalogStore' => $strDBType.'/store.php',
        'CCatalogStoreProduct' => $strDBType.'/store_product.php',
        'CCatalogAdmin' => 'general/admin.php',
        'CGlobalCondCtrl' => 'general/catalog_cond.php',
        'CGlobalCondCtrlComplex' => 'general/catalog_cond.php',
        'CGlobalCondCtrlAtoms' => 'general/catalog_cond.php',
        'CGlobalCondCtrlGroup' => 'general/catalog_cond.php',
        'CGlobalCondTree' => 'general/catalog_cond.php',
        'CCatalogCondCtrl' => 'general/catalog_cond.php',
        'CCatalogCondCtrlComplex' => 'general/catalog_cond.php',
        'CCatalogCondCtrlGroup' => 'general/catalog_cond.php',
        'CCatalogCondCtrlIBlockFields' => 'general/catalog_cond.php',
        'CCatalogCondCtrlIBlockProps' => 'general/catalog_cond.php',
        'CCatalogCondTree' => 'general/catalog_cond.php',
        'CCatalogCondCtrlBasketProductFields' => 'general/sale_cond.php',
        'CCatalogCondCtrlBasketProductProps' => 'general/sale_cond.php',
        'CCatalogCondCtrlCatalogSettings' => 'general/sale_cond.php',
        'CCatalogActionCtrlBasketProductFields' => 'general/sale_act.php',
        'CCatalogActionCtrlBasketProductProps' => 'general/sale_act.php',
        'CCatalogGifterProduct' => 'general/sale_act.php',
        'CCatalogDiscountConvert' => 'general/discount_convert.php',
        'CCatalogDiscountConvertTmp' => $strDBType.'/discount_convert.php',
        'CCatalogProductProvider' => 'general/product_provider.php',
        'CCatalogStoreBarCode' => $strDBType.'/store_barcode.php',
        'CCatalogContractor' => $strDBType.'/contractor.php',
        'CCatalogArrivalDocs' => $strDBType.'/store_docs_type.php',
        'CCatalogMovingDocs' => $strDBType.'/store_docs_type.php',
        'CCatalogDeductDocs' => $strDBType.'/store_docs_type.php',
        'CCatalogReturnsDocs' => $strDBType.'/store_docs_type.php',
        'CCatalogUnReservedDocs' => $strDBType.'/store_docs_type.php',
        'CCatalogDocs' => $strDBType.'/store_docs.php',
        'CCatalogStoreControlUtil' => 'general/store_utility.php',
        'CCatalogStoreDocsElement' => $strDBType.'/store_docs_element.php',
        'CCatalogStoreDocsBarcode' => $strDBType.'/store_docs_barcode.php',
        'CCatalogIBlockParameters' => 'general/comp_parameters.php',
        'CCatalogMeasure' => $strDBType.'/measure.php',
        'CCatalogMeasureResult' => $strDBType.'/measure.php',
        'CCatalogMeasureClassifier' => 'general/unit_classifier.php',
        'CCatalogMeasureAdminResult' => 'general/measure_result.php',
        'CCatalogMeasureRatio' => $strDBType.'/measure_ratio.php',
        'CCatalogProductSet' => $strDBType.'/product_set.php',
        'CCatalogAdminTools' => 'general/admin_tools.php',
        'CCatalogAdminProductSetEdit' => 'general/admin_tools.php',
        'CCatalogMenu' => 'general/catalog_menu.php',
        'CCatalogCSVSettings' => 'general/csv_settings.php',
        'CCatalogStepOperations' => 'general/step_operations.php',
        'CCatalogProductSetAvailable' => 'general/step_operations.php',
        'CCatalogProductAvailable' => 'general/step_operations.php',
        'CCatalogProductSettings' => 'general/step_operations.php',
        'CCatalogTools' => 'general/tools.php',
        '\Bitrix\Catalog\Discount\DiscountManager' => 'lib/discount/discountmanager.php',
        '\Bitrix\Catalog\Ebay\EbayXMLer' => 'lib/ebay/ebayxmler.php',
        '\Bitrix\Catalog\Ebay\ExportOffer' => 'lib/ebay/exportoffer.php',
        '\Bitrix\Catalog\Ebay\ExportOfferCreator' => 'lib/ebay/exportoffercreator.php',
        '\Bitrix\Catalog\Ebay\ExportOfferSKU' => 'lib/ebay/exportoffersku.php',
        '\Bitrix\Catalog\Helpers\Admin\CatalogEdit' => 'lib/helpers/admin/catalogedit.php',
        '\Bitrix\Catalog\Helpers\Admin\IblockPriceChanger' => 'lib/helpers/admin/iblockpricechanger.php',
        '\Bitrix\Catalog\Helpers\Tools' => 'lib/helpers/tools.php',
        '\Bitrix\Catalog\Product\Price' => 'lib/product/price.php',
        '\Bitrix\Catalog\Product\Search' => 'lib/product/search.php',
        '\Bitrix\Catalog\Product\Sku' => 'lib/product/sku.php',
        '\Bitrix\Catalog\Product\SubscribeManager' => 'lib/product/subscribemanager.php',
        '\Bitrix\Catalog\Product\Viewed' => 'lib/product/viewed.php',
        '\Bitrix\Catalog\CatalogIblockTable' => 'lib/catalogiblock.php',
        '\Bitrix\Catalog\CatalogViewedProductTable' => 'lib/catalogviewedproduct.php',
        '\Bitrix\Catalog\DiscountTable' => 'lib/discount.php',
        '\Bitrix\Catalog\DiscountCouponTable' => 'lib/discountcoupon.php',
        '\Bitrix\Catalog\DiscountRestrictionTable' => 'lib/discountrestriction.php',
        '\Bitrix\Catalog\ExtraTable' => 'lib/extra.php',
        '\Bitrix\Catalog\GroupTable' => 'lib/group.php',
        '\Bitrix\Catalog\GroupAccessTable' => 'lib/groupaccess.php',
        '\Bitrix\Catalog\GroupLangTable' => 'lib/grouplang.php',
        '\Bitrix\Catalog\MeasureRatioTable' => 'lib/measureratio.php',
        '\Bitrix\Catalog\PriceTable' => 'lib/price.php',
        '\Bitrix\Catalog\ProductTable' => 'lib/product.php',
        '\Bitrix\Catalog\RoundingTable' => 'lib/rounding.php',
        '\Bitrix\Catalog\StoreTable' => 'lib/store.php',
        '\Bitrix\Catalog\StoreProductTable' => 'lib/storeproduct.php',
        '\Bitrix\Catalog\VatTable' => 'lib/vat.php',
        // deprecated
        '\Bitrix\Catalog\EbayXMLer' => 'lib/ebay/old.php',
        '\Bitrix\Catalog\ExportOffer' => 'lib/ebay/old.php',
        '\Bitrix\Catalog\ExportOfferCreator' => 'lib/ebay/old.php',
        '\Bitrix\Catalog\ExportOfferSKU' => 'lib/ebay/old.php',
        '\Bitrix\Catalog\SearchHandlers' => 'lib/product/old.php',
    ]
);
unset($strDBType);

if (defined('CATALOG_GLOBAL_VARS') && CATALOG_GLOBAL_VARS === 'Y') {
    global $CATALOG_CATALOG_CACHE;
    $CATALOG_CATALOG_CACHE = null;

    global $CATALOG_ONETIME_COUPONS_ORDER;
    $CATALOG_ONETIME_COUPONS_ORDER = null;

    global $CATALOG_PRODUCT_CACHE;
    $CATALOG_PRODUCT_CACHE = null;

    global $MAIN_EXTRA_LIST_CACHE;
    $MAIN_EXTRA_LIST_CACHE = null;

    global $CATALOG_BASE_GROUP;
    $CATALOG_BASE_GROUP = [];

    global $CATALOG_TIME_PERIOD_TYPES;
    $CATALOG_TIME_PERIOD_TYPES = CCatalogProduct::GetTimePeriodTypes(true);

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
}

function GetCatalogGroups($by = 'SORT', $order = 'ASC')
{
    return CCatalogGroup::GetList([$by => $order]);
}

function GetCatalogGroup($CATALOG_GROUP_ID)
{
    $CATALOG_GROUP_ID = (int) $CATALOG_GROUP_ID;

    return CCatalogGroup::GetByID($CATALOG_GROUP_ID);
}

function GetCatalogGroupName($CATALOG_GROUP_ID)
{
    $rn = GetCatalogGroup($CATALOG_GROUP_ID);

    return $rn['NAME_LANG'];
}

function GetCatalogProduct($PRODUCT_ID)
{
    $PRODUCT_ID = (int) $PRODUCT_ID;

    return CCatalogProduct::GetByID($PRODUCT_ID);
}

function GetCatalogProductEx($PRODUCT_ID, $boolAllValues = false)
{
    $PRODUCT_ID = (int) $PRODUCT_ID;

    return CCatalogProduct::GetByIDEx($PRODUCT_ID, $boolAllValues);
}

function GetCatalogProductPrice($PRODUCT_ID, $CATALOG_GROUP_ID)
{
    $PRODUCT_ID = (int) $PRODUCT_ID;
    $CATALOG_GROUP_ID = (int) $CATALOG_GROUP_ID;

    $db_res = CPrice::GetList($by = 'CATALOG_GROUP_ID', $order = 'ASC', ['PRODUCT_ID' => $PRODUCT_ID, 'CATALOG_GROUP_ID' => $CATALOG_GROUP_ID]);

    if ($res = $db_res->Fetch()) {
        return $res;
    }

    return false;
}

function GetCatalogProductPriceList($PRODUCT_ID, $by = 'SORT', $order = 'ASC')
{
    $PRODUCT_ID = (int) $PRODUCT_ID;

    $db_res = CPrice::GetList(
        [$by => $order],
        ['PRODUCT_ID' => $PRODUCT_ID]
    );

    $arPrice = [];
    while ($res = $db_res->Fetch()) {
        $arPrice[] = $res;
    }

    return $arPrice;
}

/**
 * @deprecated
 *
 * @param bool  $SECT_ID
 * @param array $arOrder
 * @param int   $cnt
 *
 * @return bool
 */
function GetCatalogProductTable($IBLOCK, $SECT_ID = false, $arOrder = ['sort' => 'asc'], $cnt = 0)
{
    return false;
}

/**
 * @deprecated deprecated since catalog 9.0.0
 * @see CurrencyFormat()
 *
 * @param mixed $fSum
 * @param mixed $strCurrency
 */
function FormatCurrency($fSum, $strCurrency)
{
    return CCurrencyLang::CurrencyFormat($fSum, $strCurrency, true);
}

/**
 * @deprecated deprecated since catalog 12.5.0
 * @see CCatalogProductProvider::GetProductData()
 *
 * @param mixed $productID
 * @param mixed $quantity
 * @param mixed $renewal
 * @param mixed $intUserID
 * @param mixed $strSiteID
 */
function CatalogBasketCallback($productID, $quantity = 0, $renewal = 'N', $intUserID = 0, $strSiteID = false)
{
    $arParams = [
        'PRODUCT_ID' => $productID,
        'QUANTITY' => $quantity,
        'RENEWAL' => $renewal,
        'USER_ID' => $intUserID,
        'SITE_ID' => $strSiteID,
        'CHECK_QUANTITY' => 'Y',
    ];

    return CCatalogProductProvider::GetProductData($arParams);
}

function CatalogBasketOrderCallback($productID, $quantity, $renewal = 'N', $intUserID = 0, $strSiteID = false)
{
    $arParams = [
        'PRODUCT_ID' => $productID,
        'QUANTITY' => $quantity,
        'RENEWAL' => $renewal,
        'USER_ID' => $intUserID,
        'SITE_ID' => $strSiteID,
    ];

    $arResult = CCatalogProductProvider::OrderProduct($arParams);
    if (!empty($arResult) && is_array($arResult) && isset($arResult['QUANTITY'])) {
        CCatalogProduct::QuantityTracer($productID, $arResult['QUANTITY']);
    }

    return $arResult;
}

function CatalogViewedProductCallback($productID, $UserID, $strSiteID = SITE_ID)
{
    global $USER;

    $productID = (int) $productID;
    $UserID = (int) $UserID;

    if ($productID <= 0) {
        return false;
    }

    $arResult = [];

    static $arUserCache = [];
    if ($UserID > 0) {
        if (!isset($arUserCache[$UserID])) {
            $by = 'ID';
            $order = 'DESC';
            $rsUsers = CUser::GetList($by, $order, ['ID_EQUAL_EXACT' => $UserID], ['FIELDS' => ['ID']]);
            if ($arUser = $rsUsers->Fetch()) {
                $arUserCache[$arUser['ID']] = CUser::GetUserGroup($arUser['ID']);
            } else {
                return false;
            }
        }

        CCatalogDiscountSave::SetDiscountUserID($UserID);

        $dbIBlockElement = CIBlockElement::GetList(
            [],
            [
                'ID' => $productID,
                'ACTIVE' => 'Y',
                'ACTIVE_DATE' => 'Y',
                'CHECK_PERMISSIONS' => 'N',
            ],
            false,
            false,
            ['ID', 'IBLOCK_ID', 'NAME', 'DETAIL_PAGE_URL', 'TIMESTAMP_X', 'PREVIEW_PICTURE', 'DETAIL_PICTURE']
        );
        if (!($arProduct = $dbIBlockElement->GetNext())) {
            return false;
        }

        if ('E' === CIBlock::GetArrayByID($arProduct['IBLOCK_ID'], 'RIGHTS_MODE')) {
            $arUserRights = CIBlockElementRights::GetUserOperations($productID, $UserID);
            if (empty($arUserRights)) {
                return false;
            }
            if (!is_array($arUserRights) || !array_key_exists('element_read', $arUserRights)) {
                return false;
            }
        } else {
            if (CIBlock::GetPermission($arProduct['IBLOCK_ID'], $UserID) < 'R') {
                return false;
            }
        }
    } else {
        $dbIBlockElement = CIBlockElement::GetList(
            [],
            [
                'ID' => $productID,
                'ACTIVE' => 'Y',
                'ACTIVE_DATE' => 'Y',
                'CHECK_PERMISSIONS' => 'Y',
                'MIN_PERMISSION' => 'R',
            ],
            false,
            false,
            ['ID', 'IBLOCK_ID', 'NAME', 'DETAIL_PAGE_URL', 'TIMESTAMP_X', 'PREVIEW_PICTURE', 'DETAIL_PICTURE']
        );
        if (!($arProduct = $dbIBlockElement->GetNext())) {
            return false;
        }
    }

    $bTrace = true;
    if ($arCatalogProduct = CCatalogProduct::GetByID($productID)) {
        if ('Y' !== $arCatalogProduct['CAN_BUY_ZERO'] && ('Y' === $arCatalogProduct['QUANTITY_TRACE'] && (float) $arCatalogProduct['QUANTITY'] <= 0)) {
            $currentPrice = 0.0;
            $currentDiscount = 0.0;
            $bTrace = false;
        }
    }

    if ($bTrace) {
        $arPrice = CCatalogProduct::GetOptimalPrice($productID, 1, $UserID > 0 ? $arUserCache[$UserID] : $USER->GetUserGroupArray(), 'N', [], $UserID > 0 ? $strSiteID : false, []);

        if (count($arPrice) > 0) {
            $currentPrice = $arPrice['PRICE']['PRICE'];
            $currentDiscount = 0.0;

            if ('N' === $arPrice['PRICE']['VAT_INCLUDED']) {
                if ((float) $arPrice['PRICE']['VAT_RATE'] > 0) {
                    $currentPrice *= (1 + $arPrice['PRICE']['VAT_RATE']);
                    $arPrice['PRICE']['VAT_INCLUDED'] = 'Y';
                }
            }

            if (!empty($arPrice['DISCOUNT'])) {
                $currentDiscount_tmp = 0;
                if ('F' === $arPrice['DISCOUNT']['VALUE_TYPE']) {
                    if ($arPrice['DISCOUNT']['CURRENCY'] === $arPrice['PRICE']['CURRENCY']) {
                        $currentDiscount = $arPrice['DISCOUNT']['VALUE'];
                    } else {
                        $currentDiscount = CCurrencyRates::ConvertCurrency($arPrice['DISCOUNT']['VALUE'], $arPrice['DISCOUNT']['CURRENCY'], $arPrice['PRICE']['CURRENCY']);
                    }
                } elseif ('S' === $arPrice['DISCOUNT']['VALUE_TYPE']) {
                    if ($arPrice['DISCOUNT']['CURRENCY'] === $arPrice['PRICE']['CURRENCY']) {
                        $currentDiscount = $arPrice['DISCOUNT']['VALUE'];
                    } else {
                        $currentDiscount = CCurrencyRates::ConvertCurrency($arPrice['DISCOUNT']['VALUE'], $arPrice['DISCOUNT']['CURRENCY'], $arPrice['PRICE']['CURRENCY']);
                    }
                } else {
                    $currentDiscount = $currentPrice * $arPrice['DISCOUNT']['VALUE'] / 100.0;

                    if ((float) $arPrice['DISCOUNT']['MAX_DISCOUNT'] > 0) {
                        if ($arPrice['DISCOUNT']['CURRENCY'] === $arPrice['PRICE']['CURRENCY']) {
                            $maxDiscount = $arPrice['DISCOUNT']['MAX_DISCOUNT'];
                        } else {
                            $maxDiscount = CCurrencyRates::ConvertCurrency($arPrice['DISCOUNT']['MAX_DISCOUNT'], $arPrice['DISCOUNT']['CURRENCY'], $arPrice['PRICE']['CURRENCY']);
                        }

                        if ($currentDiscount > $maxDiscount) {
                            $currentDiscount = $maxDiscount;
                        }
                    }
                }

                if ('S' === $arPrice['DISCOUNT']['VALUE_TYPE']) {
                    $currentDiscount_tmp = $currentPrice - $currentDiscount;
                    $currentPrice = $currentDiscount;
                    $currentDiscount = $currentDiscount_tmp;
                } else {
                    $currentPrice -= $currentDiscount;
                }
            }

            if (empty($arPrice['PRICE']['CATALOG_GROUP_NAME'])) {
                if (!empty($arPrice['PRICE']['CATALOG_GROUP_ID'])) {
                    $rsCatGroups = CCatalogGroup::GetList([], ['ID' => $arPrice['PRICE']['CATALOG_GROUP_ID']], false, ['nTopCount' => 1], ['ID', 'NAME', 'NAME_LANG']);
                    if ($arCatGroup = $rsCatGroups->Fetch()) {
                        $arPrice['PRICE']['CATALOG_GROUP_NAME'] = (!empty($arCatGroup['NAME_LANG']) ? $arCatGroup['NAME_LANG'] : $arCatGroup['NAME']);
                    }
                }
            }
        } else {
            $currentPrice = 0.0;
            $currentDiscount = 0.0;
        }
    }

    $arResult = [
        'PREVIEW_PICTURE' => $arProduct['PREVIEW_PICTURE'],
        'DETAIL_PICTURE' => $arProduct['DETAIL_PICTURE'],
        'PRODUCT_PRICE_ID' => $arPrice['PRICE']['ID'],
        'PRICE' => $currentPrice,
        'VAT_RATE' => $arPrice['PRICE']['VAT_RATE'],
        'CURRENCY' => $arPrice['PRICE']['CURRENCY'],
        'DISCOUNT_PRICE' => $currentDiscount,
        'NAME' => $arProduct['~NAME'],
        'DETAIL_PAGE_URL' => $arProduct['~DETAIL_PAGE_URL'],
        'NOTES' => $arPrice['PRICE']['CATALOG_GROUP_NAME'],
    ];

    if ($UserID > 0) {
        CCatalogDiscountSave::ClearDiscountUserID();
    }

    return $arResult;
}

/**
 * @deprecated deprecated since catalog 12.5.6
 * @see CCatalogDiscountCoupon::CouponOneOrderDisable()
 *
 * @param mixed $intOrderID
 */
function CatalogDeactivateOneTimeCoupons($intOrderID = 0)
{
    CCatalogDiscountCoupon::CouponOneOrderDisable($intOrderID);
}

function CatalogPayOrderCallback($productID, $userID, $bPaid, $orderID)
{
    global $DB;
    global $USER;

    $productID = (int) $productID;
    $userID = (int) $userID;
    $bPaid = ($bPaid ? true : false);
    $orderID = (int) $orderID;

    if ($userID <= 0) {
        return false;
    }

    $dbIBlockElement = CIBlockElement::GetList(
        [],
        [
            'ID' => $productID,
            'ACTIVE' => 'Y',
            'ACTIVE_DATE' => 'Y',
            'CHECK_PERMISSIONS' => 'N',
        ],
        false,
        false,
        ['ID', 'IBLOCK_ID', 'NAME', 'DETAIL_PAGE_URL']
    );
    if ($arIBlockElement = $dbIBlockElement->GetNext()) {
        $arCatalog = CCatalog::GetByID($arIBlockElement['IBLOCK_ID']);
        if ('Y' === $arCatalog['SUBSCRIPTION']) {
            $arProduct = CCatalogProduct::GetByID($productID);

            if ($bPaid) {
                if ('E' === CIBlock::GetArrayByID($arIBlockElement['IBLOCK_ID'], 'RIGHTS_MODE')) {
                    $arUserRights = CIBlockElementRights::GetUserOperations($productID, $userID);
                    if (empty($arUserRights)) {
                        return false;
                    }
                    if (!is_array($arUserRights) || !array_key_exists('element_read', $arUserRights)) {
                        return false;
                    }
                } else {
                    if ('R' > CIBlock::GetPermission($arIBlockElement['IBLOCK_ID'], $userID)) {
                        return false;
                    }
                }

                $arUserGroups = [];
                $arTmp = [];
                $ind = -1;
                $curTime = time();
                $dbProductGroups = CCatalogProductGroups::GetList(
                    [],
                    ['PRODUCT_ID' => $productID],
                    false,
                    false,
                    ['GROUP_ID', 'ACCESS_LENGTH', 'ACCESS_LENGTH_TYPE']
                );
                while ($arProductGroups = $dbProductGroups->Fetch()) {
                    ++$ind;

                    $arProductGroups['GROUP_ID'] = (int) $arProductGroups['GROUP_ID'];
                    $accessType = $arProductGroups['ACCESS_LENGTH_TYPE'];
                    $accessLength = (int) $arProductGroups['ACCESS_LENGTH'];

                    $accessVal = 0;
                    if (0 < $accessLength) {
                        if (CCatalogProduct::TIME_PERIOD_HOUR === $accessType) {
                            $accessVal = mktime(date('H') + $accessLength, date('i'), date('s'), date('m'), date('d'), date('Y'));
                        } elseif (CCatalogProduct::TIME_PERIOD_DAY === $accessType) {
                            $accessVal = mktime(date('H'), date('i'), date('s'), date('m'), date('d') + $accessLength, date('Y'));
                        } elseif (CCatalogProduct::TIME_PERIOD_WEEK === $accessType) {
                            $accessVal = mktime(date('H'), date('i'), date('s'), date('m'), date('d') + 7 * $accessLength, date('Y'));
                        } elseif (CCatalogProduct::TIME_PERIOD_MONTH === $accessType) {
                            $accessVal = mktime(date('H'), date('i'), date('s'), date('m') + $accessLength, date('d'), date('Y'));
                        } elseif (CCatalogProduct::TIME_PERIOD_QUART === $accessType) {
                            $accessVal = mktime(date('H'), date('i'), date('s'), date('m') + 3 * $accessLength, date('d'), date('Y'));
                        } elseif (CCatalogProduct::TIME_PERIOD_SEMIYEAR === $accessType) {
                            $accessVal = mktime(date('H'), date('i'), date('s'), date('m') + 6 * $accessLength, date('d'), date('Y'));
                        } elseif (CCatalogProduct::TIME_PERIOD_YEAR === $accessType) {
                            $accessVal = mktime(date('H'), date('i'), date('s'), date('m'), date('d'), date('Y') + $accessLength);
                        } elseif (CCatalogProduct::TIME_PERIOD_DOUBLE_YEAR === $accessType) {
                            $accessVal = mktime(date('H'), date('i'), date('s'), date('m'), date('d'), date('Y') + 2 * $accessLength);
                        }
                    }

                    $arUserGroups[$ind] = [
                        'GROUP_ID' => $arProductGroups['GROUP_ID'],
                        'DATE_ACTIVE_FROM' => date($DB->DateFormatToPHP(CLang::GetDateFormat('FULL', SITE_ID)), $curTime),
                        'DATE_ACTIVE_TO' => (0 < $accessLength ? date($DB->DateFormatToPHP(CLang::GetDateFormat('FULL', SITE_ID)), $accessVal) : false),
                    ];

                    $arTmp[$arProductGroups['GROUP_ID']] = $ind;
                }

                if (!empty($arUserGroups)) {
                    $dbOldGroups = CUser::GetUserGroupEx($userID);
                    while ($arOldGroups = $dbOldGroups->Fetch()) {
                        $arOldGroups['GROUP_ID'] = (int) $arOldGroups['GROUP_ID'];
                        if (array_key_exists($arOldGroups['GROUP_ID'], $arTmp)) {
                            if ('' === $arOldGroups['DATE_ACTIVE_FROM']) {
                                $arUserGroups[$arTmp[$arOldGroups['GROUP_ID']]]['DATE_ACTIVE_FROM'] = false;
                            } else {
                                $oldDate = CDatabase::FormatDate($arOldGroups['DATE_ACTIVE_FROM'], CSite::GetDateFormat('SHORT', SITE_ID), 'YYYYMMDDHHMISS');
                                $newDate = CDatabase::FormatDate($arUserGroups[$arTmp[$arOldGroups['GROUP_ID']]]['DATE_ACTIVE_FROM'], CSite::GetDateFormat('SHORT', SITE_ID), 'YYYYMMDDHHMISS');
                                if ($oldDate > $newDate) {
                                    $arUserGroups[$arTmp[$arOldGroups['GROUP_ID']]]['DATE_ACTIVE_FROM'] = $arOldGroups['DATE_ACTIVE_FROM'];
                                }
                            }

                            if ('' === $arOldGroups['DATE_ACTIVE_TO']) {
                                $arUserGroups[$arTmp[$arOldGroups['GROUP_ID']]]['DATE_ACTIVE_TO'] = false;
                            } elseif (false !== $arUserGroups[$arTmp[$arOldGroups['GROUP_ID']]]['DATE_ACTIVE_TO']) {
                                $oldDate = CDatabase::FormatDate($arOldGroups['DATE_ACTIVE_TO'], CSite::GetDateFormat('SHORT', SITE_ID), 'YYYYMMDDHHMISS');
                                $newDate = CDatabase::FormatDate($arUserGroups[$arTmp[$arOldGroups['GROUP_ID']]]['DATE_ACTIVE_TO'], CSite::GetDateFormat('SHORT', SITE_ID), 'YYYYMMDDHHMISS');
                                if ($oldDate > $newDate) {
                                    $arUserGroups[$arTmp[$arOldGroups['GROUP_ID']]]['DATE_ACTIVE_TO'] = $arOldGroups['DATE_ACTIVE_TO'];
                                }
                            }
                        } else {
                            ++$ind;

                            $arUserGroups[$ind] = [
                                'GROUP_ID' => $arOldGroups['GROUP_ID'],
                                'DATE_ACTIVE_FROM' => $arOldGroups['DATE_ACTIVE_FROM'],
                                'DATE_ACTIVE_TO' => $arOldGroups['DATE_ACTIVE_TO'],
                            ];
                        }
                    }

                    CUser::SetUserGroup($userID, $arUserGroups);
                    if (CCatalog::IsUserExists()) {
                        if ((int) $USER->GetID() === $userID) {
                            $arUserGroupsTmp = [];
                            foreach ($arUserGroups as &$arOneGroup) {
                                $arUserGroupsTmp[] = $arOneGroup['GROUP_ID'];
                            }
                            if (isset($arOneGroup)) {
                                unset($arOneGroup);
                            }

                            $USER->SetUserGroupArray($arUserGroupsTmp);
                        }
                    }
                }
            } else {
                $arUserGroups = [];
                $ind = -1;
                $arTmp = [];

                $dbOldGroups = CUser::GetUserGroupEx($userID);
                while ($arOldGroups = $dbOldGroups->Fetch()) {
                    ++$ind;
                    $arOldGroups['GROUP_ID'] = (int) $arOldGroups['GROUP_ID'];
                    $arUserGroups[$ind] = [
                        'GROUP_ID' => $arOldGroups['GROUP_ID'],
                        'DATE_ACTIVE_FROM' => $arOldGroups['DATE_ACTIVE_FROM'],
                        'DATE_ACTIVE_TO' => $arOldGroups['DATE_ACTIVE_FROM'],
                    ];

                    $arTmp[$arOldGroups['GROUP_ID']] = $ind;
                }

                $bNeedUpdate = false;
                $dbProductGroups = CCatalogProductGroups::GetList(
                    [],
                    ['PRODUCT_ID' => $productID],
                    false,
                    false,
                    ['GROUP_ID']
                );
                while ($arProductGroups = $dbProductGroups->Fetch()) {
                    $arProductGroups['GROUP_ID'] = (int) $arProductGroups['GROUP_ID'];
                    if (array_key_exists($arProductGroups['GROUP_ID'], $arTmp)) {
                        unset($arUserGroups[$arProductGroups['GROUP_ID']]);
                        $bNeedUpdate = true;
                    }
                }

                if ($bNeedUpdate) {
                    CUser::SetUserGroup($userID, $arUserGroups);

                    if (CCatalog::IsUserExists()) {
                        if ((int) $USER->GetID() === $userID) {
                            $arUserGroupsTmp = [];
                            foreach ($arUserGroups as &$arOneGroup) {
                                $arUserGroupsTmp[] = $arOneGroup['GROUP_ID'];
                            }
                            if (isset($arOneGroup)) {
                                unset($arOneGroup);
                            }

                            $USER->SetUserGroupArray($arUserGroupsTmp);
                        }
                    }
                }
            }

            if ('S' !== $arProduct['PRICE_TYPE']) {
                if ($bPaid) {
                    $recurType = $arProduct['RECUR_SCHEME_TYPE'];
                    $recurLength = (int) $arProduct['RECUR_SCHEME_LENGTH'];

                    $recurSchemeVal = 0;
                    if (CCatalogProduct::TIME_PERIOD_HOUR === $recurType) {
                        $recurSchemeVal = mktime(date('H') + $recurLength, date('i'), date('s'), date('m'), date('d'), date('Y'));
                    } elseif (CCatalogProduct::TIME_PERIOD_DAY === $recurType) {
                        $recurSchemeVal = mktime(date('H'), date('i'), date('s'), date('m'), date('d') + $recurLength, date('Y'));
                    } elseif (CCatalogProduct::TIME_PERIOD_WEEK === $recurType) {
                        $recurSchemeVal = mktime(date('H'), date('i'), date('s'), date('m'), date('d') + 7 * $recurLength, date('Y'));
                    } elseif (CCatalogProduct::TIME_PERIOD_MONTH === $recurType) {
                        $recurSchemeVal = mktime(date('H'), date('i'), date('s'), date('m') + $recurLength, date('d'), date('Y'));
                    } elseif (CCatalogProduct::TIME_PERIOD_QUART === $recurType) {
                        $recurSchemeVal = mktime(date('H'), date('i'), date('s'), date('m') + 3 * $recurLength, date('d'), date('Y'));
                    } elseif (CCatalogProduct::TIME_PERIOD_SEMIYEAR === $recurType) {
                        $recurSchemeVal = mktime(date('H'), date('i'), date('s'), date('m') + 6 * $recurLength, date('d'), date('Y'));
                    } elseif (CCatalogProduct::TIME_PERIOD_YEAR === $recurType) {
                        $recurSchemeVal = mktime(date('H'), date('i'), date('s'), date('m'), date('d'), date('Y') + $recurLength);
                    } elseif (CCatalogProduct::TIME_PERIOD_DOUBLE_YEAR === $recurType) {
                        $recurSchemeVal = mktime(date('H'), date('i'), date('s'), date('m'), date('d'), date('Y') + 2 * $recurLength);
                    }

                    $arFields = [
                        'USER_ID' => $userID,
                        'MODULE' => 'catalog',
                        'PRODUCT_ID' => $productID,
                        'PRODUCT_NAME' => $arIBlockElement['~NAME'],
                        'PRODUCT_URL' => $arIBlockElement['~DETAIL_PAGE_URL'],
                        'PRODUCT_PRICE_ID' => false,
                        'PRICE_TYPE' => $arProduct['PRICE_TYPE'],
                        'RECUR_SCHEME_TYPE' => $recurType,
                        'RECUR_SCHEME_LENGTH' => $recurLength,
                        'WITHOUT_ORDER' => $arProduct['WITHOUT_ORDER'],
                        'PRICE' => false,
                        'CURRENCY' => false,
                        'CANCELED' => 'N',
                        'CANCELED_REASON' => false,
                        'PRODUCT_PROVIDER_CLASS' => 'CCatalogProductProvider',
                        'DESCRIPTION' => false,
                        'PRIOR_DATE' => false,
                        'NEXT_DATE' => date(
                            $DB->DateFormatToPHP(CLang::GetDateFormat('FULL', SITE_ID)),
                            $recurSchemeVal
                        ),
                    ];

                    return $arFields;
                }
            }
        }

        return true;
    }

    return false;
}

function CatalogRecurringCallback($productID, $userID)
{
    global $APPLICATION;
    global $DB;

    $productID = (int) $productID;
    if ($productID <= 0) {
        return false;
    }

    $userID = (int) $userID;
    if ($userID <= 0) {
        return false;
    }

    $arProduct = CCatalogProduct::GetByID($productID);
    if (!$arProduct) {
        $APPLICATION->ThrowException(str_replace('#ID#', $productID, Loc::getMessage('I_NO_PRODUCT')), 'NO_PRODUCT');

        return false;
    }

    if ('T' === $arProduct['PRICE_TYPE']) {
        $arProduct = CCatalogProduct::GetByID($arProduct['TRIAL_PRICE_ID']);
        if (!$arProduct) {
            $APPLICATION->ThrowException(str_replace('#TRIAL_ID#', $productID, str_replace('#ID#', $arProduct['TRIAL_PRICE_ID'], Loc::getMessage('I_NO_TRIAL_PRODUCT'))), 'NO_PRODUCT_TRIAL');

            return false;
        }
    }
    $productID = (int) $arProduct['ID'];

    if ('R' !== $arProduct['PRICE_TYPE']) {
        $APPLICATION->ThrowException(str_replace('#ID#', $productID, Loc::getMessage('I_PRODUCT_NOT_SUBSCR')), 'NO_IBLOCK_SUBSCR');

        return false;
    }

    $dbIBlockElement = CIBlockElement::GetList(
        [],
        [
            'ID' => $productID,
            'ACTIVE' => 'Y',
            'ACTIVE_DATE' => 'Y',
            'CHECK_PERMISSIONS' => 'N',
        ],
        false,
        false,
        ['ID', 'IBLOCK_ID', 'NAME', 'DETAIL_PAGE_URL']
    );
    if (!($arIBlockElement = $dbIBlockElement->GetNext())) {
        $APPLICATION->ThrowException(str_replace('#ID#', $productID, Loc::getMessage('I_NO_IBLOCK_ELEM')), 'NO_IBLOCK_ELEMENT');

        return false;
    }
    if ('E' === CIBlock::GetArrayByID($arIBlockElement['IBLOCK_ID'], 'RIGHTS_MODE')) {
        $arUserRights = CIBlockElementRights::GetUserOperations($productID, $userID);
        if (empty($arUserRights)) {
            $APPLICATION->ThrowException(str_replace('#ID#', $productID, Loc::getMessage('I_NO_IBLOCK_ELEM')), 'NO_IBLOCK_ELEMENT');

            return false;
        }
        if (!is_array($arUserRights) || !array_key_exists('element_read', $arUserRights)) {
            $APPLICATION->ThrowException(str_replace('#ID#', $productID, Loc::getMessage('I_NO_IBLOCK_ELEM')), 'NO_IBLOCK_ELEMENT');

            return false;
        }
    } else {
        if ('R' > CIBlock::GetPermission($arIBlockElement['IBLOCK_ID'], $userID)) {
            $APPLICATION->ThrowException(str_replace('#ID#', $productID, Loc::getMessage('I_NO_IBLOCK_ELEM')), 'NO_IBLOCK_ELEMENT');

            return false;
        }
    }

    $arCatalog = CCatalog::GetByID($arIBlockElement['IBLOCK_ID']);
    if ('Y' !== $arCatalog['SUBSCRIPTION']) {
        $APPLICATION->ThrowException(str_replace('#ID#', $arIBlockElement['IBLOCK_ID'], Loc::getMessage('I_CATALOG_NOT_SUBSCR')), 'NOT_SUBSCRIPTION');

        return false;
    }

    if ('Y' !== $arProduct['CAN_BUY_ZERO'] && ('Y' === $arProduct['QUANTITY_TRACE'] && (float) $arProduct['QUANTITY'] <= 0)) {
        $APPLICATION->ThrowException(str_replace('#ID#', $productID, Loc::getMessage('I_PRODUCT_SOLD')), 'PRODUCT_END');

        return false;
    }

    $arUserGroups = CUser::GetUserGroup($userID);
    $arUserGroups = array_values(array_unique($arUserGroups));

    CCatalogDiscountSave::Disable();

    $arPrice = CCatalogProduct::GetOptimalPrice($productID, 1, $arUserGroups, 'Y');
    if (empty($arPrice)) {
        if ($nearestQuantity = CCatalogProduct::GetNearestQuantityPrice($productID, 1, $arUserGroups)) {
            $quantity = $nearestQuantity;
            $arPrice = CCatalogProduct::GetOptimalPrice($productID, $quantity, $arUserGroups, 'Y');
        }
    }

    CCatalogDiscountSave::Enable();

    if (empty($arPrice)) {
        return false;
    }

    $currentPrice = $arPrice['PRICE']['PRICE'];
    $currentDiscount = 0.0;

    // SIGURD: logic change. see mantiss 5036.
    // discount applied to a final price with VAT already included.
    if ((float) $arPrice['PRICE']['VAT_RATE'] > 0 && 'Y' !== $arPrice['PRICE']['VAT_INCLUDED']) {
        $currentPrice *= (1 + $arPrice['PRICE']['VAT_RATE']);
    }

    $arDiscountList = [];

    if (!empty($arPrice['DISCOUNT_LIST'])) {
        $dblStartPrice = $currentPrice;

        foreach ($arPrice['DISCOUNT_LIST'] as &$arOneDiscount) {
            switch ($arOneDiscount['VALUE_TYPE']) {
                case CCatalogDiscount::TYPE_FIX:
                    if ($arOneDiscount['CURRENCY'] === $arPrice['PRICE']['CURRENCY']) {
                        $currentDiscount = $arOneDiscount['VALUE'];
                    } else {
                        $currentDiscount = CCurrencyRates::ConvertCurrency($arOneDiscount['VALUE'], $arOneDiscount['CURRENCY'], $arPrice['PRICE']['CURRENCY']);
                    }
                    $currentPrice -= $currentDiscount;

                    break;

                case CCatalogDiscount::TYPE_PERCENT:
                    $currentDiscount = $currentPrice * $arOneDiscount['VALUE'] / 100.0;
                    if (0 < $arOneDiscount['MAX_DISCOUNT']) {
                        if ($arOneDiscount['CURRENCY'] === $arPrice['PRICE']['CURRENCY']) {
                            $dblMaxDiscount = $arOneDiscount['MAX_DISCOUNT'];
                        } else {
                            $dblMaxDiscount = CCurrencyRates::ConvertCurrency($arOneDiscount['MAX_DISCOUNT'], $arOneDiscount['CURRENCY'], $arPrice['PRICE']['CURRENCY']);
                        }
                        if ($currentDiscount > $dblMaxDiscount) {
                            $currentDiscount = $dblMaxDiscount;
                        }
                    }
                    $currentPrice -= $currentDiscount;

                    break;

                case CCatalogDiscount::TYPE_SALE:
                    if ($arOneDiscount['CURRENCY'] === $arPrice['PRICE']['CURRENCY']) {
                        $currentPrice = $arOneDiscount['VALUE'];
                    } else {
                        $currentPrice = CCurrencyRates::ConvertCurrency($arOneDiscount['VALUE'], $arOneDiscount['CURRENCY'], $arPrice['PRICE']['CURRENCY']);
                    }

                    break;
            }

            $arOneList = [
                'ID' => $arOneDiscount['ID'],
                'NAME' => $arOneDiscount['NAME'],
                'COUPON' => '',
                'MODULE_ID' => 'catalog',
            ];

            if ($arOneDiscount['COUPON']) {
                $arOneList['COUPON'] = $arOneDiscount['COUPON'];
            }
            $arDiscountList[] = $arOneList;
        }
        if (isset($arOneDiscount)) {
            unset($arOneDiscount);
        }

        $currentDiscount = $dblStartPrice - $currentPrice;
    }

    $recurType = $arProduct['RECUR_SCHEME_TYPE'];
    $recurLength = (int) $arProduct['RECUR_SCHEME_LENGTH'];

    $recurSchemeVal = 0;
    if (CCatalogProduct::TIME_PERIOD_HOUR === $recurType) {
        $recurSchemeVal = mktime(date('H') + $recurLength, date('i'), date('s'), date('m'), date('d'), date('Y'));
    } elseif (CCatalogProduct::TIME_PERIOD_DAY === $recurType) {
        $recurSchemeVal = mktime(date('H'), date('i'), date('s'), date('m'), date('d') + $recurLength, date('Y'));
    } elseif (CCatalogProduct::TIME_PERIOD_WEEK === $recurType) {
        $recurSchemeVal = mktime(date('H'), date('i'), date('s'), date('m'), date('d') + 7 * $recurLength, date('Y'));
    } elseif (CCatalogProduct::TIME_PERIOD_MONTH === $recurType) {
        $recurSchemeVal = mktime(date('H'), date('i'), date('s'), date('m') + $recurLength, date('d'), date('Y'));
    } elseif (CCatalogProduct::TIME_PERIOD_QUART === $recurType) {
        $recurSchemeVal = mktime(date('H'), date('i'), date('s'), date('m') + 3 * $recurLength, date('d'), date('Y'));
    } elseif (CCatalogProduct::TIME_PERIOD_SEMIYEAR === $recurType) {
        $recurSchemeVal = mktime(date('H'), date('i'), date('s'), date('m') + 6 * $recurLength, date('d'), date('Y'));
    } elseif (CCatalogProduct::TIME_PERIOD_YEAR === $recurType) {
        $recurSchemeVal = mktime(date('H'), date('i'), date('s'), date('m'), date('d'), date('Y') + $recurLength);
    } elseif (CCatalogProduct::TIME_PERIOD_DOUBLE_YEAR === $recurType) {
        $recurSchemeVal = mktime(date('H'), date('i'), date('s'), date('m'), date('d'), date('Y') + 2 * $recurLength);
    }

    $arResult = [
        'WEIGHT' => (float) $arProduct['WEIGHT'],
        'DIMENSIONS' => serialize([
            'WIDTH' => $arProduct['WIDTH'],
            'HEIGHT' => $arProduct['HEIGHT'],
            'LENGTH' => $arProduct['LENGTH'],
        ]),
        'VAT_RATE' => $arPrice['PRICE']['VAT_RATE'],
        'QUANTITY' => 1,
        'PRICE' => $currentPrice,
        'WITHOUT_ORDER' => $arProduct['WITHOUT_ORDER'],
        'PRODUCT_ID' => $productID,
        'PRODUCT_NAME' => $arIBlockElement['~NAME'],
        'PRODUCT_URL' => $arIBlockElement['~DETAIL_PAGE_URL'],
        'PRODUCT_PRICE_ID' => $arPrice['PRICE']['ID'],
        'CURRENCY' => $arPrice['PRICE']['CURRENCY'],
        'NAME' => $arIBlockElement['NAME'],
        'MODULE' => 'catalog',
        'PRODUCT_PROVIDER_CLASS' => 'CCatalogProductProvider',
        'CATALOG_GROUP_NAME' => $arPrice['PRICE']['CATALOG_GROUP_NAME'],
        'DETAIL_PAGE_URL' => $arIBlockElement['~DETAIL_PAGE_URL'],
        'PRICE_TYPE' => $arProduct['PRICE_TYPE'],
        'RECUR_SCHEME_TYPE' => $arProduct['RECUR_SCHEME_TYPE'],
        'RECUR_SCHEME_LENGTH' => $arProduct['RECUR_SCHEME_LENGTH'],
        'PRODUCT_XML_ID' => $arIBlockElement['~XML_ID'],
        'TYPE' => (CCatalogProduct::TYPE_SET === $arProduct['TYPE']) ? CCatalogProductSet::TYPE_SET : null,
        'NEXT_DATE' => date(
            $DB->DateFormatToPHP(CLang::GetDateFormat('FULL', SITE_ID)),
            $recurSchemeVal
        ),
    ];
    if (!empty($arPrice['DISCOUNT_LIST'])) {
        $arResult['DISCOUNT_LIST'] = $arDiscountList;
    }

    return $arResult;
}

function CatalogBasketCancelCallback($PRODUCT_ID, $QUANTITY, $bCancel)
{
    $PRODUCT_ID = (int) $PRODUCT_ID;
    $QUANTITY = (float) $QUANTITY;
    $bCancel = ($bCancel ? true : false);

    if ($bCancel) {
        CCatalogProduct::QuantityTracer($PRODUCT_ID, -$QUANTITY);
    } else {
        CCatalogProduct::QuantityTracer($PRODUCT_ID, $QUANTITY);
    }
}

/**
 * @param int       $PRICE_ID
 * @param float|int $QUANTITY
 * @param array     $arRewriteFields
 * @param array     $arProductParams
 *
 * @return bool|int
 */
function Add2Basket($PRICE_ID, $QUANTITY = 1, $arRewriteFields = [], $arProductParams = [])
{
    global $APPLICATION;

    $PRICE_ID = (int) $PRICE_ID;
    if ($PRICE_ID <= 0) {
        $APPLICATION->ThrowException(Loc::getMessage('CATALOG_PRODUCT_PRICE_NOT_FOUND'), 'NO_PRODUCT_PRICE');

        return false;
    }
    $QUANTITY = (float) $QUANTITY;
    if ($QUANTITY <= 0) {
        $QUANTITY = 1;
    }

    if (!Loader::includeModule('sale')) {
        $APPLICATION->ThrowException(Loc::getMessage('CATALOG_ERR_NO_SALE_MODULE'), 'NO_SALE_MODULE');

        return false;
    }
    if (Loader::includeModule('statistic') && isset($_SESSION['SESS_SEARCHER_ID']) && (int) $_SESSION['SESS_SEARCHER_ID'] > 0) {
        $APPLICATION->ThrowException(Loc::getMessage('CATALOG_ERR_SESS_SEARCHER'), 'SESS_SEARCHER');

        return false;
    }

    $rsPrices = CPrice::GetListEx(
        [],
        ['ID' => $PRICE_ID],
        false,
        false,
        [
            'ID',
            'PRODUCT_ID',
            'PRICE',
            'CURRENCY',
            'CATALOG_GROUP_ID',
        ]
    );
    if (!($arPrice = $rsPrices->Fetch())) {
        $APPLICATION->ThrowException(Loc::getMessage('CATALOG_PRODUCT_PRICE_NOT_FOUND'), 'NO_PRODUCT_PRICE');

        return false;
    }
    $arPrice['CATALOG_GROUP_NAME'] = '';
    $rsCatGroups = CCatalogGroup::GetListEx(
        [],
        ['ID' => $arPrice['CATALOG_GROUP_ID']],
        false,
        false,
        [
            'ID',
            'NAME',
            'NAME_LANG',
        ]
    );
    if ($arCatGroup = $rsCatGroups->Fetch()) {
        $arPrice['CATALOG_GROUP_NAME'] = (!empty($arCatGroup['NAME_LANG']) ? $arCatGroup['NAME_LANG'] : $arCatGroup['NAME']);
    }
    $rsProducts = CCatalogProduct::GetList(
        [],
        ['ID' => $arPrice['PRODUCT_ID']],
        false,
        false,
        [
            'ID',
            'CAN_BUY_ZERO',
            'QUANTITY_TRACE',
            'QUANTITY',
            'WEIGHT',
            'WIDTH',
            'HEIGHT',
            'LENGTH',
            'TYPE',
            'MEASURE',
        ]
    );
    if (!($arCatalogProduct = $rsProducts->Fetch())) {
        $APPLICATION->ThrowException(Loc::getMessage('CATALOG_ERR_NO_PRODUCT'), 'NO_PRODUCT');

        return false;
    }
    if (
        (Catalog\ProductTable::TYPE_SKU === $arCatalogProduct['TYPE'] || Catalog\ProductTable::TYPE_EMPTY_SKU === $arCatalogProduct['TYPE'])
        && 'Y' !== (string) Main\Config\Option::get('catalog', 'show_catalog_tab_with_offers')
    ) {
        $APPLICATION->ThrowException(Loc::getMessage('CATALOG_ERR_CANNOT_ADD_SKU'), 'NO_PRODUCT');

        return false;
    }
    $arCatalogProduct['MEASURE'] = (int) $arCatalogProduct['MEASURE'];
    $arCatalogProduct['MEASURE_NAME'] = '';
    $arCatalogProduct['MEASURE_CODE'] = 0;
    if ($arCatalogProduct['MEASURE'] <= 0) {
        $arMeasure = CCatalogMeasure::getDefaultMeasure(true, true);
        $arCatalogProduct['MEASURE_NAME'] = $arMeasure['~SYMBOL_RUS'];
        $arCatalogProduct['MEASURE_CODE'] = $arMeasure['CODE'];
    } else {
        $rsMeasures = CCatalogMeasure::getList(
            [],
            ['ID' => $arCatalogProduct['MEASURE']],
            false,
            false,
            ['ID', 'SYMBOL_RUS', 'CODE']
        );
        if ($arMeasure = $rsMeasures->GetNext()) {
            $arCatalogProduct['MEASURE_NAME'] = $arMeasure['~SYMBOL_RUS'];
            $arCatalogProduct['MEASURE_CODE'] = $arMeasure['CODE'];
        }
    }

    $dblQuantity = (float) $arCatalogProduct['QUANTITY'];
    $intQuantity = (int) $arCatalogProduct['QUANTITY'];
    $boolQuantity = ('Y' !== $arCatalogProduct['CAN_BUY_ZERO'] && 'Y' === $arCatalogProduct['QUANTITY_TRACE']);
    if ($boolQuantity && $dblQuantity <= 0) {
        $APPLICATION->ThrowException(Loc::getMessage('CATALOG_ERR_PRODUCT_RUN_OUT'), 'PRODUCT_RUN_OUT');

        return false;
    }

    $rsItems = CIBlockElement::GetList(
        [],
        [
            'ID' => $arPrice['PRODUCT_ID'],
            'ACTIVE' => 'Y',
            'ACTIVE_DATE' => 'Y',
            'CHECK_PERMISSIONS' => 'Y',
            'MIN_PERMISSION' => 'R',
        ],
        false,
        false,
        [
            'ID',
            'IBLOCK_ID',
            'NAME',
            'XML_ID',
            'DETAIL_PAGE_URL',
        ]
    );
    if (!($arProduct = $rsItems->GetNext())) {
        $APPLICATION->ThrowException(Loc::getMessage('CATALOG_ERR_NO_PRODUCT'), 'NO_PRODUCT');

        return false;
    }

    $arProps = [];

    $strIBlockXmlID = (string) CIBlock::GetArrayByID($arProduct['IBLOCK_ID'], 'XML_ID');
    if ('' !== $strIBlockXmlID) {
        $arProps[] = [
            'NAME' => 'Catalog XML_ID',
            'CODE' => 'CATALOG.XML_ID',
            'VALUE' => $strIBlockXmlID,
        ];
    }

    // add sku props
    $arParentSku = CCatalogSku::GetProductInfo($arProduct['ID'], $arProduct['IBLOCK_ID']);
    if (!empty($arParentSku)) {
        if (!str_contains($arProduct['~XML_ID'], '#')) {
            $parentIterator = Iblock\ElementTable::getList([
                'select' => ['ID', 'XML_ID'],
                'filter' => ['ID' => $arParentSku['ID']],
            ]);
            if ($parent = $parentIterator->fetch()) {
                $arProduct['~XML_ID'] = $parent['XML_ID'].'#'.$arProduct['~XML_ID'];
            }
            unset($parent, $parentIterator);
        }
    }

    if (!empty($arProductParams) && is_array($arProductParams)) {
        foreach ($arProductParams as &$arOneProductParams) {
            $arProps[] = [
                'NAME' => $arOneProductParams['NAME'],
                'CODE' => $arOneProductParams['CODE'],
                'VALUE' => $arOneProductParams['VALUE'],
                'SORT' => $arOneProductParams['SORT'],
            ];
        }
        unset($arOneProductParams);
    }

    $arProps[] = [
        'NAME' => 'Product XML_ID',
        'CODE' => 'PRODUCT.XML_ID',
        'VALUE' => $arProduct['~XML_ID'],
    ];

    $arFields = [
        'PRODUCT_ID' => $arPrice['PRODUCT_ID'],
        'PRODUCT_PRICE_ID' => $PRICE_ID,
        'BASE_PRICE' => $arPrice['PRICE'],
        'PRICE' => $arPrice['PRICE'],
        'DISCOUNT_PRICE' => 0,
        'CURRENCY' => $arPrice['CURRENCY'],
        'WEIGHT' => $arCatalogProduct['WEIGHT'],
        'DIMENSIONS' => serialize([
            'WIDTH' => $arCatalogProduct['WIDTH'],
            'HEIGHT' => $arCatalogProduct['HEIGHT'],
            'LENGTH' => $arCatalogProduct['LENGTH'],
        ]),
        'QUANTITY' => ($boolQuantity && $dblQuantity < $QUANTITY ? $dblQuantity : $QUANTITY),
        'LID' => SITE_ID,
        'DELAY' => 'N',
        'CAN_BUY' => 'Y',
        'NAME' => $arProduct['~NAME'],
        'MODULE' => 'catalog',
        'PRODUCT_PROVIDER_CLASS' => 'CCatalogProductProvider',
        'NOTES' => $arPrice['CATALOG_GROUP_NAME'],
        'DETAIL_PAGE_URL' => $arProduct['~DETAIL_PAGE_URL'],
        'CATALOG_XML_ID' => $strIBlockXmlID,
        'PRODUCT_XML_ID' => $arProduct['~XML_ID'],
        'PROPS' => $arProps,
        'TYPE' => (CCatalogProduct::TYPE_SET === $arCatalogProduct['TYPE']) ? CCatalogProductSet::TYPE_SET : null,
        'MEASURE_NAME' => $arCatalogProduct['MEASURE_NAME'],
        'MEASURE_CODE' => $arCatalogProduct['MEASURE_CODE'],
    ];

    if (!empty($arRewriteFields) && is_array($arRewriteFields)) {
        $arFields = array_merge($arFields, $arRewriteFields);
    }
    $result = CSaleBasket::Add($arFields);

    if ($result) {
        if (Loader::includeModule('statistic')) {
            CStatistic::Set_Event('eStore', 'add2basket', $arFields['PRODUCT_ID']);
        }
    }

    return $result;
}

/**
 * @param int        $PRODUCT_ID
 * @param float|int  $QUANTITY
 * @param array      $arRewriteFields
 * @param array|bool $arProductParams
 *
 * @return bool|int
 */
function Add2BasketByProductID($PRODUCT_ID, $QUANTITY = 1, $arRewriteFields = [], $arProductParams = false)
{
    // @global CMain $APPLICATION
    global $APPLICATION;

    // for old use
    if (false === $arProductParams) {
        $arProductParams = $arRewriteFields;
        $arRewriteFields = [];
    }

    $boolRewrite = (!empty($arRewriteFields) && is_array($arRewriteFields));

    if ($boolRewrite && isset($arRewriteFields['SUBSCRIBE']) && 'Y' === $arRewriteFields['SUBSCRIBE']) {
        return SubscribeProduct($PRODUCT_ID, $arRewriteFields, $arProductParams);
    }

    $PRODUCT_ID = (int) $PRODUCT_ID;
    if ($PRODUCT_ID <= 0) {
        $APPLICATION->ThrowException(Loc::getMessage('CATALOG_ERR_EMPTY_PRODUCT_ID'), 'EMPTY_PRODUCT_ID');

        return false;
    }

    $QUANTITY = (float) $QUANTITY;
    if ($QUANTITY <= 0) {
        $QUANTITY = 1;
    }

    if (!Loader::includeModule('sale')) {
        $APPLICATION->ThrowException(Loc::getMessage('CATALOG_ERR_NO_SALE_MODULE'), 'NO_SALE_MODULE');

        return false;
    }

    if (Loader::includeModule('statistic') && isset($_SESSION['SESS_SEARCHER_ID']) && (int) $_SESSION['SESS_SEARCHER_ID'] > 0) {
        $APPLICATION->ThrowException(Loc::getMessage('CATALOG_ERR_SESS_SEARCHER'), 'SESS_SEARCHER');

        return false;
    }

    $rsProducts = CCatalogProduct::GetList(
        [],
        ['ID' => $PRODUCT_ID],
        false,
        false,
        [
            'ID',
            'CAN_BUY_ZERO',
            'QUANTITY_TRACE',
            'QUANTITY',
            'WEIGHT',
            'WIDTH',
            'HEIGHT',
            'LENGTH',
            'TYPE',
            'MEASURE',
        ]
    );
    if (!($arCatalogProduct = $rsProducts->Fetch())) {
        $APPLICATION->ThrowException(Loc::getMessage('CATALOG_ERR_NO_PRODUCT'), 'NO_PRODUCT');

        return false;
    }
    if (
        (Catalog\ProductTable::TYPE_SKU === $arCatalogProduct['TYPE'] || Catalog\ProductTable::TYPE_EMPTY_SKU === $arCatalogProduct['TYPE'])
        && 'Y' !== (string) Main\Config\Option::get('catalog', 'show_catalog_tab_with_offers')
    ) {
        $APPLICATION->ThrowException(Loc::getMessage('CATALOG_ERR_CANNOT_ADD_SKU'), 'NO_PRODUCT');

        return false;
    }
    $arCatalogProduct['MEASURE'] = (int) $arCatalogProduct['MEASURE'];
    $arCatalogProduct['MEASURE_NAME'] = '';
    $arCatalogProduct['MEASURE_CODE'] = 0;
    if ($arCatalogProduct['MEASURE'] <= 0) {
        $arMeasure = CCatalogMeasure::getDefaultMeasure(true, true);
        $arCatalogProduct['MEASURE_NAME'] = $arMeasure['~SYMBOL_RUS'];
        $arCatalogProduct['MEASURE_CODE'] = $arMeasure['CODE'];
    } else {
        $rsMeasures = CCatalogMeasure::getList(
            [],
            ['ID' => $arCatalogProduct['MEASURE']],
            false,
            false,
            ['ID', 'SYMBOL_RUS', 'CODE']
        );
        if ($arMeasure = $rsMeasures->GetNext()) {
            $arCatalogProduct['MEASURE_NAME'] = $arMeasure['~SYMBOL_RUS'];
            $arCatalogProduct['MEASURE_CODE'] = $arMeasure['CODE'];
        }
    }

    $dblQuantity = (float) $arCatalogProduct['QUANTITY'];
    $intQuantity = (int) $arCatalogProduct['QUANTITY'];
    $boolQuantity = ('Y' !== $arCatalogProduct['CAN_BUY_ZERO'] && 'Y' === $arCatalogProduct['QUANTITY_TRACE']);
    if ($boolQuantity && $dblQuantity <= 0) {
        $APPLICATION->ThrowException(Loc::getMessage('CATALOG_ERR_PRODUCT_RUN_OUT'), 'PRODUCT_RUN_OUT');

        return false;
    }

    $rsItems = CIBlockElement::GetList(
        [],
        [
            'ID' => $PRODUCT_ID,
            'ACTIVE' => 'Y',
            'ACTIVE_DATE' => 'Y',
            'CHECK_PERMISSIONS' => 'Y',
            'MIN_PERMISSION' => 'R',
        ],
        false,
        false,
        [
            'ID',
            'IBLOCK_ID',
            'XML_ID',
            'NAME',
            'DETAIL_PAGE_URL',
        ]
    );
    if (!($arProduct = $rsItems->GetNext())) {
        $APPLICATION->ThrowException(Loc::getMessage('CATALOG_ERR_NO_IBLOCK_ELEMENT'), 'NO_IBLOCK_ELEMENT');

        return false;
    }

    $strCallbackFunc = '';
    $strProductProviderClass = 'CCatalogProductProvider';

    if ($boolRewrite) {
        if (isset($arRewriteFields['CALLBACK_FUNC'])) {
            $strCallbackFunc = $arRewriteFields['CALLBACK_FUNC'];
        }
        if (isset($arRewriteFields['PRODUCT_PROVIDER_CLASS'])) {
            $strProductProviderClass = $arRewriteFields['PRODUCT_PROVIDER_CLASS'];
        }
    }

    $arCallbackPrice = false;
    if (!empty($strProductProviderClass)) {
        if ($productProvider = CSaleBasket::GetProductProvider([
            'MODULE' => 'catalog',
            'PRODUCT_PROVIDER_CLASS' => $strProductProviderClass])
        ) {
            $providerParams = [
                'PRODUCT_ID' => $PRODUCT_ID,
                'QUANTITY' => $QUANTITY,
                'RENEWAL' => 'N',
            ];
            $arCallbackPrice = $productProvider::GetProductData($providerParams);
            unset($providerParams);
        }
    } elseif (!empty($strCallbackFunc)) {
        $arCallbackPrice = CSaleBasket::ExecuteCallbackFunction(
            $strCallbackFunc,
            'catalog',
            $PRODUCT_ID,
            $QUANTITY,
            'N'
        );
    }
    if (empty($arCallbackPrice) || !is_array($arCallbackPrice)) {
        $APPLICATION->ThrowException(Loc::getMessage('CATALOG_PRODUCT_PRICE_NOT_FOUND'), 'NO_PRODUCT_PRICE');

        return false;
    }

    if (isset($arCallbackPrice['RESULT_PRICE'])) {
        $arCallbackPrice['BASE_PRICE'] = $arCallbackPrice['RESULT_PRICE']['BASE_PRICE'];
        $arCallbackPrice['PRICE'] = $arCallbackPrice['RESULT_PRICE']['DISCOUNT_PRICE'];
        $arCallbackPrice['DISCOUNT_PRICE'] = $arCallbackPrice['RESULT_PRICE']['DISCOUNT'];
        $arCallbackPrice['CURRENCY'] = $arCallbackPrice['RESULT_PRICE']['CURRENCY'];
    } else {
        if (!isset($arCallbackPrice['BASE_PRICE'])) {
            $arCallbackPrice['BASE_PRICE'] = $arCallbackPrice['PRICE'] + $arCallbackPrice['DISCOUNT_PRICE'];
        }
    }

    $arProps = [];

    $strIBlockXmlID = (string) CIBlock::GetArrayByID($arProduct['IBLOCK_ID'], 'XML_ID');
    if ('' !== $strIBlockXmlID) {
        $arProps[] = [
            'NAME' => 'Catalog XML_ID',
            'CODE' => 'CATALOG.XML_ID',
            'VALUE' => $strIBlockXmlID,
        ];
    }

    // add sku props
    $arParentSku = CCatalogSku::GetProductInfo($PRODUCT_ID, $arProduct['IBLOCK_ID']);
    if (!empty($arParentSku)) {
        if (!str_contains($arProduct['~XML_ID'], '#')) {
            $parentIterator = Iblock\ElementTable::getList([
                'select' => ['ID', 'XML_ID'],
                'filter' => ['ID' => $arParentSku['ID']],
            ]);
            if ($parent = $parentIterator->fetch()) {
                $arProduct['~XML_ID'] = $parent['XML_ID'].'#'.$arProduct['~XML_ID'];
            }
            unset($parent, $parentIterator);
        }
    }

    if (!empty($arProductParams) && is_array($arProductParams)) {
        foreach ($arProductParams as &$arOneProductParams) {
            $arProps[] = [
                'NAME' => $arOneProductParams['NAME'],
                'CODE' => $arOneProductParams['CODE'],
                'VALUE' => $arOneProductParams['VALUE'],
                'SORT' => $arOneProductParams['SORT'],
            ];
        }
        unset($arOneProductParams);
    }

    $arProps[] = [
        'NAME' => 'Product XML_ID',
        'CODE' => 'PRODUCT.XML_ID',
        'VALUE' => $arProduct['~XML_ID'],
    ];

    $arFields = [
        'PRODUCT_ID' => $PRODUCT_ID,
        'PRODUCT_PRICE_ID' => $arCallbackPrice['PRODUCT_PRICE_ID'],
        'BASE_PRICE' => $arCallbackPrice['BASE_PRICE'],
        'PRICE' => $arCallbackPrice['PRICE'],
        'DISCOUNT_PRICE' => $arCallbackPrice['DISCOUNT_PRICE'],
        'CURRENCY' => $arCallbackPrice['CURRENCY'],
        'WEIGHT' => $arCatalogProduct['WEIGHT'],
        'DIMENSIONS' => serialize([
            'WIDTH' => $arCatalogProduct['WIDTH'],
            'HEIGHT' => $arCatalogProduct['HEIGHT'],
            'LENGTH' => $arCatalogProduct['LENGTH'],
        ]),
        'QUANTITY' => ($boolQuantity && $dblQuantity < $QUANTITY ? $dblQuantity : $QUANTITY),
        'LID' => SITE_ID,
        'DELAY' => 'N',
        'CAN_BUY' => 'Y',
        'NAME' => $arProduct['~NAME'],
        'MODULE' => 'catalog',
        'PRODUCT_PROVIDER_CLASS' => 'CCatalogProductProvider',
        'NOTES' => $arCallbackPrice['NOTES'],
        'DETAIL_PAGE_URL' => $arProduct['~DETAIL_PAGE_URL'],
        'CATALOG_XML_ID' => $strIBlockXmlID,
        'PRODUCT_XML_ID' => $arProduct['~XML_ID'],
        'VAT_INCLUDED' => $arCallbackPrice['VAT_INCLUDED'],
        'VAT_RATE' => $arCallbackPrice['VAT_RATE'],
        'PROPS' => $arProps,
        'TYPE' => (CCatalogProduct::TYPE_SET === $arCatalogProduct['TYPE']) ? CCatalogProductSet::TYPE_SET : null,
        'MEASURE_NAME' => $arCatalogProduct['MEASURE_NAME'],
        'MEASURE_CODE' => $arCatalogProduct['MEASURE_CODE'],
    ];

    if ($boolRewrite) {
        $arFields = array_merge($arFields, $arRewriteFields);
    }

    $result = CSaleBasket::Add($arFields);
    if ($result) {
        if (Loader::includeModule('statistic')) {
            CStatistic::Set_Event('sale2basket', 'catalog', $arFields['DETAIL_PAGE_URL']);
        }
    }

    return $result;
}

/**
 * @param int   $intProductID
 * @param array $arRewriteFields
 * @param array $arProductParams
 *
 * @return bool|int
 */
function SubscribeProduct($intProductID, $arRewriteFields = [], $arProductParams = [])
{
    global $USER, $APPLICATION;

    if (!CCatalog::IsUserExists()) {
        return false;
    }
    if (!$USER->IsAuthorized()) {
        return false;
    }
    $intUserID = (int) $USER->GetID();

    $intProductID = (int) $intProductID;
    if ($intProductID <= 0) {
        $APPLICATION->ThrowException(Loc::getMessage('CATALOG_ERR_EMPTY_PRODUCT_ID'), 'EMPTY_PRODUCT_ID');

        return false;
    }

    if (!Loader::includeModule('sale')) {
        $APPLICATION->ThrowException(Loc::getMessage('CATALOG_ERR_NO_SALE_MODULE'), 'NO_SALE_MODULE');

        return false;
    }

    if (Loader::includeModule('statistic') && isset($_SESSION['SESS_SEARCHER_ID']) && (int) $_SESSION['SESS_SEARCHER_ID'] > 0) {
        $APPLICATION->ThrowException(Loc::getMessage('CATALOG_ERR_SESS_SEARCHER'), 'SESS_SEARCHER');

        return false;
    }

    $rsProducts = CCatalogProduct::GetList(
        [],
        ['ID' => $intProductID],
        false,
        false,
        [
            'ID',
            'WEIGHT',
            'WIDTH',
            'HEIGHT',
            'LENGTH',
            'TYPE',
            'MEASURE',
            'SUBSCRIBE',
        ]
    );
    if (!($arCatalogProduct = $rsProducts->Fetch())) {
        $APPLICATION->ThrowException(Loc::getMessage('CATALOG_ERR_NO_PRODUCT'), 'NO_PRODUCT');

        return false;
    }

    if ('N' === $arCatalogProduct['SUBSCRIBE']) {
        $APPLICATION->ThrowException(Loc::getMessage('CATALOG_ERR_NO_SUBSCRIBE'), 'SUBSCRIBE');

        return false;
    }
    $arCatalogProduct['MEASURE'] = (int) $arCatalogProduct['MEASURE'];
    $arCatalogProduct['MEASURE_NAME'] = '';
    $arCatalogProduct['MEASURE_CODE'] = 0;
    if ($arCatalogProduct['MEASURE'] <= 0) {
        $arMeasure = CCatalogMeasure::getDefaultMeasure(true, true);
        $arCatalogProduct['MEASURE_NAME'] = $arMeasure['~SYMBOL_RUS'];
        $arCatalogProduct['MEASURE_CODE'] = $arMeasure['CODE'];
    } else {
        $rsMeasures = CCatalogMeasure::getList(
            [],
            ['ID' => $arCatalogProduct['MEASURE']],
            false,
            false,
            ['ID', 'SYMBOL_RUS', 'CODE']
        );
        if ($arMeasure = $rsMeasures->GetNext()) {
            $arCatalogProduct['MEASURE_NAME'] = $arMeasure['~SYMBOL_RUS'];
            $arCatalogProduct['MEASURE_CODE'] = $arMeasure['CODE'];
        }
    }

    $rsItems = CIBlockElement::GetList(
        [],
        [
            'ID' => $intProductID,
            'ACTIVE' => 'Y',
            'ACTIVE_DATE' => 'Y',
            'CHECK_PERMISSIONS' => 'Y',
            'MIN_PERMISSION' => 'R',
        ],
        false,
        false,
        [
            'ID',
            'IBLOCK_ID',
            'NAME',
            'XML_ID',
            'DETAIL_PAGE_URL',
        ]
    );
    if (!($arProduct = $rsItems->GetNext())) {
        return false;
    }

    $arParentSku = CCatalogSku::GetProductInfo($intProductID, $arProduct['IBLOCK_ID']);
    if (!empty($arParentSku)) {
        if (!str_contains($arProduct['~XML_ID'], '#')) {
            $parentIterator = Iblock\ElementTable::getList([
                'select' => ['ID', 'XML_ID'],
                'filter' => ['ID' => $arParentSku['ID']],
            ]);
            if ($parent = $parentIterator->fetch()) {
                $arProduct['~XML_ID'] = $parent['XML_ID'].'#'.$arProduct['~XML_ID'];
            }
            unset($parent, $parentIterator);
        }
    }

    $arPrice = [
        'BASE_PRICE' => 0,
        'PRICE' => 0.0,
        'DISCOUNT_PRICE' => 0,
        'CURRENCY' => CSaleLang::GetLangCurrency(SITE_ID),
        'VAT_RATE' => 0,
        'PRODUCT_PRICE_ID' => 0,
        'CATALOG_GROUP_NAME' => '',
    ];
    $arBuyerGroups = $USER->GetUserGroupArray();
    $arSubscrPrice = CCatalogProduct::GetOptimalPrice($intProductID, 1, $arBuyerGroups, 'N', [], SITE_ID, []);
    if (!empty($arSubscrPrice) && is_array($arSubscrPrice)) {
        $arPrice['BASE_PRICE'] = $arSubscrPrice['RESULT_PRICE']['BASE_PRICE'];
        $arPrice['PRICE'] = $arSubscrPrice['RESULT_PRICE']['DISCOUNT_PRICE'];
        $arPrice['DISCOUNT_PRICE'] = $arSubscrPrice['RESULT_PRICE']['DISCOUNT'];
        $arPrice['CURRENCY'] = $arSubscrPrice['RESULT_PRICE']['CURRENCY'];
        $arPrice['VAT_RATE'] = $arSubscrPrice['RESULT_PRICE']['VAT_RATE'];
        $arPrice['PRODUCT_PRICE_ID'] = $arSubscrPrice['PRICE']['ID'];
        $arPrice['CATALOG_GROUP_NAME'] = $arSubscrPrice['PRICE']['CATALOG_GROUP_NAME'];
    }

    $arProps = [];

    $strIBlockXmlID = (string) CIBlock::GetArrayByID($arProduct['IBLOCK_ID'], 'XML_ID');
    if ('' !== $strIBlockXmlID) {
        $arProps[] = [
            'NAME' => 'Catalog XML_ID',
            'CODE' => 'CATALOG.XML_ID',
            'VALUE' => $strIBlockXmlID,
        ];
    }

    if (!empty($arProductParams) && is_array($arProductParams)) {
        foreach ($arProductParams as &$arOneProductParams) {
            $arProps[] = [
                'NAME' => $arOneProductParams['NAME'],
                'CODE' => $arOneProductParams['CODE'],
                'VALUE' => $arOneProductParams['VALUE'],
                'SORT' => $arOneProductParams['SORT'],
            ];
        }
        unset($arOneProductParams);
    }

    $arProps[] = [
        'NAME' => 'Product XML_ID',
        'CODE' => 'PRODUCT.XML_ID',
        'VALUE' => $arProduct['XML_ID'],
    ];

    $arFields = [
        'PRODUCT_ID' => $intProductID,
        'PRODUCT_PRICE_ID' => $arPrice['PRODUCT_PRICE_ID'],
        'BASE_PRICE' => $arPrice['BASE_PRICE'],
        'PRICE' => $arPrice['PRICE'],
        'DISCOUNT_PRICE' => $arPrice['DISCOUNT_PRICE'],
        'CURRENCY' => $arPrice['CURRENCY'],
        'VAT_RATE' => $arPrice['VAT_RATE'],
        'WEIGHT' => $arCatalogProduct['WEIGHT'],
        'DIMENSIONS' => serialize([
            'WIDTH' => $arCatalogProduct['WIDTH'],
            'HEIGHT' => $arCatalogProduct['HEIGHT'],
            'LENGTH' => $arCatalogProduct['LENGTH'],
        ]),
        'QUANTITY' => 1,
        'LID' => SITE_ID,
        'DELAY' => 'N',
        'CAN_BUY' => 'N',
        'SUBSCRIBE' => 'Y',
        'NAME' => $arProduct['~NAME'],
        'MODULE' => 'catalog',
        'PRODUCT_PROVIDER_CLASS' => 'CCatalogProductProvider',
        'NOTES' => $arPrice['CATALOG_GROUP_NAME'],
        'DETAIL_PAGE_URL' => $arProduct['~DETAIL_PAGE_URL'],
        'CATALOG_XML_ID' => $strIBlockXmlID,
        'PRODUCT_XML_ID' => $arProduct['~XML_ID'],
        'PROPS' => $arProps,
        'TYPE' => (CCatalogProduct::TYPE_SET === $arCatalogProduct['TYPE']) ? CCatalogProductSet::TYPE_SET : null,
        'MEASURE_NAME' => $arCatalogProduct['MEASURE_NAME'],
        'MEASURE_CODE' => $arCatalogProduct['MEASURE_CODE'],
        'IGNORE_CALLBACK_FUNC' => 'Y',
    ];

    if (!empty($arRewriteFields) && is_array($arRewriteFields)) {
        if (array_key_exists('SUBSCRIBE', $arRewriteFields)) {
            unset($arRewriteFields['SUBSCRIBE']);
        }
        if (array_key_exists('CAN_BUY', $arRewriteFields)) {
            unset($arRewriteFields['CAN_BUY']);
        }
        if (array_key_exists('DELAY', $arRewriteFields)) {
            unset($arRewriteFields['DELAY']);
        }
        if (!empty($arRewriteFields)) {
            $arFields = array_merge($arFields, $arRewriteFields);
        }
    }

    $mxBasketID = CSaleBasket::Add($arFields);
    if ($mxBasketID) {
        if (!isset($_SESSION['NOTIFY_PRODUCT'])) {
            $_SESSION['NOTIFY_PRODUCT'] = [
                $intUserID = [],
            ];
        } elseif (!isset($_SESSION['NOTIFY_PRODUCT'][$intUserID])) {
            $_SESSION['NOTIFY_PRODUCT'][$intUserID] = [];
        }
        $_SESSION['NOTIFY_PRODUCT'][$intUserID][$intProductID] = $intProductID;

        if (Loader::includeModule('statistic')) {
            CStatistic::Set_Event('sale2basket', 'subscribe', $intProductID);
        }
    }

    return $mxBasketID;
}

/**
 * @param int   $ID               Код товара. (ID элемента инфоблока, для которого запрашиваются
 *                                цены.)
 * @param array $arFilterType     = array() Массив ID типов цен. Если не задан, то выбираются все типы цен,
 *                                которые может просматривать пользователь.
 * @param mixed $VAT_INCLUDE      = 'Y' (Y/N) НДС включён
 * @param array $arCurrencyParams = array() Массив параметров для показа цен в одной валюте. Если в
 *                                переданном массиве заполнено поле CURRENCY_ID, то  произойдет
 *                                конвертация цен в валюту CURRENCY_ID по текущему курсу. Необязательный
 *                                параметр.
 * @param mixed $filterQauntity
 *
 * @return mixed <p></p><br><br>
 *
 * @static
 *
 * @see http://dev.1c-bitrix.ru/api_help/catalog/functions/cataloggetpricetableex.php
 *
 * @author Bitrix
 */
function CatalogGetPriceTableEx($ID, $filterQauntity = 0, $arFilterType = [], $VAT_INCLUDE = 'Y', $arCurrencyParams = [])
{
    global $USER;

    static $arPriceTypes = [];

    $ID = (int) $ID;
    if ($ID <= 0) {
        return false;
    }

    $filterQauntity = (int) $filterQauntity;

    if (!is_array($arFilterType)) {
        $arFilterType = [$arFilterType];
    }

    $boolConvert = false;
    $strCurrencyID = '';
    $arCurrencyList = [];
    if (!empty($arCurrencyParams) && is_array($arCurrencyParams) && !empty($arCurrencyParams['CURRENCY_ID'])) {
        $boolConvert = true;
        $strCurrencyID = $arCurrencyParams['CURRENCY_ID'];
    }

    $arResult = [];
    $arResult['ROWS'] = [];
    $arResult['COLS'] = [];
    $arResult['MATRIX'] = [];
    $arResult['CAN_BUY'] = [];
    $arResult['AVAILABLE'] = 'N';

    $cacheTime = CATALOG_CACHE_DEFAULT_TIME;
    if (defined('CATALOG_CACHE_TIME')) {
        $cacheTime = (int) CATALOG_CACHE_TIME;
    }

    $arUserGroups = $USER->GetUserGroupArray();
    CatalogClearArray($arUserGroups, true);
    $strCacheID = 'UG_'.implode('_', $arUserGroups);

    if (isset($arPriceTypes[$strCacheID])) {
        $arPriceGroups = $arPriceTypes[$strCacheID];
    } else {
        $arPriceGroups = CCatalogGroup::GetGroupsPerms($arUserGroups, []);
        $arPriceTypes[$strCacheID] = $arPriceGroups;
    }

    if (empty($arPriceGroups['view'])) {
        return $arResult;
    }

    $currentQuantity = -1;
    $rowsCnt = -1;

    $arFilter = ['PRODUCT_ID' => $ID];
    if ($filterQauntity > 0) {
        $arFilter['+<=QUANTITY_FROM'] = $filterQauntity;
        $arFilter['+>=QUANTITY_TO'] = $filterQauntity;
    }
    if (!empty($arFilterType)) {
        $arTmp = [];
        foreach ($arPriceGroups['view'] as &$intOneGroup) {
            if (in_array($intOneGroup, $arFilterType, true)) {
                $arTmp[] = $intOneGroup;
            }
        }
        if (isset($intOneGroup)) {
            unset($intOneGroup);
        }

        if (empty($arTmp)) {
            return $arResult;
        }

        $arFilter['CATALOG_GROUP_ID'] = $arTmp;
    } else {
        $arFilter['CATALOG_GROUP_ID'] = $arPriceGroups['view'];
    }

    $productQuantity = 0;
    $productQuantityTrace = 'N';

    $dbRes = CCatalogProduct::GetVATInfo($ID);
    if ($arVatInfo = $dbRes->Fetch()) {
        $fVatRate = (float) ($arVatInfo['RATE'] * 0.01);
        $bVatIncluded = 'Y' === $arVatInfo['VAT_INCLUDED'];
    } else {
        $fVatRate = 0.00;
        $bVatIncluded = false;
    }

    $rsProducts = CCatalogProduct::GetList(
        [],
        ['ID' => $ID],
        false,
        false,
        [
            'ID',
            'CAN_BUY_ZERO',
            'QUANTITY_TRACE',
            'QUANTITY',
        ]
    );
    if ($arProduct = $rsProducts->Fetch()) {
        $intIBlockID = CIBlockElement::GetIBlockByID($arProduct['ID']);
        if (!$intIBlockID) {
            return false;
        }
        $arProduct['IBLOCK_ID'] = $intIBlockID;
    } else {
        return false;
    }

    $dbPrice = CPrice::GetListEx(
        ['QUANTITY_FROM' => 'ASC', 'QUANTITY_TO' => 'ASC'],
        $arFilter,
        false,
        false,
        ['ID', 'CATALOG_GROUP_ID', 'PRICE', 'CURRENCY', 'QUANTITY_FROM', 'QUANTITY_TO']
    );

    while ($arPrice = $dbPrice->Fetch()) {
        if ('N' === $VAT_INCLUDE) {
            if ($bVatIncluded) {
                $arPrice['PRICE'] /= (1 + $fVatRate);
            }
        } else {
            if (!$bVatIncluded) {
                $arPrice['PRICE'] *= (1 + $fVatRate);
            }
        }
        $arPrice['CATALOG_GROUP_ID'] = (int) $arPrice['CATALOG_GROUP_ID'];

        $arPrice['VAT_RATE'] = $fVatRate;

        CCatalogDiscountSave::Disable();
        $arDiscounts = CCatalogDiscount::GetDiscount($ID, $arProduct['IBLOCK_ID'], $arPrice['CATALOG_GROUP_ID'], $arUserGroups, 'N', SITE_ID, []);
        CCatalogDiscountSave::Enable();

        $discountPrice = CCatalogProduct::CountPriceWithDiscount($arPrice['PRICE'], $arPrice['CURRENCY'], $arDiscounts);
        $arPrice['DISCOUNT_PRICE'] = $discountPrice;

        $arPrice['QUANTITY_FROM'] = (float) $arPrice['QUANTITY_FROM'];
        if ($currentQuantity !== $arPrice['QUANTITY_FROM']) {
            ++$rowsCnt;
            $arResult['ROWS'][$rowsCnt]['QUANTITY_FROM'] = $arPrice['QUANTITY_FROM'];
            $arResult['ROWS'][$rowsCnt]['QUANTITY_TO'] = (float) $arPrice['QUANTITY_TO'];
            $currentQuantity = $arPrice['QUANTITY_FROM'];
        }

        if ($boolConvert && $strCurrencyID !== $arPrice['CURRENCY']) {
            $arResult['MATRIX'][$arPrice['CATALOG_GROUP_ID']][$rowsCnt] = [
                'ID' => $arPrice['ID'],
                'ORIG_PRICE' => $arPrice['PRICE'],
                'ORIG_DISCOUNT_PRICE' => $arPrice['DISCOUNT_PRICE'],
                'ORIG_CURRENCY' => $arPrice['CURRENCY'],
                'ORIG_VAT_RATE' => $arPrice['VAT_RATE'],
                'PRICE' => CCurrencyRates::ConvertCurrency($arPrice['PRICE'], $arPrice['CURRENCY'], $strCurrencyID),
                'DISCOUNT_PRICE' => CCurrencyRates::ConvertCurrency($arPrice['DISCOUNT_PRICE'], $arPrice['CURRENCY'], $strCurrencyID),
                'CURRENCY' => $strCurrencyID,
                'VAT_RATE' => CCurrencyRates::ConvertCurrency($arPrice['VAT_RATE'], $arPrice['CURRENCY'], $strCurrencyID),
            ];
            $arCurrencyList[$arPrice['CURRENCY']] = $arPrice['CURRENCY'];
        } else {
            $arResult['MATRIX'][$arPrice['CATALOG_GROUP_ID']][$rowsCnt] = [
                'ID' => $arPrice['ID'],
                'PRICE' => $arPrice['PRICE'],
                'DISCOUNT_PRICE' => $arPrice['DISCOUNT_PRICE'],
                'CURRENCY' => $arPrice['CURRENCY'],
                'VAT_RATE' => $arPrice['VAT_RATE'],
            ];
        }
    }

    $arCatalogGroups = CCatalogGroup::GetListArray();
    foreach ($arCatalogGroups as $key => $value) {
        if (isset($arResult['MATRIX'][$key])) {
            $arResult['COLS'][$value['ID']] = $value;
        }
    }

    $arResult['CAN_BUY'] = $arPriceGroups['buy'];
    $arResult['AVAILABLE'] = (0 >= $arProduct['QUANTITY'] && 'Y' === $arProduct['QUANTITY_TRACE'] && 'N' === $arProduct['CAN_BUY_ZERO'] ? 'N' : 'Y');

    if ($boolConvert) {
        if (!empty($arCurrencyList)) {
            $arCurrencyList[$strCurrencyID] = $strCurrencyID;
        }
        $arResult['CURRENCY_LIST'] = $arCurrencyList;
    }

    return $arResult;
}

function CatalogGetPriceTable($ID)
{
    global $USER;

    $ID = (int) $ID;
    if ($ID <= 0) {
        return false;
    }

    $arResult = [];

    $arPriceGroups = [];
    $cacheKey = LANGUAGE_ID.'_'.$USER->GetGroups();
    if (isset($GLOBALS['CATALOG_PRICE_GROUPS_CACHE'])
        && is_array($GLOBALS['CATALOG_PRICE_GROUPS_CACHE'])
        && isset($GLOBALS['CATALOG_PRICE_GROUPS_CACHE'][$cacheKey])
        && is_array($GLOBALS['CATALOG_PRICE_GROUPS_CACHE'][$cacheKey])) {
        $arPriceGroups = $GLOBALS['CATALOG_PRICE_GROUPS_CACHE'][$cacheKey];
    } else {
        $dbPriceGroupsList = CCatalogGroup::GetList(
            ['SORT' => 'ASC'],
            [
                'CAN_ACCESS' => 'Y',
                'LID' => LANGUAGE_ID,
            ],
            ['ID', 'NAME_LANG', 'SORT'],
            false,
            ['ID', 'NAME_LANG', 'CAN_BUY', 'SORT']
        );
        while ($arPriceGroupsList = $dbPriceGroupsList->Fetch()) {
            $arPriceGroups[] = $arPriceGroupsList;
            $GLOBALS['CATALOG_PRICE_GROUPS_CACHE'][$cacheKey][] = $arPriceGroupsList;
        }
    }

    if (empty($arPriceGroups)) {
        return false;
    }

    $arBorderMap = [];
    $arPresentGroups = [];
    $bMultiQuantity = false;

    $dbPrice = CPrice::GetList(
        ['QUANTITY_FROM' => 'ASC', 'QUANTITY_TO' => 'ASC', 'SORT' => 'ASC'],
        ['PRODUCT_ID' => $ID],
        false,
        false,
        ['ID', 'CATALOG_GROUP_ID', 'PRICE', 'CURRENCY', 'QUANTITY_FROM', 'QUANTITY_TO', 'ELEMENT_IBLOCK_ID', 'SORT']
    );
    while ($arPrice = $dbPrice->Fetch()) {
        CCatalogDiscountSave::Disable();
        $arDiscounts = CCatalogDiscount::GetDiscount($ID, $arPrice['ELEMENT_IBLOCK_ID'], $arPrice['CATALOG_GROUP_ID'], $USER->GetUserGroupArray(), 'N', SITE_ID, []);
        CCatalogDiscountSave::Enable();

        $discountPrice = CCatalogProduct::CountPriceWithDiscount($arPrice['PRICE'], $arPrice['CURRENCY'], $arDiscounts);
        $arPrice['DISCOUNT_PRICE'] = $discountPrice;

        if (array_key_exists($arPrice['QUANTITY_FROM'].'-'.$arPrice['QUANTITY_TO'], $arBorderMap)) {
            $jnd = $arBorderMap[$arPrice['QUANTITY_FROM'].'-'.$arPrice['QUANTITY_TO']];
        } else {
            $jnd = count($arBorderMap);
            $arBorderMap[$arPrice['QUANTITY_FROM'].'-'.$arPrice['QUANTITY_TO']] = $jnd;
        }

        $arResult[$jnd]['QUANTITY_FROM'] = (float) $arPrice['QUANTITY_FROM'];
        $arResult[$jnd]['QUANTITY_TO'] = (float) $arPrice['QUANTITY_TO'];
        if ((float) $arPrice['QUANTITY_FROM'] > 0 || (float) $arPrice['QUANTITY_TO'] > 0) {
            $bMultiQuantity = true;
        }

        $arResult[$jnd]['PRICE'][$arPrice['CATALOG_GROUP_ID']] = $arPrice;
    }

    $numGroups = count($arPriceGroups);
    for ($i = 0; $i < $numGroups; ++$i) {
        $bNeedKill = true;
        for ($j = 0, $intCount = count($arResult); $j < $intCount; ++$j) {
            if (!array_key_exists($arPriceGroups[$i]['ID'], $arResult[$j]['PRICE'])) {
                $arResult[$j]['PRICE'][$arPriceGroups[$i]['ID']] = false;
            }

            if (false !== $arResult[$j]['PRICE'][$arPriceGroups[$i]['ID']]) {
                $bNeedKill = false;
            }
        }

        if ($bNeedKill) {
            for ($j = 0, $intCount = count($arResult); $j < $intCount; ++$j) {
                unset($arResult[$j]['PRICE'][$arPriceGroups[$i]['ID']], $arPriceGroups[$i]);
            }
        }
    }

    return [
        'COLS' => $arPriceGroups,
        'MATRIX' => $arResult,
        'MULTI_QUANTITY' => ($bMultiQuantity ? 'Y' : 'N'),
    ];
}

function __CatalogGetMicroTime()
{
    list($usec, $sec) = explode(' ', microtime());

    return (float) $usec + (float) $sec;
}

function __CatalogSetTimeMark($text, $startStop = '')
{
    global $__catalogTimeMarkTo, $__catalogTimeMarkFrom, $__catalogTimeMarkGlobalFrom;

    if ('START' === strtoupper($startStop)) {
        $hFile = fopen($_SERVER['DOCUMENT_ROOT'].'/__catalog_debug.txt', 'a');
        fwrite($hFile, date('H:i:s').' - '.$text."\n");
        fclose($hFile);

        $__catalogTimeMarkGlobalFrom = __CatalogGetMicroTime();
        $__catalogTimeMarkFrom = __CatalogGetMicroTime();
    } elseif ('STOP' === strtoupper($startStop)) {
        $__catalogTimeMarkTo = __CatalogGetMicroTime();

        $hFile = fopen($_SERVER['DOCUMENT_ROOT'].'/__catalog_debug.txt', 'a');
        fwrite($hFile, date('H:i:s').' - '.round($__catalogTimeMarkTo - $__catalogTimeMarkFrom, 3).' s - '.$text."\n");
        fwrite($hFile, date('H:i:s').' - '.round($__catalogTimeMarkTo - $__catalogTimeMarkGlobalFrom, 3)." s\n\n");
        fclose($hFile);
    } else {
        $__catalogTimeMarkTo = __CatalogGetMicroTime();

        $hFile = fopen($_SERVER['DOCUMENT_ROOT'].'/__catalog_debug.txt', 'a');
        fwrite($hFile, date('H:i:s').' - '.round($__catalogTimeMarkTo - $__catalogTimeMarkFrom, 3).' s - '.$text."\n");
        fclose($hFile);

        $__catalogTimeMarkFrom = __CatalogGetMicroTime();
    }
}

function CatalogGetVATArray($arFilter = [], $bInsertEmptyLine = false)
{
    $bInsertEmptyLine = (true === $bInsertEmptyLine);

    if (!is_array($arFilter)) {
        $arFilter = [];
    }

    $arFilter['ACTIVE'] = 'Y';
    $dbResult = CCatalogVat::GetListEx([], $arFilter, false, false, ['ID', 'NAME']);

    $arReference = [];

    if ($bInsertEmptyLine) {
        $arList = ['REFERENCE' => [0 => Loc::getMessage('CAT_VAT_REF_NOT_SELECTED')], 'REFERENCE_ID' => [0 => '']];
    } else {
        $arList = ['REFERENCE' => [], 'REFERENCE_ID' => []];
    }

    $bEmpty = true;
    while ($arRes = $dbResult->Fetch()) {
        $bEmpty = false;
        $arList['REFERENCE'][] = $arRes['NAME'];
        $arList['REFERENCE_ID'][] = $arRes['ID'];
    }

    if ($bEmpty && !$bInsertEmptyLine) {
        return false;
    }

    return $arList;
}

function CurrencyModuleUnInstallCatalog()
{
    global $APPLICATION;
    $APPLICATION->ThrowException(Loc::getMessage('CAT_INCLUDE_CURRENCY'), 'CAT_DEPENDS_CURRENCY');

    return false;
}

function CatalogGenerateCoupon()
{
    foreach (GetModuleEvents('catalog', 'OnGenerateCoupon', true) as $arEvent) {
        return ExecuteModuleEventEx($arEvent);
    }

    $allchars = 'ABCDEFGHIJKLNMOPQRSTUVWXYZ0123456789';
    $charsLen = strlen($allchars) - 1;
    $string1 = '';
    $string2 = '';
    for ($i = 0; $i < 5; ++$i) {
        $string1 .= substr($allchars, rand(0, $charsLen), 1);
    }

    for ($i = 0; $i < 7; ++$i) {
        $string2 .= substr($allchars, rand(0, $charsLen), 1);
    }

    return 'CP-'.$string1.'-'.$string2;
}

function __GetCatLangMessages($strBefore, $strAfter, $MessID, $strDefMess = false, $arLangList = [])
{
    $arResult = false;

    if (empty($MessID)) {
        return $arResult;
    }
    if (!is_array($MessID)) {
        $MessID = [$MessID];
    }
    if (!is_array($arLangList)) {
        $arLangList = [$arLangList];
    }

    if (empty($arLangList)) {
        $by = 'lid';
        $order = 'asc';
        $rsLangs = CLanguage::GetList($by, $order, ['ACTIVE' => 'Y']);
        while ($arLang = $rsLangs->Fetch()) {
            $arLangList[] = $arLang['LID'];
        }
    }
    foreach ($arLangList as &$strLID) {
        $arMess = IncludeModuleLangFile(str_replace('//', '/', $strBefore.$strAfter), $strLID, true);
        if (!empty($arMess)) {
            foreach ($MessID as &$strMessID) {
                if (empty($strMessID)) {
                    continue;
                }
                $arResult[$strMessID][$strLID] = (isset($arMess[$strMessID]) ? $arMess[$strMessID] : $strDefMess);
            }
            if (isset($strMessID)) {
                unset($strMessID);
            }
        }
    }
    if (isset($strLID)) {
        unset($strLID);
    }

    return $arResult;
}

/**
 * @deprecated deprecated since catalog 16.0.0
 * @see Collection::normalizeArrayValuesByInt
 *
 * @param array &$arMap
 * @param bool  $boolSort
 */
function CatalogClearArray(&$arMap, $boolSort = true)
{
    Collection::normalizeArrayValuesByInt($arMap, $boolSort);
}
