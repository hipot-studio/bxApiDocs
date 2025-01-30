<?php

use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

class csv_settings
{
    public const FIELDS_ELEMENT = 'ELEMENT';
    public const FIELDS_CATALOG = 'CATALOG';
    public const FIELDS_PRICE = 'PRICE';
    public const FIELDS_PRICE_EXT = 'PRICE_EXT';
    public const FIELDS_SECTION = 'SECTION';
    public const FIELDS_CURRENCY = 'CURRENCY';

    public static function getSettingsFields($type, $extFormat = false)
    {
        $extFormat = (true === $extFormat);
        $result = [];
        $type = (string) $type;
        if ('' !== $type) {
            switch ($type) {
                case self::FIELDS_ELEMENT:
                    $result = [
                        'IE_XML_ID' => [
                            'value' => 'IE_XML_ID',
                            'field' => 'XML_ID',
                            'important' => 'Y',
                            'name' => Loc::getMessage('CATI_FI_UNIXML_EXT').' (B_IBLOCK_ELEMENT.XML_ID)',
                        ],
                        'IE_NAME' => [
                            'value' => 'IE_NAME',
                            'field' => 'NAME',
                            'important' => 'Y',
                            'name' => Loc::getMessage('CATI_FI_NAME').' (B_IBLOCK_ELEMENT.NAME)',
                        ],
                        'IE_ACTIVE' => [
                            'value' => 'IE_ACTIVE',
                            'field' => 'ACTIVE',
                            'important' => 'N',
                            'name' => Loc::getMessage('CATI_FI_ACTIV').' (B_IBLOCK_ELEMENT.ACTIVE)',
                        ],
                        'IE_ACTIVE_FROM' => [
                            'value' => 'IE_ACTIVE_FROM',
                            'field' => 'ACTIVE_FROM',
                            'important' => 'N',
                            'name' => Loc::getMessage('CATI_FI_ACTIVFROM').' (B_IBLOCK_ELEMENT.ACTIVE_FROM)',
                        ],
                        'IE_ACTIVE_TO' => [
                            'value' => 'IE_ACTIVE_TO',
                            'field' => 'ACTIVE_TO',
                            'important' => 'N',
                            'name' => Loc::getMessage('CATI_FI_ACTIVTO').' (B_IBLOCK_ELEMENT.ACTIVE_TO)',
                        ],
                        'IE_SORT' => [
                            'value' => 'IE_SORT',
                            'field' => 'SORT',
                            'important' => 'N',
                            'name' => Loc::getMessage('CATI_FI_SORT_EXT').' (B_IBLOCK_ELEMENT.SORT)',
                        ],
                        'IE_PREVIEW_PICTURE' => [
                            'value' => 'IE_PREVIEW_PICTURE',
                            'field' => 'PREVIEW_PICTURE',
                            'important' => 'N',
                            'name' => Loc::getMessage('CATI_FI_CATIMG_EXT').' (B_IBLOCK_ELEMENT.PREVIEW_PICTURE)',
                        ],
                        'IE_PREVIEW_TEXT' => [
                            'value' => 'IE_PREVIEW_TEXT',
                            'field' => 'PREVIEW_TEXT',
                            'important' => 'N',
                            'name' => Loc::getMessage('CATI_FI_CATDESCR_EXT').' (B_IBLOCK_ELEMENT.PREVIEW_TEXT)',
                        ],
                        'IE_PREVIEW_TEXT_TYPE' => [
                            'value' => 'IE_PREVIEW_TEXT_TYPE',
                            'field' => 'PREVIEW_TEXT_TYPE',
                            'important' => 'N',
                            'name' => Loc::getMessage('CATI_FI_CATDESCRTYPE_EXT').' (B_IBLOCK_ELEMENT.PREVIEW_TEXT_TYPE)',
                        ],
                        'IE_DETAIL_PICTURE' => [
                            'value' => 'IE_DETAIL_PICTURE',
                            'field' => 'DETAIL_PICTURE',
                            'important' => 'N',
                            'name' => Loc::getMessage('CATI_FI_DETIMG_EXT').' (B_IBLOCK_ELEMENT.DETAIL_PICTURE)',
                        ],
                        'IE_DETAIL_TEXT' => [
                            'value' => 'IE_DETAIL_TEXT',
                            'field' => 'DETAIL_TEXT',
                            'important' => 'N',
                            'name' => Loc::getMessage('CATI_FI_DETDESCR_EXT').' (B_IBLOCK_ELEMENT.DETAIL_TEXT)',
                        ],
                        'IE_DETAIL_TEXT_TYPE' => [
                            'value' => 'IE_DETAIL_TEXT_TYPE',
                            'field' => 'DETAIL_TEXT_TYPE',
                            'important' => 'N',
                            'name' => Loc::getMessage('CATI_FI_DETDESCRTYPE_EXT').' (B_IBLOCK_ELEMENT.DETAIL_TEXT_TYPE)',
                        ],
                        'IE_CODE' => [
                            'value' => 'IE_CODE',
                            'field' => 'CODE',
                            'important' => 'N',
                            'name' => Loc::getMessage('CATI_FI_CODE_EXT').' (B_IBLOCK_ELEMENT.CODE)',
                        ],
                        'IE_TAGS' => [
                            'value' => 'IE_TAGS',
                            'field' => 'TAGS',
                            'important' => 'N',
                            'name' => Loc::getMessage('CATI_FI_TAGS').' (B_IBLOCK_ELEMENT.TAGS)',
                        ],
                        'IE_ID' => [
                            'value' => 'IE_ID',
                            'field' => 'ID',
                            'important' => 'N',
                            'name' => Loc::getMessage('CATI_FI_ID').' (B_IBLOCK_ELEMENT.ID)',
                        ],
                    ];

                    break;

                case self::FIELDS_CATALOG:
                    $result = [
                        'CP_QUANTITY' => [
                            'value' => 'CP_QUANTITY',
                            'field' => 'QUANTITY',
                            'important' => 'N',
                            'name' => Loc::getMessage('CATI_FI_QUANT').' (B_CATALOG_PRODUCT.QUANTITY)',
                        ],
                        'CP_QUANTITY_TRACE' => [
                            'value' => 'CP_QUANTITY_TRACE',
                            'field' => 'QUANTITY_TRACE',
                            'field_orig' => 'QUANTITY_TRACE_ORIG',
                            'important' => 'N',
                            'name' => Loc::getMessage('CATI_FI_QUANTITY_TRACE').' (B_CATALOG_PRODUCT.QUANTITY_TRACE)',
                        ],
                        'CP_CAN_BUY_ZERO' => [
                            'value' => 'CP_CAN_BUY_ZERO',
                            'field' => 'CAN_BUY_ZERO',
                            'field_orig' => 'CAN_BUY_ZERO_ORIG',
                            'important' => 'N',
                            'name' => Loc::getMessage('CATI_FI_CAN_BUY_ZERO').' (B_CATALOG_PRODUCT.CAN_BUY_ZERO)',
                        ],
                        'CP_WEIGHT' => [
                            'value' => 'CP_WEIGHT',
                            'field' => 'WEIGHT',
                            'important' => 'N',
                            'name' => Loc::getMessage('CATI_FI_WEIGHT').' (B_CATALOG_PRODUCT.WEIGHT)',
                        ],
                        'CP_WIDTH' => [
                            'value' => 'CP_WIDTH',
                            'field' => 'WIDTH',
                            'important' => 'N',
                            'name' => Loc::getMessage('CATI_FI_WIDTH').' (B_CATALOG_PRODUCT.WIDTH)',
                        ],
                        'CP_HEIGHT' => [
                            'value' => 'CP_HEIGHT',
                            'field' => 'HEIGHT',
                            'important' => 'N',
                            'name' => Loc::getMessage('CATI_FI_HEIGHT').' (B_CATALOG_PRODUCT.HEIGHT)',
                        ],
                        'CP_LENGTH' => [
                            'value' => 'CP_LENGTH',
                            'field' => 'LENGTH',
                            'important' => 'N',
                            'name' => Loc::getMessage('CATI_FI_LENGTH').' (B_CATALOG_PRODUCT.LENGTH)',
                        ],
                        'CP_PURCHASING_PRICE' => [
                            'value' => 'CP_PURCHASING_PRICE',
                            'field' => 'PURCHASING_PRICE',
                            'important' => 'N',
                            'name' => Loc::getMessage('CATI_FI_PURCHASING_PRICE').' (B_CATALOG_PRODUCT.PURCHASING_PRICE)',
                        ],
                        'CP_PURCHASING_CURRENCY' => [
                            'value' => 'CP_PURCHASING_CURRENCY',
                            'field' => 'PURCHASING_CURRENCY',
                            'important' => 'N',
                            'name' => Loc::getMessage('CATI_FI_PURCHASING_CURRENCY').' (B_CATALOG_PRODUCT.PURCHASING_CURRENCY)',
                        ],
                        'CP_PRICE_TYPE' => [
                            'value' => 'CP_PRICE_TYPE',
                            'field' => 'PRICE_TYPE',
                            'important' => 'N',
                            'name' => Loc::getMessage('I_PAY_TYPE').' (B_CATALOG_PRODUCT.PRICE_TYPE)',
                        ],
                        'CP_RECUR_SCHEME_LENGTH' => [
                            'value' => 'CP_RECUR_SCHEME_LENGTH',
                            'field' => 'RECUR_SCHEME_LENGTH',
                            'important' => 'N',
                            'name' => Loc::getMessage('I_PAY_PERIOD_LENGTH').' (B_CATALOG_PRODUCT.RECUR_SCHEME_LENGTH)',
                        ],
                        'CP_RECUR_SCHEME_TYPE' => [
                            'value' => 'CP_RECUR_SCHEME_TYPE',
                            'field' => 'RECUR_SCHEME_TYPE',
                            'important' => 'N',
                            'name' => Loc::getMessage('I_PAY_PERIOD_TYPE').' (B_CATALOG_PRODUCT.RECUR_SCHEME_TYPE)',
                        ],
                        'CP_TRIAL_PRICE_ID' => [
                            'value' => 'CP_TRIAL_PRICE_ID',
                            'field' => 'TRIAL_PRICE_ID',
                            'important' => 'N',
                            'name' => Loc::getMessage('I_TRIAL_FOR').' (B_CATALOG_PRODUCT.TRIAL_PRICE_ID)',
                        ],
                        'CP_WITHOUT_ORDER' => [
                            'value' => 'CP_WITHOUT_ORDER',
                            'field' => 'WITHOUT_ORDER',
                            'important' => 'N',
                            'name' => Loc::getMessage('I_WITHOUT_ORDER').' (B_CATALOG_PRODUCT.WITHOUT_ORDER)',
                        ],
                        'CP_VAT_ID' => [
                            'value' => 'CP_VAT_ID',
                            'field' => 'VAT_ID',
                            'important' => 'N',
                            'name' => Loc::getMessage('I_VAT_ID').' (B_CATALOG_PRODUCT.VAT_ID)',
                        ],
                        'CP_VAT_INCLUDED' => [
                            'value' => 'CP_VAT_INCLUDED',
                            'field' => 'VAT_INCLUDED',
                            'important' => 'N',
                            'name' => Loc::getMessage('I_VAT_INCLUDED').' (B_CATALOG_PRODUCT.VAT_INCLUDED)',
                        ],
                        'CP_MEASURE' => [
                            'value' => 'CP_MEASURE',
                            'field' => 'MEASURE',
                            'important' => 'N',
                            'name' => Loc::getMessage('BX_CAT_CSV_SETTINGS_PRODUCT_FIELD_NAME_MEASURE_ID').' (B_CATALOG_PRODUCT.MEASURE)',
                        ],
                    ];

                    break;

                case self::FIELDS_PRICE:
                    $result = [
                        'CV_PRICE' => [
                            'value' => 'CV_PRICE',
                            'value_size' => 8,
                            'field' => 'PRICE',
                            'important' => 'N',
                            'name' => Loc::getMessage('I_NAME_PRICE').' (B_CATALOG_PRICE.PRICE)',
                        ],
                        'CV_CURRENCY' => [
                            'value' => 'CV_CURRENCY',
                            'value_size' => 11,
                            'field' => 'CURRENCY',
                            'important' => 'N',
                            'name' => Loc::getMessage('I_NAME_CURRENCY').' (B_CATALOG_PRICE.CURRENCY)',
                        ],
                        'CV_EXTRA_ID' => [
                            'value' => 'CV_EXTRA_ID',
                            'value_size' => 11,
                            'field' => 'EXTRA_ID',
                            'important' => 'N',
                            'name' => Loc::getMessage('I_NAME_EXTRA_ID').' (B_CATALOG_PRICE.EXTRA_ID)',
                        ],
                    ];

                    break;

                case self::FIELDS_PRICE_EXT:
                    $result = [
                        'CV_QUANTITY_FROM' => [
                            'value' => 'CV_QUANTITY_FROM',
                            'field' => 'QUANTITY_FROM',
                            'important' => 'N',
                            'name' => Loc::getMessage('I_NAME_QUANTITY_FROM').' (B_CATALOG_PRICE.QUANTITY_FROM)',
                        ],
                        'CV_QUANTITY_TO' => [
                            'value' => 'CV_QUANTITY_TO',
                            'field' => 'QUANTITY_TO',
                            'important' => 'N',
                            'name' => Loc::getMessage('I_NAME_QUANTITY_TO').' (B_CATALOG_PRICE.QUANTITY_TO)',
                        ],
                    ];

                    break;

                case self::FIELDS_SECTION:
                    $result = [
                        'IC_ID' => [
                            'value' => 'IC_ID',
                            'field' => 'ID',
                            'important' => 'N',
                            'name' => Loc::getMessage('CATI_FI_ID').' (B_IBLOCK_SECTION.ID)',
                        ],
                        'IC_XML_ID' => [
                            'value' => 'IC_XML_ID',
                            'field' => 'XML_ID',
                            'important' => 'Y',
                            'name' => Loc::getMessage('CATI_FG_UNIXML_EXT').' (B_IBLOCK_SECTION.XML_ID)',
                        ],
                        'IC_GROUP' => [
                            'value' => 'IC_GROUP',
                            'field' => 'NAME',
                            'important' => 'Y',
                            'name' => Loc::getMessage('CATI_FG_NAME').' (B_IBLOCK_SECTION.NAME)',
                        ],
                        'IC_ACTIVE' => [
                            'value' => 'IC_ACTIVE',
                            'field' => 'ACTIVE',
                            'important' => 'N',
                            'name' => Loc::getMessage('CATI_FG_ACTIV').' (B_IBLOCK_SECTION.ACTIVE)',
                        ],
                        'IC_SORT' => [
                            'value' => 'IC_SORT',
                            'field' => 'SORT',
                            'important' => 'N',
                            'name' => Loc::getMessage('CATI_FG_SORT_EXT').' (B_IBLOCK_SECTION.SORT)',
                        ],
                        'IC_DESCRIPTION' => [
                            'value' => 'IC_DESCRIPTION',
                            'field' => 'DESCRIPTION',
                            'important' => 'N',
                            'name' => Loc::getMessage('CATI_FG_DESCR').' (B_IBLOCK_SECTION.DESCRIPTION)',
                        ],
                        'IC_DESCRIPTION_TYPE' => [
                            'value' => 'IC_DESCRIPTION_TYPE',
                            'field' => 'DESCRIPTION_TYPE',
                            'important' => 'N',
                            'name' => Loc::getMessage('CATI_FG_DESCRTYPE').' (B_IBLOCK_SECTION.DESCRIPTION_TYPE)',
                        ],
                        'IC_CODE' => [
                            'value' => 'IC_CODE',
                            'field' => 'CODE',
                            'important' => 'N',
                            'name' => Loc::getMessage('CATI_FG_CODE_EXT2').' (B_IBLOCK_SECTION.CODE)',
                        ],
                        'IC_PICTURE' => [
                            'value' => 'IC_PICTURE',
                            'field' => 'PICTURE',
                            'important' => 'N',
                            'name' => Loc::getMessage('CATI_FG_PICTURE').' (B_IBLOCK_SECTION.PICTURE)',
                        ],
                        'IC_DETAIL_PICTURE' => [
                            'value' => 'IC_DETAIL_PICTURE',
                            'field' => 'DETAIL_PICTURE',
                            'important' => 'N',
                            'name' => Loc::getMessage('CATI_FG_DETAIL_PICTURE').' (B_IBLOCK_SECTION.DETAIL_PICTURE)',
                        ],
                    ];

                    break;
            }
        }

        return $extFormat ? $result : array_values($result);
    }

    public static function getDefaultSettings($type, $extFormat = false)
    {
        $extFormat = (true === $extFormat);
        $result = ($extFormat ? [] : '');
        $type = (string) $type;
        if ('' !== $type) {
            switch ($type) {
                case self::FIELDS_ELEMENT:
                    $result = (
                        $extFormat
                        ? ['IE_XML_ID', 'IE_NAME', 'IE_PREVIEW_TEXT', 'IE_DETAIL_TEXT']
                        : 'IE_XML_ID,IE_NAME,IE_PREVIEW_TEXT,IE_DETAIL_TEXT'
                    );

                    break;

                case self::FIELDS_CATALOG:
                    $result = (
                        $extFormat
                        ? ['CP_QUANTITY', 'CP_WEIGHT', 'CP_WIDTH', 'CP_HEIGHT', 'CP_LENGTH']
                        : 'CP_QUANTITY,CP_WEIGHT,CP_WIDTH,CP_HEIGHT,CP_LENGTH'
                    );

                    break;

                case self::FIELDS_PRICE:
                    $result = (
                        $extFormat
                        ? ['CV_PRICE', 'CV_CURRENCY']
                        : 'CV_PRICE,CV_CURRENCY'
                    );

                    break;

                case self::FIELDS_PRICE_EXT:
                    $result = (
                        $extFormat
                        ? ['CV_QUANTITY_FROM', 'CV_QUANTITY_TO']
                        : 'CV_QUANTITY_FROM,CV_QUANTITY_TO'
                    );

                    break;

                case self::FIELDS_SECTION:
                    $result = (
                        $extFormat
                        ? ['IC_GROUP']
                        : 'IC_GROUP'
                    );

                    break;

                case self::FIELDS_CURRENCY:
                    $result = (
                        $extFormat
                        ? ['USD']
                        : 'USD'
                    );

                    break;
            }
        }

        return $result;
    }
}
