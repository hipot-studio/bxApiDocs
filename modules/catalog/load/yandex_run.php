<?php

// <title>Yandex</title>
// @global CUser $USER
// @global CMain $APPLICATION
// @var int $IBLOCK_ID
// @var string $SETUP_SERVER_NAME
// @var string $SETUP_FILE_NAME
// @var array $V
// @var string $XML_DATA
use Bitrix\Catalog;
use Bitrix\Currency;
use Bitrix\Iblock;

IncludeModuleLangFile($_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/catalog/export_yandex.php');
set_time_limit(0);

global $USER, $APPLICATION;
$bTmpUserCreated = false;
if (!CCatalog::IsUserExists()) {
    $bTmpUserCreated = true;
    if (isset($USER)) {
        $USER_TMP = $USER;
        unset($USER);
    }

    $USER = new CUser();
}

CCatalogDiscountSave::Disable();
// @noinspection PhpDeprecationInspection
CCatalogDiscountCoupon::ClearCoupon();
if ($USER->IsAuthorized()) {
    // @noinspection PhpDeprecationInspection
    CCatalogDiscountCoupon::ClearCouponsByManage($USER->GetID());
}

$arYandexFields = [
    'typePrefix', 'vendor', 'vendorCode', 'model',
    'author', 'name', 'publisher', 'series', 'year',
    'ISBN', 'volume', 'part', 'language', 'binding',
    'page_extent', 'table_of_contents', 'performed_by', 'performance_type',
    'storage', 'format', 'recording_length', 'artist', 'title', 'year', 'media',
    'starring', 'director', 'originalName', 'country', 'aliases',
    'description', 'sales_notes', 'promo', 'provider', 'tarifplan',
    'xCategory', 'additional', 'worldRegion', 'region', 'days', 'dataTour',
    'hotel_stars', 'room', 'meal', 'included', 'transport', 'price_min', 'price_max',
    'options', 'manufacturer_warranty', 'country_of_origin', 'downloadable', 'adult', 'param',
    'place', 'hall', 'hall_part', 'is_premiere', 'is_kids', 'date',
];

$formatList = [
    'none' => [
        'vendor', 'vendorCode', 'sales_notes', 'manufacturer_warranty', 'country_of_origin',
        'adult',
    ],
    'vendor.model' => [
        'typePrefix', 'vendor', 'vendorCode', 'model', 'sales_notes', 'manufacturer_warranty', 'country_of_origin',
        'adult',
    ],
    'book' => [
        'author', 'publisher', 'series', 'year', 'ISBN', 'volume', 'part', 'language', 'binding',
        'page_extent', 'table_of_contents',
    ],
    'audiobook' => [
        'author', 'publisher', 'series', 'year', 'ISBN', 'performed_by', 'performance_type',
        'language', 'volume', 'part', 'format', 'storage', 'recording_length', 'table_of_contents',
    ],
    'artist.title' => [
        'title', 'artist', 'director', 'starring', 'originalName', 'country', 'year', 'media', 'adult',
    ],
];

if (!function_exists('yandex_replace_special')) {
    function yandex_replace_special($arg)
    {
        if (in_array($arg[0], ['&quot;', '&amp;', '&lt;', '&gt;'], true)) {
            return $arg[0];
        }

        return ' ';
    }
}

if (!function_exists('yandex_text2xml')) {
    function yandex_text2xml($text, $bHSC = false, $bDblQuote = false)
    {
        global $APPLICATION;

        $bHSC = (true === $bHSC ? true : false);
        $bDblQuote = (true === $bDblQuote ? true : false);

        if ($bHSC) {
            $text = htmlspecialcharsbx($text);
            if ($bDblQuote) {
                $text = str_replace('&quot;', '"', $text);
            }
        }
        $text = preg_replace("/[\x1-\x8\xB-\xC\xE-\x1F]/", '', $text);
        $text = str_replace("'", '&apos;', $text);
        $text = $APPLICATION->ConvertCharset($text, LANG_CHARSET, 'windows-1251');

        return $text;
    }
}

if (!function_exists('yandex_get_value')) {
    function yandex_get_value($arOffer, $param, $PROPERTY, $arProperties, $arUserTypeFormat, $usedProtocol)
    {
        global $iblockServerName;

        $strProperty = '';
        $bParam = (0 === strncmp($param, 'PARAM_', 6));
        if (isset($arProperties[$PROPERTY]) && !empty($arProperties[$PROPERTY])) {
            $PROPERTY_CODE = $arProperties[$PROPERTY]['CODE'];
            $arProperty = (
                isset($arOffer['PROPERTIES'][$PROPERTY_CODE])
                ? $arOffer['PROPERTIES'][$PROPERTY_CODE]
                : $arOffer['PROPERTIES'][$PROPERTY]
            );

            $value = '';
            $description = '';

            switch ($arProperty['PROPERTY_TYPE']) {
                case 'USER_TYPE':
                    if ('Y' === $arProperty['MULTIPLE']) {
                        if (!empty($arProperty['~VALUE'])) {
                            $arValues = [];
                            foreach ($arProperty['~VALUE'] as $oneValue) {
                                $isArray = is_array($oneValue);
                                if (
                                    ($isArray && !empty($oneValue))
                                    || (!$isArray && '' !== $oneValue)
                                ) {
                                    $arValues[] = call_user_func_array(
                                        $arUserTypeFormat[$PROPERTY],
                                        [
                                            $arProperty,
                                            ['VALUE' => $oneValue],
                                            ['MODE' => 'SIMPLE_TEXT'],
                                        ]
                                    );
                                }
                            }
                            $value = implode(', ', $arValues);
                        }
                    } else {
                        $isArray = is_array($arProperty['~VALUE']);
                        if (
                            ($isArray && !empty($arProperty['~VALUE']))
                            || (!$isArray && '' !== $arProperty['~VALUE'])
                        ) {
                            $value = call_user_func_array(
                                $arUserTypeFormat[$PROPERTY],
                                [
                                    $arProperty,
                                    ['VALUE' => $arProperty['~VALUE']],
                                    ['MODE' => 'SIMPLE_TEXT'],
                                ]
                            );
                        }
                    }

                    break;

                case Iblock\PropertyTable::TYPE_ELEMENT:
                    if (!empty($arProperty['VALUE'])) {
                        $arCheckValue = [];
                        if (!is_array($arProperty['VALUE'])) {
                            $arProperty['VALUE'] = (int) $arProperty['VALUE'];
                            if (0 < $arProperty['VALUE']) {
                                $arCheckValue[] = $arProperty['VALUE'];
                            }
                        } else {
                            foreach ($arProperty['VALUE'] as &$intValue) {
                                $intValue = (int) $intValue;
                                if (0 < $intValue) {
                                    $arCheckValue[] = $intValue;
                                }
                            }
                            if (isset($intValue)) {
                                unset($intValue);
                            }
                        }
                        if (!empty($arCheckValue)) {
                            $dbRes = CIBlockElement::GetList(
                                [],
                                ['IBLOCK_ID' => $arProperty['LINK_IBLOCK_ID'], 'ID' => $arCheckValue],
                                false,
                                false,
                                ['NAME']
                            );
                            while ($arRes = $dbRes->Fetch()) {
                                $value .= ($value ? ', ' : '').$arRes['NAME'];
                            }
                        }
                    }

                    break;

                case Iblock\PropertyTable::TYPE_SECTION:
                    if (!empty($arProperty['VALUE'])) {
                        $arCheckValue = [];
                        if (!is_array($arProperty['VALUE'])) {
                            $arProperty['VALUE'] = (int) $arProperty['VALUE'];
                            if (0 < $arProperty['VALUE']) {
                                $arCheckValue[] = $arProperty['VALUE'];
                            }
                        } else {
                            foreach ($arProperty['VALUE'] as &$intValue) {
                                $intValue = (int) $intValue;
                                if (0 < $intValue) {
                                    $arCheckValue[] = $intValue;
                                }
                            }
                            if (isset($intValue)) {
                                unset($intValue);
                            }
                        }
                        if (!empty($arCheckValue)) {
                            $dbRes = CIBlockSection::GetList(
                                [],
                                ['IBLOCK_ID' => $arProperty['LINK_IBLOCK_ID'], 'ID' => $arCheckValue],
                                false,
                                ['NAME']
                            );
                            while ($arRes = $dbRes->Fetch()) {
                                $value .= ($value ? ', ' : '').$arRes['NAME'];
                            }
                        }
                    }

                    break;

                case Iblock\PropertyTable::TYPE_LIST:
                    if (!empty($arProperty['VALUE'])) {
                        if (is_array($arProperty['VALUE'])) {
                            $value .= implode(', ', $arProperty['VALUE']);
                        } else {
                            $value .= $arProperty['VALUE'];
                        }
                    }

                    break;

                case Iblock\PropertyTable::TYPE_FILE:
                    if (!empty($arProperty['VALUE'])) {
                        if (is_array($arProperty['VALUE'])) {
                            foreach ($arProperty['VALUE'] as &$intValue) {
                                $intValue = (int) $intValue;
                                if ($intValue > 0) {
                                    if ($ar_file = CFile::GetFileArray($intValue)) {
                                        if ('/' === substr($ar_file['SRC'], 0, 1)) {
                                            $strFile = $usedProtocol.$iblockServerName.CHTTP::urnEncode($ar_file['SRC'], 'utf-8');
                                        } else {
                                            $strFile = $ar_file['SRC'];
                                        }
                                        $value .= ($value ? ', ' : '').$strFile;
                                    }
                                }
                            }
                            if (isset($intValue)) {
                                unset($intValue);
                            }
                        } else {
                            $arProperty['VALUE'] = (int) $arProperty['VALUE'];
                            if ($arProperty['VALUE'] > 0) {
                                if ($ar_file = CFile::GetFileArray($arProperty['VALUE'])) {
                                    if ('/' === substr($ar_file['SRC'], 0, 1)) {
                                        $strFile = $usedProtocol.$iblockServerName.CHTTP::urnEncode($ar_file['SRC'], 'utf-8');
                                    } else {
                                        $strFile = $ar_file['SRC'];
                                    }
                                    $value = $strFile;
                                }
                            }
                        }
                    }

                    break;

                default:
                    if ($bParam && 'Y' === $arProperty['WITH_DESCRIPTION']) {
                        $description = $arProperty['DESCRIPTION'];
                        $value = $arProperty['VALUE'];
                    } else {
                        $value = is_array($arProperty['VALUE']) ? implode(', ', $arProperty['VALUE']) : $arProperty['VALUE'];
                    }
            }

            // !!!! check multiple properties and properties like CML2_ATTRIBUTES

            if ($bParam) {
                if (is_array($description)) {
                    foreach ($value as $key => $val) {
                        $strProperty .= $strProperty ? "\n" : '';
                        $strProperty .= '<param name="'.yandex_text2xml($description[$key], true).'">'.
                            yandex_text2xml($val, true).'</param>';
                    }
                } else {
                    $strProperty .= '<param name="'.yandex_text2xml($arProperty['NAME'], true).'">'.
                        yandex_text2xml($value, true).'</param>';
                }
            } else {
                $param_h = yandex_text2xml($param, true);
                $strProperty .= '<'.$param_h.'>'.yandex_text2xml($value, true).'</'.$param_h.'>';
            }
        }

        return $strProperty;
    }
}

$arRunErrors = [];

if ($XML_DATA && CheckSerializedData($XML_DATA)) {
    $XML_DATA = unserialize(stripslashes($XML_DATA));
    if (!is_array($XML_DATA)) {
        $XML_DATA = [];
    }
}
if (!is_array($XML_DATA)) {
    $arRunErrors[] = GetMessage('YANDEX_ERR_BAD_XML_DATA');
}

$yandexFormat = 'none';
if (isset($XML_DATA['TYPE'], $formatList[$XML_DATA['TYPE']])) {
    $yandexFormat = $XML_DATA['TYPE'];
}

$productFormat = ('none' !== $yandexFormat ? ' type="'.htmlspecialcharsbx($yandexFormat).'"' : '');

$fields = [];
$fieldsExist = !empty($XML_DATA['XML_DATA']) && is_array($XML_DATA['XML_DATA']);
if ($fieldsExist) {
    foreach ($XML_DATA['XML_DATA'] as $key => $value) {
        if (!is_array($value)) {
            continue;
        }
        $value = (string) $value;
        if ('' === $value) {
            continue;
        }
        $fields[$key] = $value;
    }
    unset($key, $value);
    $fieldsExist = !empty($fields);
}

$parametricFieldsExist = false;
$parametricFields = [];
if ($fieldsExist) {
    $parametricFieldsExist = (!empty($XML_DATA['XML_DATA']['PARAMS']) && is_array($XML_DATA['XML_DATA']['PARAMS']));
}
if ($parametricFieldsExist) {
    $parametricFields = $XML_DATA['XML_DATA']['PARAMS'];
}

$needProperties = !empty($XML_DATA['XML_DATA']) && is_array($XML_DATA['XML_DATA']);

$IBLOCK_ID = (int) $IBLOCK_ID;
$db_iblock = CIBlock::GetByID($IBLOCK_ID);
if (!($ar_iblock = $db_iblock->Fetch())) {
    $arRunErrors[] = str_replace('#ID#', $IBLOCK_ID, GetMessage('YANDEX_ERR_NO_IBLOCK_FOUND_EXT'));
}
/*elseif (!CIBlockRights::UserHasRightTo($IBLOCK_ID, $IBLOCK_ID, 'iblock_admin_display'))
{
    $arRunErrors[] = str_replace('#IBLOCK_ID#',$IBLOCK_ID,GetMessage('CET_ERROR_IBLOCK_PERM'));
} */
else {
    $SETUP_SERVER_NAME = trim($SETUP_SERVER_NAME);

    if ('' === $SETUP_SERVER_NAME) {
        if ('' === $ar_iblock['SERVER_NAME']) {
            $b = 'sort';
            $o = 'asc';
            $rsSite = CSite::GetList($b, $o, ['LID' => $ar_iblock['LID']]);
            if ($arSite = $rsSite->Fetch()) {
                $ar_iblock['SERVER_NAME'] = $arSite['SERVER_NAME'];
            }
            if ('' === $ar_iblock['SERVER_NAME'] && defined('SITE_SERVER_NAME')) {
                $ar_iblock['SERVER_NAME'] = SITE_SERVER_NAME;
            }
            if ('' === $ar_iblock['SERVER_NAME']) {
                $ar_iblock['SERVER_NAME'] = COption::GetOptionString('main', 'server_name', '');
            }
        }
    } else {
        $ar_iblock['SERVER_NAME'] = $SETUP_SERVER_NAME;
    }
    $ar_iblock['PROPERTY'] = [];
    $rsProps = CIBlockProperty::GetList(
        ['SORT' => 'ASC', 'NAME' => 'ASC'],
        ['IBLOCK_ID' => $IBLOCK_ID, 'ACTIVE' => 'Y', 'CHECK_PERMISSIONS' => 'N']
    );
    while ($arProp = $rsProps->Fetch()) {
        $arProp['ID'] = (int) $arProp['ID'];
        $arProp['USER_TYPE'] = (string) $arProp['USER_TYPE'];
        $arProp['CODE'] = (string) $arProp['CODE'];
        $ar_iblock['PROPERTY'][$arProp['ID']] = $arProp;
    }
}

global $iblockServerName;
$iblockServerName = $ar_iblock['SERVER_NAME'];

$arProperties = [];
if (isset($ar_iblock['PROPERTY'])) {
    $arProperties = $ar_iblock['PROPERTY'];
}

$boolOffers = false;
$arOffers = false;
$arOfferIBlock = false;
$intOfferIBlockID = 0;
$arSelectOfferProps = [];
$arSelectedPropTypes = [
    Iblock\PropertyTable::TYPE_STRING,
    Iblock\PropertyTable::TYPE_NUMBER,
    Iblock\PropertyTable::TYPE_LIST,
    Iblock\PropertyTable::TYPE_ELEMENT,
    Iblock\PropertyTable::TYPE_SECTION,
];
$arOffersSelectKeys = [
    YANDEX_SKU_EXPORT_ALL,
    YANDEX_SKU_EXPORT_MIN_PRICE,
    YANDEX_SKU_EXPORT_PROP,
];
$arCondSelectProp = [
    'ZERO',
    'NONZERO',
    'EQUAL',
    'NONEQUAL',
];
$arPropertyMap = [];
$arSKUExport = [];

$arCatalog = CCatalog::GetByIDExt($IBLOCK_ID);
if (empty($arCatalog)) {
    $arRunErrors[] = str_replace('#ID#', $IBLOCK_ID, GetMessage('YANDEX_ERR_NO_IBLOCK_IS_CATALOG'));
} else {
    $arOffers = CCatalogSku::GetInfoByProductIBlock($IBLOCK_ID);
    if (!empty($arOffers['IBLOCK_ID'])) {
        $intOfferIBlockID = $arOffers['IBLOCK_ID'];
        $rsOfferIBlocks = CIBlock::GetByID($intOfferIBlockID);
        if ($arOfferIBlock = $rsOfferIBlocks->Fetch()) {
            $boolOffers = true;
            $rsProps = CIBlockProperty::GetList(
                ['SORT' => 'ASC', 'NAME' => 'ASC'],
                ['IBLOCK_ID' => $intOfferIBlockID, 'ACTIVE' => 'Y', 'CHECK_PERMISSIONS' => 'N']
            );
            while ($arProp = $rsProps->Fetch()) {
                $arProp['ID'] = (int) $arProp['ID'];
                if ($arOffers['SKU_PROPERTY_ID'] !== $arProp['ID']) {
                    $arProp['USER_TYPE'] = (string) $arProp['USER_TYPE'];
                    $arProp['CODE'] = (string) $arProp['CODE'];
                    $ar_iblock['OFFERS_PROPERTY'][$arProp['ID']] = $arProp;
                    $arProperties[$arProp['ID']] = $arProp;
                    if (in_array($arProp['PROPERTY_TYPE'], $arSelectedPropTypes, true)) {
                        $arSelectOfferProps[] = $arProp['ID'];
                    }
                    if ('' !== $arProp['CODE']) {
                        foreach ($ar_iblock['PROPERTY'] as &$arMainProp) {
                            if ($arMainProp['CODE'] === $arProp['CODE']) {
                                $arPropertyMap[$arProp['ID']] = $arMainProp['CODE'];

                                break;
                            }
                        }
                        if (isset($arMainProp)) {
                            unset($arMainProp);
                        }
                    }
                }
            }
            $arOfferIBlock['LID'] = $ar_iblock['LID'];
        } else {
            $arRunErrors[] = GetMessage('YANDEX_ERR_BAD_OFFERS_IBLOCK_ID');
        }
    }
    if ($boolOffers) {
        if (empty($XML_DATA['SKU_EXPORT'])) {
            $arRunErrors[] = GetMessage('YANDEX_ERR_SKU_SETTINGS_ABSENT');
        } else {
            $arSKUExport = $XML_DATA['SKU_EXPORT'];
            if (empty($arSKUExport['SKU_EXPORT_COND']) || !in_array($arSKUExport['SKU_EXPORT_COND'], $arOffersSelectKeys, true)) {
                $arRunErrors[] = GetMessage('YANDEX_SKU_EXPORT_ERR_CONDITION_ABSENT');
            }
            if (YANDEX_SKU_EXPORT_PROP === $arSKUExport['SKU_EXPORT_COND']) {
                if (empty($arSKUExport['SKU_PROP_COND']) || !is_array($arSKUExport['SKU_PROP_COND'])) {
                    $arRunErrors[] = GetMessage('YANDEX_SKU_EXPORT_ERR_PROPERTY_ABSENT');
                } else {
                    if (empty($arSKUExport['SKU_PROP_COND']['PROP_ID']) || !in_array($arSKUExport['SKU_PROP_COND']['PROP_ID'], $arSelectOfferProps, true)) {
                        $arRunErrors[] = GetMessage('YANDEX_SKU_EXPORT_ERR_PROPERTY_ABSENT');
                    }
                    if (empty($arSKUExport['SKU_PROP_COND']['COND']) || !in_array($arSKUExport['SKU_PROP_COND']['COND'], $arCondSelectProp, true)) {
                        $arRunErrors[] = GetMessage('YANDEX_SKU_EXPORT_ERR_PROPERTY_COND_ABSENT');
                    } else {
                        if ('EQUAL' === $arSKUExport['SKU_PROP_COND']['COND'] || 'NONEQUAL' === $arSKUExport['SKU_PROP_COND']['COND']) {
                            if (empty($arSKUExport['SKU_PROP_COND']['VALUES'])) {
                                $arRunErrors[] = GetMessage('YANDEX_SKU_EXPORT_ERR_PROPERTY_VALUES_ABSENT');
                            }
                        }
                    }
                }
            }
        }
    }
}

$arUserTypeFormat = [];
foreach ($arProperties as $key => $arProperty) {
    $arProperty['USER_TYPE'] = (string) $arProperty['USER_TYPE'];
    $arUserTypeFormat[$arProperty['ID']] = false;
    if ('' !== $arProperty['USER_TYPE']) {
        $arUserType = CIBlockProperty::GetUserType($arProperty['USER_TYPE']);
        if (isset($arUserType['GetPublicViewHTML'])) {
            $arUserTypeFormat[$arProperty['ID']] = $arUserType['GetPublicViewHTML'];
            $arProperties[$key]['PROPERTY_TYPE'] = 'USER_TYPE';
        }
    }
}

$bAllSections = false;
$arSections = [];
if (empty($arRunErrors)) {
    if (is_array($V)) {
        foreach ($V as $key => $value) {
            if ('0' === trim($value)) {
                $bAllSections = true;

                break;
            }
            $value = (int) $value;
            if ($value > 0) {
                $arSections[] = $value;
            }
        }
    }

    if (!$bAllSections && empty($arSections)) {
        $arRunErrors[] = GetMessage('YANDEX_ERR_NO_SECTION_LIST');
    }
}

if (!empty($XML_DATA['PRICE'])) {
    if ((int) $XML_DATA['PRICE'] > 0) {
        $rsCatalogGroups = CCatalogGroup::GetGroupsList(['CATALOG_GROUP_ID' => $XML_DATA['PRICE'], 'GROUP_ID' => 2]);
        if (!($arCatalogGroup = $rsCatalogGroups->Fetch())) {
            $arRunErrors[] = GetMessage('YANDEX_ERR_BAD_PRICE_TYPE');
        }
    } else {
        $arRunErrors[] = GetMessage('YANDEX_ERR_BAD_PRICE_TYPE');
    }
}

$usedProtocol = (isset($USE_HTTPS) && 'Y' === $USE_HTTPS ? 'https://' : 'http://');
$filterAvailable = (isset($FILTER_AVAILABLE) && 'Y' === $FILTER_AVAILABLE);
$disableReferers = (isset($DISABLE_REFERERS) && 'Y' === $DISABLE_REFERERS);

if ('' === $SETUP_FILE_NAME) {
    $arRunErrors[] = GetMessage('CATI_NO_SAVE_FILE');
} elseif (preg_match(BX_CATALOG_FILENAME_REG, $SETUP_FILE_NAME)) {
    $arRunErrors[] = GetMessage('CES_ERROR_BAD_EXPORT_FILENAME');
} else {
    $SETUP_FILE_NAME = Rel2Abs('/', $SETUP_FILE_NAME);
}
if (empty($arRunErrors)) {
    /*	if ($GLOBALS["APPLICATION"]->GetFileAccessPermission($SETUP_FILE_NAME) < "W")
        {
            $arRunErrors[] = str_replace('#FILE#', $SETUP_FILE_NAME,GetMessage('YANDEX_ERR_FILE_ACCESS_DENIED'));
        } */
}

if (empty($arRunErrors)) {
    CheckDirPath($_SERVER['DOCUMENT_ROOT'].$SETUP_FILE_NAME);

    if (!$fp = @fopen($_SERVER['DOCUMENT_ROOT'].$SETUP_FILE_NAME, 'w')) {
        $arRunErrors[] = str_replace('#FILE#', $_SERVER['DOCUMENT_ROOT'].$SETUP_FILE_NAME, GetMessage('YANDEX_ERR_FILE_OPEN_WRITING'));
    } else {
        if (!@fwrite($fp, '<? $disableReferers = '.($disableReferers ? 'true' : 'false').';'."\n")) {
            $arRunErrors[] = str_replace('#FILE#', $_SERVER['DOCUMENT_ROOT'].$SETUP_FILE_NAME, GetMessage('YANDEX_ERR_SETUP_FILE_WRITE'));
            @fclose($fp);
        } else {
            if (!$disableReferers) {
                fwrite($fp, 'if (!isset($_GET["referer1"]) || strlen($_GET["referer1"])<=0) $_GET["referer1"] = "yandext";'."\n");
                fwrite($fp, 'if (!isset($_GET["referer1"]) || strlen($_GET["referer1"])<=0) $_GET["referer1"] = "yandext";'."\n");
                fwrite($fp, '$strReferer1 = htmlspecialchars($_GET["referer1"]);'."\n");
                fwrite($fp, 'if (!isset($_GET["referer2"]) || strlen($_GET["referer2"]) <= 0) $_GET["referer2"] = "";'."\n");
                fwrite($fp, '$strReferer2 = htmlspecialchars($_GET["referer2"]);'."\n");
            }
        }
    }
}

if (empty($arRunErrors)) {
    // @noinspection PhpUndefinedVariableInspection
    fwrite($fp, 'header("Content-Type: text/xml; charset=windows-1251");'."\n");
    fwrite($fp, 'echo "<"."?xml version=\"1.0\" encoding=\"windows-1251\"?".">"?>');
    fwrite($fp, "\n".'<!DOCTYPE yml_catalog SYSTEM "shops.dtd">'."\n");
    fwrite($fp, '<yml_catalog date="'.date('Y-m-d H:i').'">'."\n");
    fwrite($fp, '<shop>'."\n");

    fwrite($fp, '<name>'.$APPLICATION->ConvertCharset(htmlspecialcharsbx(COption::GetOptionString('main', 'site_name', '')), LANG_CHARSET, 'windows-1251')."</name>\n");

    fwrite($fp, '<company>'.$APPLICATION->ConvertCharset(htmlspecialcharsbx(COption::GetOptionString('main', 'site_name', '')), LANG_CHARSET, 'windows-1251')."</company>\n");
    fwrite($fp, '<url>'.$usedProtocol.htmlspecialcharsbx($ar_iblock['SERVER_NAME'])."</url>\n");
    fwrite($fp, '<platform>1C-Bitrix</platform>'."\n");

    $strTmp = '<currencies>'."\n";

    $RUR = 'RUB';
    $currencyIterator = Currency\CurrencyTable::getList([
        'select' => ['CURRENCY'],
        'filter' => ['=CURRENCY' => 'RUR'],
    ]);
    if ($currency = $currencyIterator->fetch()) {
        $RUR = 'RUR';
    }
    unset($currency, $currencyIterator);

    $arCurrencyAllowed = [$RUR, 'USD', 'EUR', 'UAH', 'BYR', 'BYN', 'KZT'];

    $BASE_CURRENCY = Currency\CurrencyManager::getBaseCurrency();
    if (is_array($XML_DATA['CURRENCY'])) {
        foreach ($XML_DATA['CURRENCY'] as $CURRENCY => $arCurData) {
            if (in_array($CURRENCY, $arCurrencyAllowed, true)) {
                $strTmp .= '<currency id="'.$CURRENCY.'"'
                .' rate="'.('SITE' === $arCurData['rate'] ? CCurrencyRates::ConvertCurrency(1, $CURRENCY, $RUR) : $arCurData['rate']).'"'
                .($arCurData['plus'] > 0 ? ' plus="'.(int) $arCurData['plus'].'"' : '')
                ." />\n";
            }
        }
        unset($CURRENCY, $arCurData);
    } else {
        $currencyIterator = Currency\CurrencyTable::getList([
            'select' => ['CURRENCY', 'SORT'],
            'filter' => ['@CURRENCY' => $arCurrencyAllowed],
            'order' => ['SORT' => 'ASC', 'CURRENCY' => 'ASC'],
        ]);
        while ($currency = $currencyIterator->fetch()) {
            $strTmp .= '<currency id="'.$currency['CURRENCY'].'" rate="'.CCurrencyRates::ConvertCurrency(1, $currency['CURRENCY'], $RUR).'" />'."\n";
        }
        unset($currency, $currencyIterator);
    }
    $strTmp .= "</currencies>\n";

    fwrite($fp, $strTmp);
    unset($strTmp);

    // *****************************************//

    // *****************************************//
    $intMaxSectionID = 0;

    $strTmpCat = '';
    $strTmpOff = '';

    $arSectionIDs = [];
    $arAvailGroups = [];
    if (!$bAllSections) {
        for ($i = 0, $intSectionsCount = count($arSections); $i < $intSectionsCount; ++$i) {
            $sectionIterator = CIBlockSection::GetNavChain($IBLOCK_ID, $arSections[$i], ['ID', 'IBLOCK_SECTION_ID', 'NAME', 'LEFT_MARGIN', 'RIGHT_MARGIN']);
            $curLEFT_MARGIN = 0;
            $curRIGHT_MARGIN = 0;
            while ($section = $sectionIterator->Fetch()) {
                $section['ID'] = (int) $section['ID'];
                $section['IBLOCK_SECTION_ID'] = (int) $section['IBLOCK_SECTION_ID'];
                if ($arSections[$i] === $section['ID']) {
                    $curLEFT_MARGIN = (int) $section['LEFT_MARGIN'];
                    $curRIGHT_MARGIN = (int) $section['RIGHT_MARGIN'];
                    $arSectionIDs[] = $section['ID'];
                }
                $arAvailGroups[$section['ID']] = [
                    'ID' => $section['ID'],
                    'IBLOCK_SECTION_ID' => $section['IBLOCK_SECTION_ID'],
                    'NAME' => $section['NAME'],
                ];
                if ($intMaxSectionID < $section['ID']) {
                    $intMaxSectionID = $section['ID'];
                }
            }
            unset($section, $sectionIterator);

            $filter = ['IBLOCK_ID' => $IBLOCK_ID, '>LEFT_MARGIN' => $curLEFT_MARGIN, '<RIGHT_MARGIN' => $curRIGHT_MARGIN, 'ACTIVE' => 'Y', 'IBLOCK_ACTIVE' => 'Y', 'GLOBAL_ACTIVE' => 'Y'];
            $sectionIterator = CIBlockSection::GetList(['LEFT_MARGIN' => 'ASC'], $filter, false, ['ID', 'IBLOCK_SECTION_ID', 'NAME']);
            while ($section = $sectionIterator->Fetch()) {
                $section['ID'] = (int) $section['ID'];
                $section['IBLOCK_SECTION_ID'] = (int) $section['IBLOCK_SECTION_ID'];
                $arSectionIDs[] = $section['ID'];
                $arAvailGroups[$section['ID']] = $section;
                if ($intMaxSectionID < $section['ID']) {
                    $intMaxSectionID = $section['ID'];
                }
            }
            unset($section, $sectionIterator);
        }
        if (!empty($arSectionIDs)) {
            $arSectionIDs = array_unique($arSectionIDs);
        }
    } else {
        $filter = ['IBLOCK_ID' => $IBLOCK_ID, 'ACTIVE' => 'Y', 'IBLOCK_ACTIVE' => 'Y', 'GLOBAL_ACTIVE' => 'Y'];
        $sectionIterator = CIBlockSection::GetList(['LEFT_MARGIN' => 'ASC'], $filter, false, ['ID', 'IBLOCK_SECTION_ID', 'NAME']);
        while ($section = $sectionIterator->Fetch()) {
            $section['ID'] = (int) $section['ID'];
            $section['IBLOCK_SECTION_ID'] = (int) $section['IBLOCK_SECTION_ID'];
            $arAvailGroups[$section['ID']] = $section;
            if ($intMaxSectionID < $section['ID']) {
                $intMaxSectionID = $section['ID'];
            }
        }
        unset($section, $sectionIterator);

        if (!empty($arAvailGroups)) {
            $arSectionIDs = array_keys($arAvailGroups);
        }
    }

    foreach ($arAvailGroups as &$value) {
        $strTmpCat .= '<category id="'.$value['ID'].'"'.($value['IBLOCK_SECTION_ID'] > 0 ? ' parentId="'.$value['IBLOCK_SECTION_ID'].'"' : '').'>'.yandex_text2xml($value['NAME'], true).'</category>'."\n";
    }
    if (isset($value)) {
        unset($value);
    }

    $intMaxSectionID += 100_000_000;

    // *****************************************//
    $boolNeedRootSection = false;

    CCatalogProduct::setPriceVatIncludeMode(true);
    CCatalogProduct::setUsedCurrency($BASE_CURRENCY);
    CCatalogProduct::setUseDiscount(true);

    if (CCatalogSku::TYPE_CATALOG === $arCatalog['CATALOG_TYPE'] || CCatalogSku::TYPE_OFFERS === $arCatalog['CATALOG_TYPE']) {
        $arSelect = [
            'ID', 'LID', 'IBLOCK_ID', 'IBLOCK_SECTION_ID', 'NAME',
            'PREVIEW_PICTURE', 'PREVIEW_TEXT', 'PREVIEW_TEXT_TYPE', 'DETAIL_PICTURE', 'LANG_DIR', 'DETAIL_PAGE_URL',
            'CATALOG_AVAILABLE',
        ];

        $filter = ['IBLOCK_ID' => $IBLOCK_ID];
        if (!$bAllSections && !empty($arSectionIDs)) {
            $filter['INCLUDE_SUBSECTIONS'] = 'Y';
            $filter['SECTION_ID'] = $arSectionIDs;
        }
        $filter['ACTIVE'] = 'Y';
        $filter['ACTIVE_DATE'] = 'Y';
        if ($filterAvailable) {
            $filter['CATALOG_AVAILABLE'] = 'Y';
        }
        $res = CIBlockElement::GetList(['ID' => 'ASC'], $filter, false, false, $arSelect);

        $total_sum = 0;
        $is_exists = false;
        $cnt = 0;
        while ($obElement = $res->GetNextElement()) {
            ++$cnt;
            $arAcc = $obElement->GetFields();
            if ($needProperties) {
                $arAcc['PROPERTIES'] = $obElement->GetProperties();
            }

            $str_AVAILABLE = ' available="'.('Y' === $arAcc['CATALOG_AVAILABLE'] ? 'true' : 'false').'"';

            $fullPrice = 0;
            $minPrice = 0;
            $minPriceRUR = 0;
            $minPriceGroup = 0;
            $minPriceCurrency = '';

            if ($XML_DATA['PRICE'] > 0) {
                $rsPrices = CPrice::GetListEx(
                    [],
                    [
                        'PRODUCT_ID' => $arAcc['ID'],
                        'CATALOG_GROUP_ID' => $XML_DATA['PRICE'],
                        'CAN_BUY' => 'Y',
                        'GROUP_GROUP_ID' => [2],
                        '+<=QUANTITY_FROM' => 1,
                        '+>=QUANTITY_TO' => 1,
                    ]
                );
                if ($arPrice = $rsPrices->Fetch()) {
                    if ($arOptimalPrice = CCatalogProduct::GetOptimalPrice(
                        $arAcc['ID'],
                        1,
                        [2], // anonymous
                        'N',
                        [$arPrice],
                        $ar_iblock['LID'],
                        []
                    )) {
                        $minPrice = $arOptimalPrice['RESULT_PRICE']['DISCOUNT_PRICE'];
                        $fullPrice = $arOptimalPrice['RESULT_PRICE']['BASE_PRICE'];
                        $minPriceCurrency = $arOptimalPrice['RESULT_PRICE']['CURRENCY'];
                        if ($minPriceCurrency === $RUR) {
                            $minPriceRUR = $minPrice;
                        } else {
                            $minPriceRUR = CCurrencyRates::ConvertCurrency($minPrice, $minPriceCurrency, $RUR);
                        }
                        $minPriceGroup = $arOptimalPrice['PRICE']['CATALOG_GROUP_ID'];
                    }
                }
            } else {
                if ($arPrice = CCatalogProduct::GetOptimalPrice(
                    $arAcc['ID'],
                    1,
                    [2], // anonymous
                    'N',
                    [],
                    $ar_iblock['LID'],
                    []
                )) {
                    $minPrice = $arPrice['RESULT_PRICE']['DISCOUNT_PRICE'];
                    $fullPrice = $arPrice['RESULT_PRICE']['BASE_PRICE'];
                    $minPriceCurrency = $arPrice['RESULT_PRICE']['CURRENCY'];
                    if ($minPriceCurrency === $RUR) {
                        $minPriceRUR = $minPrice;
                    } else {
                        $minPriceRUR = CCurrencyRates::ConvertCurrency($minPrice, $minPriceCurrency, $RUR);
                    }
                    $minPriceGroup = $arPrice['PRICE']['CATALOG_GROUP_ID'];
                }
            }

            if ($minPrice <= 0) {
                continue;
            }

            $boolCurrentSections = false;
            $bNoActiveGroup = true;
            $strTmpOff_tmp = '';
            $db_res1 = CIBlockElement::GetElementGroups($arAcc['ID'], false, ['ID', 'ADDITIONAL_PROPERTY_ID']);
            while ($ar_res1 = $db_res1->Fetch()) {
                if (0 < (int) $ar_res1['ADDITIONAL_PROPERTY_ID']) {
                    continue;
                }
                $boolCurrentSections = true;
                if (in_array((int) $ar_res1['ID'], $arSectionIDs, true)) {
                    $strTmpOff_tmp .= '<categoryId>'.$ar_res1['ID']."</categoryId>\n";
                    $bNoActiveGroup = false;
                }
            }
            if (!$boolCurrentSections) {
                $boolNeedRootSection = true;
                $strTmpOff_tmp .= '<categoryId>'.$intMaxSectionID."</categoryId>\n";
            } else {
                if ($bNoActiveGroup) {
                    continue;
                }
            }

            if ('' === $arAcc['DETAIL_PAGE_URL']) {
                $arAcc['DETAIL_PAGE_URL'] = '/';
            } else {
                $arAcc['DETAIL_PAGE_URL'] = str_replace(' ', '%20', $arAcc['DETAIL_PAGE_URL']);
            }

            $strTmpOff .= '<offer id="'.$arAcc['ID'].'"'.$productFormat.$str_AVAILABLE.">\n";
            $referer = '';
            if (!$disableReferers) {
                $referer = (!str_contains($arAcc['DETAIL_PAGE_URL'], '?') ? '?' : '&amp;').'r1=<?=$strReferer1; ?>&amp;r2=<?=$strReferer2; ?>';
            }

            $strTmpOff .= '<url>'.$usedProtocol.$ar_iblock['SERVER_NAME'].htmlspecialcharsbx($arAcc['~DETAIL_PAGE_URL']).$referer."</url>\n";

            $strTmpOff .= '<price>'.$minPrice."</price>\n";
            if ($minPrice < $fullPrice) {
                $strTmpOff .= '<oldprice>'.$fullPrice."</oldprice>\n";
            }
            $strTmpOff .= '<currencyId>'.$minPriceCurrency."</currencyId>\n";

            $strTmpOff .= $strTmpOff_tmp;

            $arAcc['DETAIL_PICTURE'] = (int) $arAcc['DETAIL_PICTURE'];
            $arAcc['PREVIEW_PICTURE'] = (int) $arAcc['PREVIEW_PICTURE'];
            if ($arAcc['DETAIL_PICTURE'] > 0 || $arAcc['PREVIEW_PICTURE'] > 0) {
                $pictNo = ($arAcc['DETAIL_PICTURE'] > 0 ? $arAcc['DETAIL_PICTURE'] : $arAcc['PREVIEW_PICTURE']);

                if ($ar_file = CFile::GetFileArray($pictNo)) {
                    if ('/' === substr($ar_file['SRC'], 0, 1)) {
                        $strFile = $usedProtocol.$ar_iblock['SERVER_NAME'].CHTTP::urnEncode($ar_file['SRC'], 'utf-8');
                    } else {
                        $strFile = $ar_file['SRC'];
                    }
                    $strTmpOff .= '<picture>'.$strFile."</picture>\n";
                }
            }

            $y = 0;
            foreach ($arYandexFields as $key) {
                switch ($key) {
                    case 'name':
                        if ('vendor.model' === $yandexFormat || 'artist.title' === $yandexFormat) {
                            break;
                        }

                        $strTmpOff .= '<name>'.yandex_text2xml($arAcc['~NAME'], true)."</name>\n";

                        break;

                    case 'description':
                        $strTmpOff .=
                            '<description>'.
                            yandex_text2xml(TruncateText(
                                'html' === $arAcc['PREVIEW_TEXT_TYPE'] ?
                                strip_tags(preg_replace_callback("'&[^;]*;'", 'yandex_replace_special', $arAcc['~PREVIEW_TEXT'])) : preg_replace_callback("'&[^;]*;'", 'yandex_replace_special', $arAcc['~PREVIEW_TEXT']),
                                255
                            ), true).
                            "</description>\n";

                        break;

                    case 'param':
                        if ($parametricFieldsExist) {
                            foreach ($parametricFields as $paramKey => $prop_id) {
                                $strParamValue = '';
                                if ($prop_id) {
                                    $strParamValue = yandex_get_value($arAcc, 'PARAM_'.$paramKey, $prop_id, $arProperties, $arUserTypeFormat, $usedProtocol);
                                }
                                if ('' !== $strParamValue) {
                                    $strTmpOff .= $strParamValue."\n";
                                }
                            }
                            unset($paramKey, $prop_id);
                        }

                        break;

                    case 'model':
                    case 'title':
                        if (!$fieldsExist || !isset($fields[$key])) {
                            if (
                                'model' === $key && 'vendor.model' === $yandexFormat
                                || 'title' === $key && 'artist.title' === $yandexFormat
                            ) {
                                $strTmpOff .= '<'.$key.'>'.yandex_text2xml($arAcc['~NAME'], true).'</'.$key.">\n";
                            }
                        } else {
                            $strValue = '';
                            $strValue = yandex_get_value($arAcc, $key, $fields[$key], $arProperties, $arUserTypeFormat, $usedProtocol);
                            if ('' !== $strValue) {
                                $strTmpOff .= $strValue."\n";
                            }
                        }

                        break;

                    case 'year':
                    default:
                        if ('year' === $key) {
                            ++$y;
                            if ('artist.title' === $yandexFormat) {
                                if (1 === $y) {
                                    break;
                                }
                            } else {
                                if ($y > 1) {
                                    break;
                                }
                            }
                        }
                        if ($fieldsExist && isset($fields[$key])) {
                            $strValue = '';
                            $strValue = yandex_get_value($arAcc, $key, $fields[$key], $arProperties, $arUserTypeFormat, $usedProtocol);
                            if ('' !== $strValue) {
                                $strTmpOff .= $strValue."\n";
                            }
                        }
                }
            }

            $strTmpOff .= "</offer>\n";
            if (100 <= $cnt) {
                $cnt = 0;
                CCatalogDiscount::ClearDiscountCache([
                    'PRODUCT' => true,
                    'SECTIONS' => true,
                    'PROPERTIES' => true,
                ]);
            }
        }
    } elseif (CCatalogSku::TYPE_PRODUCT === $arCatalog['CATALOG_TYPE'] || CCatalogSku::TYPE_FULL === $arCatalog['CATALOG_TYPE']) {
        $arOfferSelect = [
            'ID', 'LID', 'IBLOCK_ID', 'NAME',
            'PREVIEW_PICTURE', 'PREVIEW_TEXT', 'PREVIEW_TEXT_TYPE', 'DETAIL_PICTURE', 'DETAIL_PAGE_URL',
            'CATALOG_AVAILABLE', 'CATALOG_TYPE',
        ];
        $arOfferFilter = ['IBLOCK_ID' => $intOfferIBlockID, '=PROPERTY_'.$arOffers['SKU_PROPERTY_ID'] => 0, 'ACTIVE' => 'Y', 'ACTIVE_DATE' => 'Y'];
        if (YANDEX_SKU_EXPORT_PROP === $arSKUExport['SKU_EXPORT_COND']) {
            $strExportKey = '';
            $mxValues = false;
            if ('NONZERO' === $arSKUExport['SKU_PROP_COND']['COND'] || 'NONEQUAL' === $arSKUExport['SKU_PROP_COND']['COND']) {
                $strExportKey = '!';
            }
            $strExportKey .= 'PROPERTY_'.$arSKUExport['SKU_PROP_COND']['PROP_ID'];
            if ('EQUAL' === $arSKUExport['SKU_PROP_COND']['COND'] || 'NONEQUAL' === $arSKUExport['SKU_PROP_COND']['COND']) {
                $mxValues = $arSKUExport['SKU_PROP_COND']['VALUES'];
            }
            $arOfferFilter[$strExportKey] = $mxValues;
        }
        if ($filterAvailable) {
            $arOfferFilter['CATALOG_AVAILABLE'] = 'Y';
        }

        $arSelect = [
            'ID', 'LID', 'IBLOCK_ID', 'IBLOCK_SECTION_ID', 'NAME',
            'PREVIEW_PICTURE', 'PREVIEW_TEXT', 'PREVIEW_TEXT_TYPE', 'DETAIL_PICTURE', 'DETAIL_PAGE_URL',
            'CATALOG_AVAILABLE', 'CATALOG_TYPE',
        ];

        $arFilter = ['IBLOCK_ID' => $IBLOCK_ID];
        if (!$bAllSections && !empty($arSectionIDs)) {
            $arFilter['INCLUDE_SUBSECTIONS'] = 'Y';
            $arFilter['SECTION_ID'] = $arSectionIDs;
        }
        $arFilter['ACTIVE'] = 'Y';
        $arFilter['ACTIVE_DATE'] = 'Y';
        if ($filterAvailable) {
            $arFilter['CATALOG_AVAILABLE'] = 'Y';
        }

        $strOfferTemplateURL = '';
        if (!empty($arSKUExport['SKU_URL_TEMPLATE_TYPE'])) {
            switch ($arSKUExport['SKU_URL_TEMPLATE_TYPE']) {
                case YANDEX_SKU_TEMPLATE_PRODUCT:
                    $strOfferTemplateURL = '#PRODUCT_URL#';

                    break;

                case YANDEX_SKU_TEMPLATE_CUSTOM:
                    if (!empty($arSKUExport['SKU_URL_TEMPLATE'])) {
                        $strOfferTemplateURL = $arSKUExport['SKU_URL_TEMPLATE'];
                    }

                    break;

                case YANDEX_SKU_TEMPLATE_OFFERS:
                default:
                    $strOfferTemplateURL = '';

                    break;
            }
        }

        $cnt = 0;
        $rsItems = CIBlockElement::GetList(['ID' => 'ASC'], $arFilter, false, false, $arSelect);
        while ($obItem = $rsItems->GetNextElement()) {
            ++$cnt;
            $arCross = [];
            $arItem = $obItem->GetFields();
            $arItem['PROPERTIES'] = $obItem->GetProperties();
            if (!empty($arItem['PROPERTIES'])) {
                foreach ($arItem['PROPERTIES'] as &$arProp) {
                    $arCross[$arProp['ID']] = $arProp;
                }
                if (isset($arProp)) {
                    unset($arProp);
                }
                $arItem['PROPERTIES'] = $arCross;
            }
            $boolItemExport = false;
            $boolItemOffers = false;
            $arItem['OFFERS'] = [];

            $boolCurrentSections = false;
            $boolNoActiveSections = true;
            $strSections = '';
            $rsSections = CIBlockElement::GetElementGroups($arItem['ID'], false, ['ID', 'ADDITIONAL_PROPERTY_ID']);
            while ($arSection = $rsSections->Fetch()) {
                if (0 < (int) $arSection['ADDITIONAL_PROPERTY_ID']) {
                    continue;
                }
                $arSection['ID'] = (int) $arSection['ID'];
                $boolCurrentSections = true;
                if (in_array($arSection['ID'], $arSectionIDs, true)) {
                    $strSections .= '<categoryId>'.$arSection['ID']."</categoryId>\n";
                    $boolNoActiveSections = false;
                }
            }
            if (!$boolCurrentSections) {
                $boolNeedRootSection = true;
                $strSections .= '<categoryId>'.$intMaxSectionID."</categoryId>\n";
            } else {
                if ($boolNoActiveSections) {
                    continue;
                }
            }

            $arItem['YANDEX_CATEGORY'] = $strSections;

            $strFile = '';
            $arItem['DETAIL_PICTURE'] = (int) $arItem['DETAIL_PICTURE'];
            $arItem['PREVIEW_PICTURE'] = (int) $arItem['PREVIEW_PICTURE'];
            if ($arItem['DETAIL_PICTURE'] > 0 || $arItem['PREVIEW_PICTURE'] > 0) {
                $pictNo = ($arItem['DETAIL_PICTURE'] > 0 ? $arItem['DETAIL_PICTURE'] : $arItem['PREVIEW_PICTURE']);

                if ($ar_file = CFile::GetFileArray($pictNo)) {
                    if ('/' === substr($ar_file['SRC'], 0, 1)) {
                        $strFile = $usedProtocol.$ar_iblock['SERVER_NAME'].CHTTP::urnEncode($ar_file['SRC'], 'utf-8');
                    } else {
                        $strFile = $ar_file['SRC'];
                    }
                }
            }
            $arItem['YANDEX_PICT'] = $strFile;

            $arItem['YANDEX_DESCR'] = yandex_text2xml(TruncateText(
                'html' === $arItem['PREVIEW_TEXT_TYPE'] ?
                            strip_tags(preg_replace_callback("'&[^;]*;'", 'yandex_replace_special', $arItem['~PREVIEW_TEXT'])) : preg_replace_callback("'&[^;]*;'", 'yandex_replace_special', $arItem['~PREVIEW_TEXT']),
                255
            ), true);

            if (Catalog\ProductTable::TYPE_SKU === $arItem['CATALOG_TYPE']) {
                $arOfferFilter['=PROPERTY_'.$arOffers['SKU_PROPERTY_ID']] = $arItem['ID'];
                $rsOfferItems = CIBlockElement::GetList(['ID' => 'ASC'], $arOfferFilter, false, false, $arOfferSelect);

                if (!empty($strOfferTemplateURL)) {
                    $rsOfferItems->SetUrlTemplates($strOfferTemplateURL);
                }
                if (YANDEX_SKU_EXPORT_MIN_PRICE === $arSKUExport['SKU_EXPORT_COND']) {
                    $arCurrentOffer = false;
                    $arCurrentPrice = false;
                    $dblAllMinPrice = 0;
                    $boolFirst = true;

                    while ($obOfferItem = $rsOfferItems->GetNextElement()) {
                        $arOfferItem = $obOfferItem->GetFields();
                        $fullPrice = 0;
                        $minPrice = 0;

                        $minPriceRUR = 0;
                        $minPriceCurrency = '';
                        $minPriceGroup = 0;

                        if ($XML_DATA['PRICE'] > 0) {
                            $rsPrices = CPrice::GetListEx(
                                [],
                                [
                                    'PRODUCT_ID' => $arOfferItem['ID'],
                                    'CATALOG_GROUP_ID' => $XML_DATA['PRICE'],
                                    'CAN_BUY' => 'Y',
                                    'GROUP_GROUP_ID' => [2],
                                    '+<=QUANTITY_FROM' => 1,
                                    '+>=QUANTITY_TO' => 1,
                                ]
                            );
                            if ($arPrice = $rsPrices->Fetch()) {
                                if ($arOptimalPrice = CCatalogProduct::GetOptimalPrice(
                                    $arOfferItem['ID'],
                                    1,
                                    [2],
                                    'N',
                                    [$arPrice],
                                    $arOfferIBlock['LID'],
                                    []
                                )
                                ) {
                                    $minPrice = $arOptimalPrice['RESULT_PRICE']['DISCOUNT_PRICE'];
                                    $fullPrice = $arOptimalPrice['RESULT_PRICE']['BASE_PRICE'];
                                    $minPriceCurrency = $arOptimalPrice['RESULT_PRICE']['CURRENCY'];
                                    if ($minPriceCurrency === $RUR) {
                                        $minPriceRUR = $minPrice;
                                    } else {
                                        $minPriceRUR = CCurrencyRates::ConvertCurrency($minPrice, $minPriceCurrency, $RUR);
                                    }
                                    $minPriceGroup = $arOptimalPrice['PRICE']['CATALOG_GROUP_ID'];
                                }
                            }
                        } else {
                            if ($arPrice = CCatalogProduct::GetOptimalPrice(
                                $arOfferItem['ID'],
                                1,
                                [2], // anonymous
                                'N',
                                [],
                                $arOfferIBlock['LID'],
                                []
                            )
                            ) {
                                $minPrice = $arPrice['RESULT_PRICE']['DISCOUNT_PRICE'];
                                $fullPrice = $arPrice['RESULT_PRICE']['BASE_PRICE'];
                                $minPriceCurrency = $arPrice['RESULT_PRICE']['CURRENCY'];
                                if ($minPriceCurrency === $RUR) {
                                    $minPriceRUR = $minPrice;
                                } else {
                                    $minPriceRUR = CCurrencyRates::ConvertCurrency($minPrice, $minPriceCurrency, $RUR);
                                }
                                $minPriceGroup = $arPrice['PRICE']['CATALOG_GROUP_ID'];
                            }
                        }
                        if ($minPrice <= 0) {
                            continue;
                        }
                        if ($boolFirst) {
                            $dblAllMinPrice = $minPriceRUR;
                            $arCross = (!empty($arItem['PROPERTIES']) ? $arItem['PROPERTIES'] : []);
                            $arOfferItem['PROPERTIES'] = $obOfferItem->GetProperties();
                            if (!empty($arOfferItem['PROPERTIES'])) {
                                foreach ($arOfferItem['PROPERTIES'] as $arProp) {
                                    $arCross[$arProp['ID']] = $arProp;
                                }
                            }
                            $arOfferItem['PROPERTIES'] = $arCross;

                            $arCurrentOffer = $arOfferItem;
                            $arCurrentPrice = [
                                'FULL_PRICE' => $fullPrice,
                                'MIN_PRICE' => $minPrice,
                                'MIN_PRICE_CURRENCY' => $minPriceCurrency,
                                'MIN_PRICE_RUR' => $minPriceRUR,
                                'MIN_PRICE_GROUP' => $minPriceGroup,
                            ];
                            $boolFirst = false;
                        } else {
                            if ($dblAllMinPrice > $minPriceRUR) {
                                $dblAllMinPrice = $minPriceRUR;
                                $arCross = (!empty($arItem['PROPERTIES']) ? $arItem['PROPERTIES'] : []);
                                $arOfferItem['PROPERTIES'] = $obOfferItem->GetProperties();
                                if (!empty($arOfferItem['PROPERTIES'])) {
                                    foreach ($arOfferItem['PROPERTIES'] as $arProp) {
                                        $arCross[$arProp['ID']] = $arProp;
                                    }
                                }
                                $arOfferItem['PROPERTIES'] = $arCross;

                                $arCurrentOffer = $arOfferItem;
                                $arCurrentPrice = [
                                    'FULL_PRICE' => $fullPrice,
                                    'MIN_PRICE' => $minPrice,
                                    'MIN_PRICE_CURRENCY' => $minPriceCurrency,
                                    'MIN_PRICE_RUR' => $minPriceRUR,
                                    'MIN_PRICE_GROUP' => $minPriceGroup,
                                ];
                            }
                        }
                    }
                    if (!empty($arCurrentOffer) && !empty($arCurrentPrice)) {
                        $arOfferItem = $arCurrentOffer;
                        $fullPrice = $arCurrentPrice['FULL_PRICE'];
                        $minPrice = $arCurrentPrice['MIN_PRICE'];
                        $minPriceCurrency = $arCurrentPrice['MIN_PRICE_CURRENCY'];
                        $minPriceRUR = $arCurrentPrice['MIN_PRICE_RUR'];
                        $minPriceGroup = $arCurrentPrice['MIN_PRICE_GROUP'];

                        $arOfferItem['YANDEX_AVAILABLE'] = ('Y' === $arOfferItem['CATALOG_AVAILABLE'] ? 'true' : 'false');

                        if ('' === $arOfferItem['DETAIL_PAGE_URL']) {
                            $arOfferItem['DETAIL_PAGE_URL'] = '/';
                        } else {
                            $arOfferItem['DETAIL_PAGE_URL'] = str_replace(' ', '%20', $arOfferItem['DETAIL_PAGE_URL']);
                        }

                        $arOfferItem['YANDEX_TYPE'] = $productFormat;

                        $strOfferYandex = '';
                        $strOfferYandex .= '<offer id="'.$arOfferItem['ID'].'"'.$productFormat.' available="'.$arOfferItem['YANDEX_AVAILABLE'].'">'."\n";
                        $referer = '';
                        if (!$disableReferers) {
                            $referer = (!str_contains($arOfferItem['DETAIL_PAGE_URL'], '?') ? '?' : '&amp;').'r1=<?=$strReferer1; ?>&amp;r2=<?=$strReferer2; ?>';
                        }

                        $strOfferYandex .= '<url>'.$usedProtocol.$ar_iblock['SERVER_NAME'].htmlspecialcharsbx($arOfferItem['~DETAIL_PAGE_URL']).$referer."</url>\n";

                        $strOfferYandex .= '<price>'.$minPrice."</price>\n";
                        if ($minPrice < $fullPrice) {
                            $strOfferYandex .= '<oldprice>'.$fullPrice."</oldprice>\n";
                        }
                        $strOfferYandex .= '<currencyId>'.$minPriceCurrency."</currencyId>\n";

                        $strOfferYandex .= $arItem['YANDEX_CATEGORY'];

                        $strFile = '';
                        $arOfferItem['DETAIL_PICTURE'] = (int) $arOfferItem['DETAIL_PICTURE'];
                        $arOfferItem['PREVIEW_PICTURE'] = (int) $arOfferItem['PREVIEW_PICTURE'];
                        if ($arOfferItem['DETAIL_PICTURE'] > 0 || $arOfferItem['PREVIEW_PICTURE'] > 0) {
                            $pictNo = ($arOfferItem['DETAIL_PICTURE'] > 0 ? $arOfferItem['DETAIL_PICTURE'] : $arOfferItem['PREVIEW_PICTURE']);

                            if ($ar_file = CFile::GetFileArray($pictNo)) {
                                if ('/' === substr($ar_file['SRC'], 0, 1)) {
                                    $strFile = $usedProtocol.$ar_iblock['SERVER_NAME'].CHTTP::urnEncode($ar_file['SRC'], 'utf-8');
                                } else {
                                    $strFile = $ar_file['SRC'];
                                }
                            }
                        }
                        if (!empty($strFile) || !empty($arItem['YANDEX_PICT'])) {
                            $strOfferYandex .= '<picture>'.(!empty($strFile) ? $strFile : $arItem['YANDEX_PICT'])."</picture>\n";
                        }

                        $y = 0;
                        foreach ($arYandexFields as $key) {
                            switch ($key) {
                                case 'name':
                                    if ('vendor.model' === $yandexFormat || 'artist.title' === $yandexFormat) {
                                        break;
                                    }

                                    $strOfferYandex .= '<name>'.yandex_text2xml($arOfferItem['~NAME'], true)."</name>\n";

                                    break;

                                case 'description':
                                    $strOfferYandex .= '<description>';
                                    if ('' === $arOfferItem['~PREVIEW_TEXT']) {
                                        $strOfferYandex .= $arItem['YANDEX_DESCR'];
                                    } else {
                                        $strOfferYandex .= yandex_text2xml(
                                            TruncateText(
                                                'html' === $arOfferItem['PREVIEW_TEXT_TYPE'] ?
                                                    strip_tags(preg_replace_callback("'&[^;]*;'", 'yandex_replace_special', $arOfferItem['~PREVIEW_TEXT'])) : $arOfferItem['~PREVIEW_TEXT'],
                                                255
                                            ),
                                            true
                                        );
                                    }
                                    $strOfferYandex .= "</description>\n";

                                    break;

                                case 'param':
                                    if ($parametricFieldsExist) {
                                        foreach ($parametricFields as $paramKey => $prop_id) {
                                            $strParamValue = '';
                                            if ($prop_id) {
                                                $strParamValue = yandex_get_value($arOfferItem, 'PARAM_'.$paramKey, $prop_id, $arProperties, $arUserTypeFormat, $usedProtocol);
                                            }
                                            if ('' !== $strParamValue) {
                                                $strOfferYandex .= $strParamValue."\n";
                                            }
                                        }
                                        unset($paramKey, $prop_id);
                                    }

                                    break;

                                case 'model':
                                case 'title':
                                    if (!$fieldsExist || !isset($fields[$key])) {
                                        if (
                                            'model' === $key && 'vendor.model' === $yandexFormat
                                            || 'title' === $key && 'artist.title' === $yandexFormat
                                        ) {
                                            $strOfferYandex .= '<'.$key.'>'.yandex_text2xml($arOfferItem['~NAME'], true).'</'.$key.">\n";
                                        }
                                    } else {
                                        $strValue = '';
                                        $strValue = yandex_get_value($arOfferItem, $key, $fields[$key], $arProperties, $arUserTypeFormat, $usedProtocol);
                                        if ('' !== $strValue) {
                                            $strOfferYandex .= $strValue."\n";
                                        }
                                    }

                                    break;

                                case 'year':
                                default:
                                    if ('year' === $key) {
                                        ++$y;
                                        if ('artist.title' === $yandexFormat) {
                                            if (1 === $y) {
                                                break;
                                            }
                                        } else {
                                            if ($y > 1) {
                                                break;
                                            }
                                        }
                                    }
                                    if ($fieldsExist && isset($fields[$key])) {
                                        $strValue = '';
                                        $strValue = yandex_get_value($arOfferItem, $key, $fields[$key], $arProperties, $arUserTypeFormat, $usedProtocol);
                                        if ('' !== $strValue) {
                                            $strOfferYandex .= $strValue."\n";
                                        }
                                    }
                            }
                        }

                        $strOfferYandex .= "</offer>\n";
                        $arItem['OFFERS'][] = $strOfferYandex;
                        $boolItemOffers = true;
                        $boolItemExport = true;
                    }
                } else {
                    while ($obOfferItem = $rsOfferItems->GetNextElement()) {
                        $arOfferItem = $obOfferItem->GetFields();
                        $arCross = (!empty($arItem['PROPERTIES']) ? $arItem['PROPERTIES'] : []);
                        $arOfferItem['PROPERTIES'] = $obOfferItem->GetProperties();
                        if (!empty($arOfferItem['PROPERTIES'])) {
                            foreach ($arOfferItem['PROPERTIES'] as $arProp) {
                                $arCross[$arProp['ID']] = $arProp;
                            }
                        }
                        $arOfferItem['PROPERTIES'] = $arCross;

                        $arOfferItem['YANDEX_AVAILABLE'] = ('Y' === $arOfferItem['CATALOG_AVAILABLE'] ? 'true' : 'false');

                        $fullPrice = 0;
                        $minPrice = 0;

                        $minPriceCurrency = '';

                        if ($XML_DATA['PRICE'] > 0) {
                            $rsPrices = CPrice::GetListEx(
                                [],
                                [
                                    'PRODUCT_ID' => $arOfferItem['ID'],
                                    'CATALOG_GROUP_ID' => $XML_DATA['PRICE'],
                                    'CAN_BUY' => 'Y',
                                    'GROUP_GROUP_ID' => [2],
                                    '+<=QUANTITY_FROM' => 1,
                                    '+>=QUANTITY_TO' => 1,
                                ]
                            );
                            if ($arPrice = $rsPrices->Fetch()) {
                                if ($arOptimalPrice = CCatalogProduct::GetOptimalPrice(
                                    $arOfferItem['ID'],
                                    1,
                                    [2],
                                    'N',
                                    [$arPrice],
                                    $arOfferIBlock['LID'],
                                    []
                                )
                                ) {
                                    $minPrice = $arOptimalPrice['RESULT_PRICE']['DISCOUNT_PRICE'];
                                    $fullPrice = $arOptimalPrice['RESULT_PRICE']['BASE_PRICE'];
                                    $minPriceCurrency = $arOptimalPrice['RESULT_PRICE']['CURRENCY'];
                                    if ($minPriceCurrency === $RUR) {
                                        $minPriceRUR = $minPrice;
                                    } else {
                                        $minPriceRUR = CCurrencyRates::ConvertCurrency($minPrice, $minPriceCurrency, $RUR);
                                    }
                                    $minPriceGroup = $arOptimalPrice['PRICE']['CATALOG_GROUP_ID'];
                                }
                            }
                        } else {
                            if ($arPrice = CCatalogProduct::GetOptimalPrice(
                                $arOfferItem['ID'],
                                1,
                                [2], // anonymous
                                'N',
                                [],
                                $arOfferIBlock['LID'],
                                []
                            )
                            ) {
                                $minPrice = $arPrice['RESULT_PRICE']['DISCOUNT_PRICE'];
                                $fullPrice = $arPrice['RESULT_PRICE']['BASE_PRICE'];
                                $minPriceCurrency = $arPrice['RESULT_PRICE']['CURRENCY'];
                                if ($minPriceCurrency === $RUR) {
                                    $minPriceRUR = $minPrice;
                                } else {
                                    $minPriceRUR = CCurrencyRates::ConvertCurrency($minPrice, $minPriceCurrency, $RUR);
                                }
                                $minPriceGroup = $arPrice['PRICE']['CATALOG_GROUP_ID'];
                            }
                        }
                        if ($minPrice <= 0) {
                            continue;
                        }

                        if ('' === $arOfferItem['DETAIL_PAGE_URL']) {
                            $arOfferItem['DETAIL_PAGE_URL'] = '/';
                        } else {
                            $arOfferItem['DETAIL_PAGE_URL'] = str_replace(' ', '%20', $arOfferItem['DETAIL_PAGE_URL']);
                        }

                        $arOfferItem['YANDEX_TYPE'] = $productFormat;

                        $strOfferYandex = '';
                        $strOfferYandex .= '<offer id="'.$arOfferItem['ID'].'"'.$productFormat.' available="'.$arOfferItem['YANDEX_AVAILABLE'].'">'."\n";
                        $referer = '';
                        if (!$disableReferers) {
                            $referer = (!str_contains($arOfferItem['DETAIL_PAGE_URL'], '?') ? '?' : '&amp;').'r1=<?=$strReferer1; ?>&amp;r2=<?=$strReferer2; ?>';
                        }
                        $strOfferYandex .= '<url>'.$usedProtocol.$ar_iblock['SERVER_NAME'].htmlspecialcharsbx($arOfferItem['~DETAIL_PAGE_URL']).$referer."</url>\n";

                        $strOfferYandex .= '<price>'.$minPrice."</price>\n";
                        if ($minPrice < $fullPrice) {
                            $strOfferYandex .= '<oldprice>'.$fullPrice."</oldprice>\n";
                        }
                        $strOfferYandex .= '<currencyId>'.$minPriceCurrency."</currencyId>\n";

                        $strOfferYandex .= $arItem['YANDEX_CATEGORY'];

                        $strFile = '';
                        $arOfferItem['DETAIL_PICTURE'] = (int) $arOfferItem['DETAIL_PICTURE'];
                        $arOfferItem['PREVIEW_PICTURE'] = (int) $arOfferItem['PREVIEW_PICTURE'];
                        if ($arOfferItem['DETAIL_PICTURE'] > 0 || $arOfferItem['PREVIEW_PICTURE'] > 0) {
                            $pictNo = ($arOfferItem['DETAIL_PICTURE'] > 0 ? $arOfferItem['DETAIL_PICTURE'] : $arOfferItem['PREVIEW_PICTURE']);

                            if ($ar_file = CFile::GetFileArray($pictNo)) {
                                if ('/' === substr($ar_file['SRC'], 0, 1)) {
                                    $strFile = $usedProtocol.$ar_iblock['SERVER_NAME'].CHTTP::urnEncode($ar_file['SRC'], 'utf-8');
                                } else {
                                    $strFile = $ar_file['SRC'];
                                }
                            }
                        }
                        if (!empty($strFile) || !empty($arItem['YANDEX_PICT'])) {
                            $strOfferYandex .= '<picture>'.(!empty($strFile) ? $strFile : $arItem['YANDEX_PICT'])."</picture>\n";
                        }

                        $y = 0;
                        foreach ($arYandexFields as $key) {
                            switch ($key) {
                                case 'name':
                                    if ('vendor.model' === $yandexFormat || 'artist.title' === $yandexFormat) {
                                        break;
                                    }

                                    $strOfferYandex .= '<name>'.yandex_text2xml($arOfferItem['~NAME'], true)."</name>\n";

                                    break;

                                case 'description':
                                    $strOfferYandex .= '<description>';
                                    if ('' === $arOfferItem['~PREVIEW_TEXT']) {
                                        $strOfferYandex .= $arItem['YANDEX_DESCR'];
                                    } else {
                                        $strOfferYandex .= yandex_text2xml(
                                            TruncateText(
                                                'html' === $arOfferItem['PREVIEW_TEXT_TYPE'] ?
                                                    strip_tags(preg_replace_callback("'&[^;]*;'", 'yandex_replace_special', $arOfferItem['~PREVIEW_TEXT'])) : preg_replace_callback("'&[^;]*;'", 'yandex_replace_special', $arOfferItem['~PREVIEW_TEXT']),
                                                255
                                            ),
                                            true
                                        );
                                    }
                                    $strOfferYandex .= "</description>\n";

                                    break;

                                case 'param':
                                    if ($parametricFieldsExist) {
                                        foreach ($parametricFields as $paramKey => $prop_id) {
                                            $strParamValue = '';
                                            if ($prop_id) {
                                                $strParamValue = yandex_get_value($arOfferItem, 'PARAM_'.$paramKey, $prop_id, $arProperties, $arUserTypeFormat, $usedProtocol);
                                            }
                                            if ('' !== $strParamValue) {
                                                $strOfferYandex .= $strParamValue."\n";
                                            }
                                        }
                                        unset($paramKey, $prop_id);
                                    }

                                    break;

                                case 'model':
                                case 'title':
                                    if (!$fieldsExist || !isset($fields[$key])) {
                                        if (
                                            'model' === $key && 'vendor.model' === $yandexFormat
                                            || 'title' === $key && 'artist.title' === $yandexFormat
                                        ) {
                                            $strOfferYandex .= '<'.$key.'>'.yandex_text2xml($arOfferItem['~NAME'], true).'</'.$key.">\n";
                                        }
                                    } else {
                                        $strValue = '';
                                        $strValue = yandex_get_value($arOfferItem, $key, $fields[$key], $arProperties, $arUserTypeFormat, $usedProtocol);
                                        if ('' !== $strValue) {
                                            $strOfferYandex .= $strValue."\n";
                                        }
                                    }

                                    break;

                                case 'year':
                                default:
                                    if ('year' === $key) {
                                        ++$y;
                                        if ('artist.title' === $yandexFormat) {
                                            if (1 === $y) {
                                                break;
                                            }
                                        } else {
                                            if ($y > 1) {
                                                break;
                                            }
                                        }
                                    }
                                    if ($fieldsExist && isset($fields[$key])) {
                                        $strValue = '';
                                        $strValue = yandex_get_value($arOfferItem, $key, $fields[$key], $arProperties, $arUserTypeFormat, $usedProtocol);
                                        if ('' !== $strValue) {
                                            $strOfferYandex .= $strValue."\n";
                                        }
                                    }
                            }
                        }

                        $strOfferYandex .= "</offer>\n";
                        $arItem['OFFERS'][] = $strOfferYandex;
                        $boolItemOffers = true;
                        $boolItemExport = true;
                    }
                }
            } elseif (CCatalogSku::TYPE_FULL === $arCatalog['CATALOG_TYPE'] && Catalog\ProductTable::TYPE_PRODUCT === $arItem['CATALOG_TYPE']) {
                $str_AVAILABLE = ' available="'.('Y' === $arItem['CATALOG_AVAILABLE'] ? 'true' : 'false').'"';

                $fullPrice = 0;
                $minPrice = 0;
                $minPriceRUR = 0;
                $minPriceGroup = 0;
                $minPriceCurrency = '';

                if ($XML_DATA['PRICE'] > 0) {
                    $rsPrices = CPrice::GetListEx(
                        [],
                        [
                            'PRODUCT_ID' => $arItem['ID'],
                            'CATALOG_GROUP_ID' => $XML_DATA['PRICE'],
                            'CAN_BUY' => 'Y',
                            'GROUP_GROUP_ID' => [2],
                            '+<=QUANTITY_FROM' => 1,
                            '+>=QUANTITY_TO' => 1,
                        ]
                    );
                    if ($arPrice = $rsPrices->Fetch()) {
                        if ($arOptimalPrice = CCatalogProduct::GetOptimalPrice(
                            $arItem['ID'],
                            1,
                            [2],
                            'N',
                            [$arPrice],
                            $ar_iblock['LID'],
                            []
                        )) {
                            $minPrice = $arOptimalPrice['RESULT_PRICE']['DISCOUNT_PRICE'];
                            $fullPrice = $arOptimalPrice['RESULT_PRICE']['BASE_PRICE'];
                            $minPriceCurrency = $arOptimalPrice['RESULT_PRICE']['CURRENCY'];
                            if ($minPriceCurrency === $RUR) {
                                $minPriceRUR = $minPrice;
                            } else {
                                $minPriceRUR = CCurrencyRates::ConvertCurrency($minPrice, $minPriceCurrency, $RUR);
                            }
                            $minPriceGroup = $arOptimalPrice['PRICE']['CATALOG_GROUP_ID'];
                        }
                    }
                } else {
                    if ($arPrice = CCatalogProduct::GetOptimalPrice(
                        $arItem['ID'],
                        1,
                        [2], // anonymous
                        'N',
                        [],
                        $ar_iblock['LID'],
                        []
                    )) {
                        $minPrice = $arPrice['RESULT_PRICE']['DISCOUNT_PRICE'];
                        $fullPrice = $arPrice['RESULT_PRICE']['BASE_PRICE'];
                        $minPriceCurrency = $arPrice['RESULT_PRICE']['CURRENCY'];
                        if ($minPriceCurrency === $RUR) {
                            $minPriceRUR = $minPrice;
                        } else {
                            $minPriceRUR = CCurrencyRates::ConvertCurrency($minPrice, $minPriceCurrency, $RUR);
                        }
                        $minPriceGroup = $arPrice['PRICE']['CATALOG_GROUP_ID'];
                    }
                }

                if ($minPrice <= 0) {
                    continue;
                }

                if ('' === $arItem['DETAIL_PAGE_URL']) {
                    $arItem['DETAIL_PAGE_URL'] = '/';
                } else {
                    $arItem['DETAIL_PAGE_URL'] = str_replace(' ', '%20', $arItem['DETAIL_PAGE_URL']);
                }
                if ('' === $arItem['~DETAIL_PAGE_URL']) {
                    $arItem['~DETAIL_PAGE_URL'] = '/';
                } else {
                    $arItem['~DETAIL_PAGE_URL'] = str_replace(' ', '%20', $arItem['~DETAIL_PAGE_URL']);
                }

                $strOfferYandex = '';
                $strOfferYandex .= '<offer id="'.$arItem['ID'].'"'.$productFormat.$str_AVAILABLE.">\n";
                $referer = '';
                if (!$disableReferers) {
                    $referer = (!str_contains($arItem['DETAIL_PAGE_URL'], '?') ? '?' : '&amp;').'r1=<?=$strReferer1; ?>&amp;r2=<?=$strReferer2; ?>';
                }
                $strOfferYandex .= '<url>'.$usedProtocol.$ar_iblock['SERVER_NAME'].htmlspecialcharsbx($arItem['~DETAIL_PAGE_URL']).$referer."</url>\n";

                $strOfferYandex .= '<price>'.$minPrice."</price>\n";
                if ($minPrice < $fullPrice) {
                    $strOfferYandex .= '<oldprice>'.$fullPrice."</oldprice>\n";
                }
                $strOfferYandex .= '<currencyId>'.$minPriceCurrency."</currencyId>\n";

                $strOfferYandex .= $arItem['YANDEX_CATEGORY'];

                if (!empty($arItem['YANDEX_PICT'])) {
                    $strOfferYandex .= '<picture>'.$arItem['YANDEX_PICT']."</picture>\n";
                }

                $y = 0;
                foreach ($arYandexFields as $key) {
                    $strValue = '';

                    switch ($key) {
                        case 'name':
                            if ('vendor.model' === $yandexFormat || 'artist.title' === $yandexFormat) {
                                break;
                            }

                            $strValue = '<name>'.yandex_text2xml($arItem['~NAME'], true)."</name>\n";

                            break;

                        case 'description':
                            $strValue =
                                '<description>'.
                                yandex_text2xml(TruncateText(
                                    'html' === $arItem['PREVIEW_TEXT_TYPE'] ?
                                    strip_tags(preg_replace_callback("'&[^;]*;'", 'yandex_replace_special', $arItem['~PREVIEW_TEXT'])) : preg_replace_callback("'&[^;]*;'", 'yandex_replace_special', $arItem['~PREVIEW_TEXT']),
                                    255
                                ), true).
                                "</description>\n";

                            break;

                        case 'param':
                            if ($parametricFieldsExist) {
                                foreach ($parametricFields as $paramKey => $prop_id) {
                                    $strParamValue = '';
                                    if ($prop_id) {
                                        $strParamValue = yandex_get_value($arItem, 'PARAM_'.$paramKey, $prop_id, $arProperties, $arUserTypeFormat, $usedProtocol);
                                    }
                                    if ('' !== $strParamValue) {
                                        $strValue .= $strParamValue."\n";
                                    }
                                }
                                unset($paramKey, $prop_id);
                            }

                            break;

                        case 'model':
                        case 'title':
                            if (!$fieldsExist || !isset($fields[$key])) {
                                if (
                                    'model' === $key && 'vendor.model' === $yandexFormat
                                    || 'title' === $key && 'artist.title' === $yandexFormat
                                ) {
                                    $strValue = '<'.$key.'>'.yandex_text2xml($arItem['~NAME'], true).'</'.$key.">\n";
                                }
                            } else {
                                $strValue = yandex_get_value($arItem, $key, $fields[$key], $arProperties, $arUserTypeFormat, $usedProtocol);
                                if ('' !== $strValue) {
                                    $strValue .= "\n";
                                }
                            }

                            break;

                        case 'year':
                        default:
                            if ('year' === $key) {
                                ++$y;
                                if ('artist.title' === $yandexFormat) {
                                    if (1 === $y) {
                                        break;
                                    }
                                } else {
                                    if ($y > 1) {
                                        break;
                                    }
                                }
                            }
                            if ($fieldsExist && isset($fields[$key])) {
                                $strValue = yandex_get_value($arItem, $key, $fields[$key], $arProperties, $arUserTypeFormat, $usedProtocol);
                                if ('' !== $strValue) {
                                    $strValue .= "\n";
                                }
                            }
                    }
                    if ('' !== $strValue) {
                        $strOfferYandex .= $strValue;
                    }
                }

                $strOfferYandex .= "</offer>\n";

                if ('' !== $strOfferYandex) {
                    $arItem['OFFERS'][] = $strOfferYandex;
                    $boolItemOffers = true;
                    $boolItemExport = true;
                }
            }
            if (100 <= $cnt) {
                $cnt = 0;
                CCatalogDiscount::ClearDiscountCache([
                    'PRODUCT' => true,
                    'SECTIONS' => true,
                    'PROPERTIES' => true,
                ]);
            }
            if (!$boolItemExport) {
                continue;
            }
            foreach ($arItem['OFFERS'] as $strOfferItem) {
                $strTmpOff .= $strOfferItem;
            }
        }
    }

    fwrite($fp, "<categories>\n");
    if ($boolNeedRootSection) {
        $strTmpCat .= '<category id="'.$intMaxSectionID.'">'.yandex_text2xml(GetMessage('YANDEX_ROOT_DIRECTORY'), true)."</category>\n";
    }
    fwrite($fp, $strTmpCat);
    fwrite($fp, "</categories>\n");

    fwrite($fp, "<offers>\n");
    fwrite($fp, $strTmpOff);
    fwrite($fp, "</offers>\n");

    fwrite($fp, "</shop>\n");
    fwrite($fp, "</yml_catalog>\n");

    fclose($fp);
}

CCatalogDiscountSave::Enable();

if (!empty($arRunErrors)) {
    $strExportErrorMessage = implode('<br />', $arRunErrors);
}

if ($bTmpUserCreated) {
    unset($USER);
    if (isset($USER_TMP)) {
        $USER = $USER_TMP;
        unset($USER_TMP);
    }
}
