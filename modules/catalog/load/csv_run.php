<?php

// <title>CSV Export</title>
IncludeModuleLangFile($_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/catalog/data_export.php');

global $USER;
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

if (!function_exists('__sortCSVOrder')) {
    function __sortCSVOrder($a, $b)
    {
        if ($a['SORT'] === $b['SORT']) {
            return $a['ID'] < $b['ID'] ? -1 : 1;
        }

        return $a['SORT'] < $b['SORT'] ? -1 : 1;
    }
}

$strCatalogDefaultFolder = COption::GetOptionString('catalog', 'export_default_path', CATALOG_DEFAULT_EXPORT_PATH);

$NUM_CATALOG_LEVELS = (int) COption::GetOptionString('catalog', 'num_catalog_levels', 3);
if (0 >= $NUM_CATALOG_LEVELS) {
    $NUM_CATALOG_LEVELS = 3;
}

$strExportErrorMessage = '';
$arRunErrors = [];

global $arCatalogAvailProdFields,
$defCatalogAvailProdFields,
$arCatalogAvailPriceFields,
$defCatalogAvailPriceFields,
$arCatalogAvailValueFields,
$defCatalogAvailValueFields,
$arCatalogAvailQuantityFields,
$defCatalogAvailQuantityFields,
$arCatalogAvailGroupFields,
$defCatalogAvailGroupFields,
$defCatalogAvailCurrencies;

if (!isset($arCatalogAvailProdFields)) {
    $arCatalogAvailProdFields = CCatalogCSVSettings::getSettingsFields(CCatalogCSVSettings::FIELDS_ELEMENT);
}
if (!isset($arCatalogAvailPriceFields)) {
    $arCatalogAvailPriceFields = CCatalogCSVSettings::getSettingsFields(CCatalogCSVSettings::FIELDS_CATALOG);
}
if (!isset($arCatalogAvailValueFields)) {
    $arCatalogAvailValueFields = CCatalogCSVSettings::getSettingsFields(CCatalogCSVSettings::FIELDS_PRICE);
}
if (!isset($arCatalogAvailQuantityFields)) {
    $arCatalogAvailQuantityFields = CCatalogCSVSettings::getSettingsFields(CCatalogCSVSettings::FIELDS_PRICE_EXT);
}
if (!isset($arCatalogAvailGroupFields)) {
    $arCatalogAvailGroupFields = CCatalogCSVSettings::getSettingsFields(CCatalogCSVSettings::FIELDS_SECTION);
}

if (!isset($defCatalogAvailProdFields)) {
    $defCatalogAvailProdFields = CCatalogCSVSettings::getDefaultSettings(CCatalogCSVSettings::FIELDS_ELEMENT);
}
if (!isset($defCatalogAvailPriceFields)) {
    $defCatalogAvailPriceFields = CCatalogCSVSettings::getDefaultSettings(CCatalogCSVSettings::FIELDS_CATALOG);
}
if (!isset($defCatalogAvailValueFields)) {
    $defCatalogAvailValueFields = CCatalogCSVSettings::getDefaultSettings(CCatalogCSVSettings::FIELDS_PRICE);
}
if (!isset($defCatalogAvailQuantityFields)) {
    $defCatalogAvailQuantityFields = CCatalogCSVSettings::getDefaultSettings(CCatalogCSVSettings::FIELDS_PRICE_EXT);
}
if (!isset($defCatalogAvailGroupFields)) {
    $defCatalogAvailGroupFields = CCatalogCSVSettings::getDefaultSettings(CCatalogCSVSettings::FIELDS_SECTION);
}
if (!isset($defCatalogAvailCurrencies)) {
    $defCatalogAvailCurrencies = CCatalogCSVSettings::getDefaultSettings(CCatalogCSVSettings::FIELDS_CURRENCY);
}

$IBLOCK_ID = (int) $IBLOCK_ID;
if ($IBLOCK_ID <= 0) {
    $arRunErrors[] = GetMessage('CATI_NO_IBLOCK');
} elseif ($IBLOCK_ID <= 0) {
    $arRunErrors[] = GetMessage('CATI_NO_IBLOCK');
} else {
    $arIBlockres = CIBlock::GetList(['sort' => 'asc'], ['ID' => $IBLOCK_ID, 'CHECK_PERMISSIONS' => 'N']);
    if (!($arIBlock = $arIBlockres->Fetch())) {
        $arRunErrors[] = GetMessage('CATI_NO_IBLOCK');
    }
}

$boolCatalog = false;
if (empty($arRunErrors)) {
    $arCatalog = CCatalog::GetByID($IBLOCK_ID);
    if (!empty($arCatalog)) {
        $boolCatalog = true;
    }
}

if (empty($arRunErrors)) {
    $csvFile = new CCSVData();

    if (!isset($fields_type) || ('F' !== $fields_type && 'R' !== $fields_type)) {
        $arRunErrors[] = GetMessage('CATI_NO_FORMAT');
    }

    $csvFile->SetFieldsType($fields_type);

    $first_names_r = (isset($first_names_r) && 'Y' === $first_names_r ? true : false);
    $csvFile->SetFirstHeader($first_names_r);

    $delimiter_r_char = '';
    if (isset($delimiter_r)) {
        switch ($delimiter_r) {
            case 'TAB':
                $delimiter_r_char = "\t";

                break;

            case 'ZPT':
                $delimiter_r_char = ',';

                break;

            case 'SPS':
                $delimiter_r_char = ' ';

                break;

            case 'OTR':
                $delimiter_r_char = (isset($delimiter_other_r) ? substr($delimiter_other_r, 0, 1) : '');

                break;

            case 'TZP':
                $delimiter_r_char = ';';

                break;
        }
    }

    if (1 !== strlen($delimiter_r_char)) {
        $arRunErrors[] = GetMessage('CATI_NO_DELIMITER');
    }

    if (empty($arRunErrors)) {
        $csvFile->SetDelimiter($delimiter_r_char);
    }

    if (!isset($SETUP_FILE_NAME) || '' === $SETUP_FILE_NAME) {
        $arRunErrors[] = GetMessage('CATI_NO_SAVE_FILE');
    } elseif (preg_match(BX_CATALOG_FILENAME_REG, $SETUP_FILE_NAME)) {
        $arRunErrors[] = GetMessage('CES_ERROR_BAD_EXPORT_FILENAME');
    } else {
        $SETUP_FILE_NAME = Rel2Abs('/', $SETUP_FILE_NAME);
        if ('.csv' !== strtolower(substr($SETUP_FILE_NAME, strlen($SETUP_FILE_NAME) - 4))) {
            $SETUP_FILE_NAME .= '.csv';
        }
        if (!str_starts_with($SETUP_FILE_NAME, $strCatalogDefaultFolder)) {
            $arRunErrors[] = GetMessage('CES_ERROR_PATH_WITHOUT_DEFAUT');
        } else {
            CheckDirPath($_SERVER['DOCUMENT_ROOT'].$SETUP_FILE_NAME);

            if (!($fp = fopen($_SERVER['DOCUMENT_ROOT'].$SETUP_FILE_NAME, 'w'))) {
                $arRunErrors[] = GetMessage('CATI_CANNOT_CREATE_FILE');
            }
            @fclose($fp);
        }
    }

    $bFieldsPres = (!empty($field_needed) && is_array($field_needed) && in_array('Y', $field_needed, true));
    if ($bFieldsPres && (empty($field_code) || !is_array($field_code))) {
        $bFieldsPres = false;
    }
    if (!$bFieldsPres) {
        $arRunErrors[] = GetMessage('CATI_NO_FIELDS');
    }

    // We can't link more than 30 tables.
    $tableLinksCount = 10;
    for ($i = 0, $intCount = count($field_code); $i < $intCount; ++$i) {
        if ('CR_PRICE_' === substr($field_code[$i], 0, strlen('CR_PRICE_')) && isset($field_needed[$i]) && 'Y' === $field_needed[$i]) {
            ++$tableLinksCount;
        } elseif ('IP_PROP' === substr($field_code[$i], 0, strlen('IP_PROP')) && isset($field_needed[$i]) && 'Y' === $field_needed[$i]) {
            $tableLinksCount += 2;
        }
    }
    if ($tableLinksCount > 30) {
        $arRunErrors[] = GetMessage('CATI_TOO_MANY_TABLES');
    }

    $num_rows_writed = 0;
    if (empty($arRunErrors)) {
        global $defCatalogAvailGroupFields, $defCatalogAvailProdFields, $defCatalogAvailPriceFields, $defCatalogAvailValueFields, $defCatalogAvailQuantityFields;
        global $arCatalogAvailProdFields, $arCatalogAvailGroupFields, $arCatalogAvailPriceFields, $arCatalogAvailValueFields, $arCatalogAvailQuantityFields;

        $intCount = 0; // count of all available fields, props, section fields, prices
        $arSortFields = []; // array for order
        $selectArray = ['ID', 'IBLOCK_ID', 'IBLOCK_SECTION_ID']; // selected element fields
        $bNeedGroups = false; // sections need?
        $bNeedPrices = false; // prices need?
        $bNeedProducts = false; // product properties need?
        $bNeedProps = false; // element props need?
        $arGroupProps = []; // section fields array (no user props)
        $arElementProps = []; // element props
        $arFileProps = [];
        $arCatalogGroups = []; // prices
        $arProductFields = []; // product properties
        $bNeedCounts = false; // price ranges
        $arCountFields = []; // price ranges fields
        $arValueCodes = [];
        $arNeedFields = []; // result order

        // Prepare arrays for product loading
        $strAvailProdFields = COption::GetOptionString('catalog', 'allowed_product_fields', $defCatalogAvailProdFields);
        $arAvailProdFields = explode(',', $strAvailProdFields);
        $arAvailProdFields_names = [];
        foreach ($arCatalogAvailProdFields as &$arOneCatalogAvailProdFields) {
            if (in_array($arOneCatalogAvailProdFields['value'], $arAvailProdFields, true)) {
                $arAvailProdFields_names[$arOneCatalogAvailProdFields['value']] = [
                    'field' => $arOneCatalogAvailProdFields['field'],
                    'important' => $arOneCatalogAvailProdFields['important'],
                ];
                $mxSelKey = array_search($arOneCatalogAvailProdFields['value'], $field_code, true);
                if (!(false === $mxSelKey || empty($field_needed[$mxSelKey]) || 'Y' !== $field_needed[$mxSelKey])) {
                    $arSortFields[$arOneCatalogAvailProdFields['value']] = [
                        'CODE' => $arOneCatalogAvailProdFields['value'],
                        'ID' => $intCount,
                        'SORT' => (!empty($field_num[$mxSelKey]) && 0 < (int) ($field_num[$mxSelKey]) ? (int) ($field_num[$mxSelKey]) : ($intCount + 1) * 10),
                    ];
                    $selectArray[] = $arOneCatalogAvailProdFields['field'];
                }
                ++$intCount;
            }
        }
        if (isset($arOneCatalogAvailProdFields)) {
            unset($arOneCatalogAvailProdFields);
        }

        $rsProps = CIBlockProperty::GetList(['SORT' => 'ASC', 'ID' => 'ASC'], ['IBLOCK_ID' => $IBLOCK_ID, 'ACTIVE' => 'Y', 'CHECK_PERMISSIONS' => 'N']);
        while ($arProp = $rsProps->Fetch()) {
            $mxSelKey = array_search('IP_PROP'.$arProp['ID'], $field_code, true);
            if (!(false === $mxSelKey || empty($field_needed[$mxSelKey]) || 'Y' !== $field_needed[$mxSelKey])) {
                $arSortFields['IP_PROP'.$arProp['ID']] = [
                    'CODE' => 'IP_PROP'.$arProp['ID'],
                    'ID' => $intCount,
                    'SORT' => (!empty($field_num[$mxSelKey]) && 0 < (int) ($field_num[$mxSelKey]) ? (int) ($field_num[$mxSelKey]) : ($intCount + 1) * 10),
                ];
                $bNeedProps = true;
                $arElementProps[] = $arProp['ID'];
                if ('F' === $arProp['PROPERTY_TYPE']) {
                    $arFileProps[] = $arProp['ID'];
                }
                $selectArray[] = 'PROPERTY_'.$arProp['ID'];
            }
            ++$intCount;
        }
        if ($bNeedProps) {
            $arElementProps = array_values(array_unique($arElementProps));
            if (!empty($arFileProps)) {
                $arFileProps = array_values(array_unique($arFileProps));
            }
        }

        // Prepare arrays for groups loading
        $strAvailGroupFields = COption::GetOptionString('catalog', 'allowed_group_fields', $defCatalogAvailGroupFields);
        $arAvailGroupFields = explode(',', $strAvailGroupFields);
        $arAvailGroupFields_names = [];
        foreach ($arCatalogAvailGroupFields as &$arOneCatalogAvailGroupFields) {
            if (in_array($arOneCatalogAvailGroupFields['value'], $arAvailGroupFields, true)) {
                $arAvailGroupFields_names[$arOneCatalogAvailGroupFields['value']] = [
                    'field' => $arOneCatalogAvailGroupFields['field'],
                    'important' => $arOneCatalogAvailGroupFields['important'],
                ];
            }
        }
        if (isset($arOneCatalogAvailGroupFields)) {
            unset($arOneCatalogAvailGroupFields);
        }
        if (!empty($arAvailGroupFields_names)) {
            $arAvailGroupFieldsList = array_keys($arAvailGroupFields_names);
            for ($i = 0; $i < $NUM_CATALOG_LEVELS; ++$i) {
                foreach ($arAvailGroupFieldsList as &$strKey) {
                    $mxSelKey = array_search($strKey.$i, $field_code, true);
                    if (!(false === $mxSelKey || empty($field_needed[$mxSelKey]) || 'Y' !== $field_needed[$mxSelKey])) {
                        $arSortFields[$strKey.$i] = [
                            'CODE' => $strKey.$i,
                            'ID' => $intCount,
                            'SORT' => (!empty($field_num[$mxSelKey]) && 0 < (int) ($field_num[$mxSelKey]) ? (int) ($field_num[$mxSelKey]) : ($intCount + 1) * 10),
                        ];
                        $bNeedGroups = true;
                        $arGroupProps[$i][] = $strKey;
                    }
                    ++$intCount;
                }
                if (isset($strKey)) {
                    unset($strKey);
                }
                if (!empty($arGroupProps[$i])) {
                    $arGroupProps[$i] = array_values(array_unique($arGroupProps[$i]));
                }
            }
            unset($arAvailGroupFieldsList);
        }

        if ($boolCatalog) {
            // Prepare arrays for product loading (for catalog)
            $strAvailPriceFields = COption::GetOptionString('catalog', 'allowed_product_fields', $defCatalogAvailPriceFields);
            $arAvailPriceFields = explode(',', $strAvailPriceFields);
            $arAvailPriceFields_names = [];
            foreach ($arCatalogAvailPriceFields as &$arOneCatalogAvailPriceFields) {
                if (in_array($arOneCatalogAvailPriceFields['value'], $arAvailPriceFields, true)) {
                    $arAvailPriceFields_names[$arOneCatalogAvailPriceFields['value']] = [
                        'field' => $arOneCatalogAvailPriceFields['field'],
                        'important' => $arOneCatalogAvailPriceFields['important'],
                    ];

                    $mxSelKey = array_search($arOneCatalogAvailPriceFields['value'], $field_code, true);
                    if (!(false === $mxSelKey || empty($field_needed[$mxSelKey]) || 'Y' !== $field_needed[$mxSelKey])) {
                        $arSortFields[$arOneCatalogAvailPriceFields['value']] = [
                            'CODE' => $arOneCatalogAvailPriceFields['value'],
                            'ID' => $intCount,
                            'SORT' => (!empty($field_num[$mxSelKey]) && 0 < (int) ($field_num[$mxSelKey]) ? (int) ($field_num[$mxSelKey]) : ($intCount + 1) * 10),
                        ];
                        $bNeedProducts = true;
                        $arProductFields[] = $arOneCatalogAvailPriceFields['value'];
                        $selectArray[] = 'CATALOG_'.$arOneCatalogAvailPriceFields['field'];
                    }
                    ++$intCount;
                }
            }
            if (isset($arOneCatalogAvailPriceFields)) {
                unset($arOneCatalogAvailPriceFields);
            }
            if ($bNeedProducts) {
                $arProductFields = array_values(array_unique($arProductFields));
            }

            // Prepare arrays for price loading
            $strAvailCountFields = $defCatalogAvailQuantityFields;
            $arAvailCountFields = explode(',', $strAvailCountFields);
            $arAvailCountFields_names = [];
            foreach ($arCatalogAvailQuantityFields as &$arOneCatalogAvailQuantityFields) {
                if (in_array($arOneCatalogAvailQuantityFields['value'], $arAvailCountFields, true)) {
                    $arAvailCountFields_names[$arOneCatalogAvailQuantityFields['value']] = [
                        'field' => $arOneCatalogAvailQuantityFields['field'],
                        'important' => $arOneCatalogAvailQuantityFields['important'],
                    ];
                    $mxSelKey = array_search($arOneCatalogAvailQuantityFields['value'], $field_code, true);
                    if (!(false === $mxSelKey || empty($field_needed[$mxSelKey]) || 'Y' !== $field_needed[$mxSelKey])) {
                        $arSortFields[$arOneCatalogAvailQuantityFields['value']] = [
                            'CODE' => $arOneCatalogAvailQuantityFields['value'],
                            'ID' => $intCount,
                            'SORT' => (!empty($field_num[$mxSelKey]) && 0 < (int) ($field_num[$mxSelKey]) ? (int) ($field_num[$mxSelKey]) : ($intCount + 1) * 10),
                        ];
                        $bNeedCounts = true;
                        $arCountFields[] = $arOneCatalogAvailQuantityFields['value'];
                    }
                    ++$intCount;
                }
            }
            if (isset($arOneCatalogAvailQuantityFields)) {
                unset($arOneCatalogAvailQuantityFields);
            }

            $strVal = COption::GetOptionString('catalog', 'allowed_currencies', $defCatalogAvailCurrencies);
            $arVal = explode(',', $strVal);
            $by1 = 'sort';
            $order1 = 'asc';
            $lcur = CCurrency::GetList($by1, $order1);
            $arCurList = [];
            while ($lcur_res = $lcur->Fetch()) {
                if (in_array($lcur_res['CURRENCY'], $arVal, true)) {
                    $arCurList[] = $lcur_res['CURRENCY'];
                }
            }

            $arPriceList = [];
            if (!empty($arCurList)) {
                foreach ($field_code as $mxSelKey => $strOneFieldsCode) {
                    if (0 === strncmp($strOneFieldsCode, 'CR_PRICE_', 9)) {
                        if (!(empty($field_needed[$mxSelKey]) || 'Y' !== $field_needed[$mxSelKey])) {
                            $arTempo = explode('_', substr($strOneFieldsCode, 9));
                            $arTempo[0] = (int) $arTempo[0];
                            if (0 < $arTempo[0]) {
                                $bNeedPrices = true;
                                $arPriceList[$arTempo[0]] = $arTempo[1];
                                $arSortFields['CR_PRICE_'.$arTempo[0].'_'.$arTempo[1]] = [
                                    'CODE' => 'CR_PRICE_'.$arTempo[0].'_'.$arTempo[1],
                                    'ID' => $intCount,
                                    'SORT' => (!empty($field_num[$mxSelKey]) && 0 < (int) ($field_num[$mxSelKey]) ? (int) ($field_num[$mxSelKey]) : ($intCount + 1) * 10),
                                ];
                                $selectArray[] = 'CATALOG_GROUP_'.$arTempo[0];
                            }
                        }
                        ++$intCount;
                    }
                }
            }

            if (!$bNeedPrices) {
                $bNeedCounts = false;
                $arCountFields = [];
            }
        }
        uasort($arSortFields, '__sortCSVOrder');

        $arNeedFields = array_keys($arSortFields);

        if ($first_line_names) {
            $csvFile->SaveFile($_SERVER['DOCUMENT_ROOT'].$SETUP_FILE_NAME, $arNeedFields);
        }

        $res = CIBlockElement::GetList(['ID' => 'ASC'], ['IBLOCK_ID' => $IBLOCK_ID, 'CHECK_PERMISSIONS' => 'N'], false, false, $selectArray);
        while ($res1 = $res->Fetch()) {
            $arResSections = [];
            if ($bNeedGroups) {
                $indreseg = 0;
                $reseg = CIBlockElement::GetElementGroups($res1['ID'], false, ['ID', 'ADDITIONAL_PROPERTY_ID']);
                while ($reseg1 = $reseg->Fetch()) {
                    if (0 < (int) $reseg1['ADDITIONAL_PROPERTY_ID']) {
                        continue;
                    }
                    $sections_path = GetIBlockSectionPath($IBLOCK_ID, $reseg1['ID']);
                    while ($arSection = $sections_path->Fetch()) {
                        $arResSectionTmp = [];
                        foreach ($arAvailGroupFields_names as $key => $value) {
                            $arResSectionTmp[$key] = $arSection[$value['field']];
                        }
                        $arResSections[$indreseg][] = $arResSectionTmp;
                    }
                    ++$indreseg;
                }
                if (empty($arResSections)) {
                    $arResSections[0] = [];
                }
            } else {
                $arResSections[0] = [];
            }

            for ($inds = 0, $intSectCount = count($arResSections); $inds < $intSectCount; ++$inds) {
                $arResFields = [];
                for ($i = 0, $intNFCount = count($arNeedFields); $i < $intNFCount; ++$i) {
                    $bFieldOut = false;
                    if (is_set($arAvailProdFields_names, $arNeedFields[$i])) {
                        $bFieldOut = true;
                        $arResFields[$i] = $res1[$arAvailProdFields_names[$arNeedFields[$i]]['field']];
                        if ('IE_PREVIEW_PICTURE' === $arNeedFields[$i] || 'IE_DETAIL_PICTURE' === $arNeedFields[$i]) {
                            if ((int) $arResFields[$i] > 0) {
                                $db_z = CFile::GetByID((int) $arResFields[$i]);
                                if ($z = $db_z->Fetch()) {
                                    $arResFields[$i] = $z['FILE_NAME'];
                                }
                            } else {
                                $arResFields[$i] = '';
                            }
                        }
                    }

                    if ($boolCatalog) {
                        if (!$bFieldOut && is_set($arAvailPriceFields_names, $arNeedFields[$i])) {
                            $bFieldOut = true;
                            $arResFields[$i] = $res1['CATALOG_'.$arAvailPriceFields_names[$arNeedFields[$i]]['field']];
                        }

                        if (!$bFieldOut && is_set($arAvailCountFields_names, $arNeedFields[$i])) {
                            $bFieldOut = true;
                            $arResFields[$i] = $res1['CATALOG_'.$arAvailCountFields_names[$arNeedFields[$i]]['field']];
                        }
                    }

                    if (!$bFieldOut) {
                        if ('IP_PROP' === substr($arNeedFields[$i], 0, strlen('IP_PROP'))) {
                            $strTempo = substr($arNeedFields[$i], strlen('IP_PROP'));
                            if (!empty($arFileProps) && in_array($strTempo, $arFileProps, true)) {
                                $valueTmp = '';
                                if (0 < (int) $res1['PROPERTY_'.$strTempo.'_VALUE']) {
                                    $arFile = CFile::GetFileArray($res1['PROPERTY_'.$strTempo.'_VALUE']);
                                    if (is_array($arFile)) {
                                        $valueTmp = $arFile['SRC'];
                                    }
                                }
                                $arResFields[$i] = $valueTmp;
                            } else {
                                // $arResFields[$i] = $res1["PROPERTY_".substr($arNeedFields[$i], strlen("IP_PROP"))."_VALUE"];
                                $arResFields[$i] = $res1['PROPERTY_'.$strTempo.'_VALUE'];
                            }
                            $bFieldOut = true;
                        } elseif ($boolCatalog && 'CR_PRICE_' === substr($arNeedFields[$i], 0, strlen('CR_PRICE_'))) {
                            $sPriceTmp = substr($arNeedFields[$i], strlen('CR_PRICE_'));
                            $arPriceTmp = explode('_', $sPriceTmp);

                            if ('' !== $res1['CATALOG_CURRENCY_'.(int) $arPriceTmp[0]]
                                && $res1['CATALOG_CURRENCY_'.(int) $arPriceTmp[0]] !== $arPriceTmp[1]) {
                                $arResFields[$i] = round(CCurrencyRates::ConvertCurrency($res1['CATALOG_PRICE_'.(int) $arPriceTmp[0]], $res1['CATALOG_CURRENCY_'.(int) $arPriceTmp[0]], $arPriceTmp[1]), 2);
                            } else {
                                $arResFields[$i] = $res1['CATALOG_PRICE_'.(int) $arPriceTmp[0]];
                            }
                            $bFieldOut = true;
                        }
                    }

                    if (!$bFieldOut) {
                        foreach ($arAvailGroupFields_names as $key => $value) {
                            if ($key === substr($arNeedFields[$i], 0, strlen($key))
                                && is_numeric(substr($arNeedFields[$i], strlen($key)))) {
                                $bFieldOut = true;
                                $arResFields[$i] = $arResSections[$inds][(int) substr($arNeedFields[$i], strlen($key))][$key];

                                break;
                            }
                        }
                    }
                }
                $csvFile->SaveFile($_SERVER['DOCUMENT_ROOT'].$SETUP_FILE_NAME, $arResFields);
                ++$num_rows_writed;
            }
        }
    }
    // *****************************************************************//
}

if (!empty($arRunErrors)) {
    $strExportErrorMessage = implode('<br />', $arRunErrors);
}

CCatalogDiscountSave::Enable();

if ($bTmpUserCreated) {
    unset($USER);
    if (isset($USER_TMP)) {
        $USER = $USER_TMP;
        unset($USER_TMP);
    }
}
