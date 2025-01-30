<?php

// <title>CSV</title>
IncludeModuleLangFile($_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/catalog/import_setup_templ.php');
$startImportExecTime = getmicrotime();

global $USER;
global $APPLICATION;
$bTmpUserCreated = false;
if (!CCatalog::IsUserExists()) {
    $bTmpUserCreated = true;
    if (isset($USER)) {
        $USER_TMP = $USER;
        unset($USER);
    }

    $USER = new CUser();
}

$strImportErrorMessage = '';
$strImportOKMessage = '';

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

$NUM_CATALOG_LEVELS = (int) COption::GetOptionString('catalog', 'num_catalog_levels');

$max_execution_time = (int) $max_execution_time;
if ($max_execution_time <= 0) {
    $max_execution_time = 0;
}
if (defined('BX_CAT_CRON') && true === BX_CAT_CRON) {
    $max_execution_time = 0;
}

if (defined('CATALOG_LOAD_NO_STEP') && CATALOG_LOAD_NO_STEP) {
    $max_execution_time = 0;
}

$bAllLinesLoaded = true;

$io = CBXVirtualIo::GetInstance();

if (!function_exists('CSVCheckTimeout')) {
    function CSVCheckTimeout($max_execution_time)
    {
        return ($max_execution_time <= 0) || (getmicrotime() - START_EXEC_TIME <= $max_execution_time);
    }
}

$DATA_FILE_NAME = '';

if ('' !== $URL_DATA_FILE) {
    $URL_DATA_FILE = Rel2Abs('/', $URL_DATA_FILE);
    if (file_exists($_SERVER['DOCUMENT_ROOT'].$URL_DATA_FILE) && is_file($_SERVER['DOCUMENT_ROOT'].$URL_DATA_FILE)) {
        $DATA_FILE_NAME = $URL_DATA_FILE;
    }
}

if ('' === $DATA_FILE_NAME) {
    $strImportErrorMessage .= GetMessage('CATI_NO_DATA_FILE').'<br>';
}

$IBLOCK_ID = (int) $IBLOCK_ID;
if ($IBLOCK_ID <= 0) {
    $strImportErrorMessage .= GetMessage('CATI_NO_IBLOCK').'<br>';
} else {
    $arIBlock = CIBlock::GetArrayByID($IBLOCK_ID);
    if (false === $arIBlock) {
        $strImportErrorMessage .= GetMessage('CATI_NO_IBLOCK').'<br>';
    }
}

if ('' === $strImportErrorMessage) {
    $bWorkflow = CModule::IncludeModule('workflow') && ('N' !== $arIBlock['WORKFLOW']);

    $bIBlockIsCatalog = false;
    $arSku = false;
    $rsCatalogs = CCatalog::GetList(
        [],
        ['IBLOCK_ID' => $IBLOCK_ID],
        false,
        false,
        ['IBLOCK_ID', 'PRODUCT_IBLOCK_ID', 'SKU_PROPERTY_ID']
    );
    if ($arCatalog = $rsCatalogs->Fetch()) {
        $bIBlockIsCatalog = true;
        $arCatalog['IBLOCK_ID'] = (int) $arCatalog['IBLOCK_ID'];
        $arCatalog['PRODUCT_IBLOCK_ID'] = (int) $arCatalog['PRODUCT_IBLOCK_ID'];
        $arCatalog['SKU_PROPERTY_ID'] = (int) $arCatalog['SKU_PROPERTY_ID'];
        if (0 < $arCatalog['PRODUCT_IBLOCK_ID'] && 0 < $arCatalog['SKU_PROPERTY_ID']) {
            $arSku = $arCatalog;
        }
    }

    $csvFile = new CCSVData();
    $csvFile->LoadFile($_SERVER['DOCUMENT_ROOT'].$DATA_FILE_NAME);

    if ('F' !== $fields_type && 'R' !== $fields_type) {
        $strImportErrorMessage .= GetMessage('CATI_NO_FILE_FORMAT').'<br>';
    }
}

if ('' === $strImportErrorMessage) {
    $arDataFileFields = [];
    $fields_type = (('F' === $fields_type) ? 'F' : 'R');

    $csvFile->SetFieldsType($fields_type);

    if ('R' === $fields_type) {
        $first_names_r = (('Y' === $first_names_r) ? 'Y' : 'N');
        $csvFile->SetFirstHeader(('Y' === $first_names_r) ? true : false);

        $delimiter_r_char = '';

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
                $delimiter_r_char = substr($delimiter_other_r, 0, 1);

                break;

            case 'TZP':
                $delimiter_r_char = ';';

                break;
        }

        if (1 !== strlen($delimiter_r_char)) {
            $strImportErrorMessage .= GetMessage('CATI_NO_DELIMITER').'<br>';
        }

        if ('' === $strImportErrorMessage) {
            $csvFile->SetDelimiter($delimiter_r_char);
        }
    } else {
        $first_names_f = (('Y' === $first_names_f) ? 'Y' : 'N');
        $csvFile->SetFirstHeader(('Y' === $first_names_f) ? true : false);

        if ('' === $metki_f) {
            $strImportErrorMessage .= GetMessage('CATI_NO_METKI').'<br>';
        }

        if ('' === $strImportErrorMessage) {
            $arMetkiTmp = preg_split('/[\\D]/i', $metki_f);

            $arMetki = [];
            for ($i = 0, $intCount = count($arMetkiTmp); $i < $intCount; ++$i) {
                if ((int) $arMetkiTmp[$i] > 0) {
                    $arMetki[] = (int) $arMetkiTmp[$i];
                }
            }

            if (!is_array($arMetki) || count($arMetki) < 1) {
                $strImportErrorMessage .= GetMessage('CATI_NO_METKI').'<br>';
            }

            if ('' === $strImportErrorMessage) {
                $csvFile->SetWidthMap($arMetki);
            }
        }
    }

    if ('' === $strImportErrorMessage) {
        $bFirstHeaderTmp = $csvFile->GetFirstHeader();
        $csvFile->SetFirstHeader(false);
        if ($arRes = $csvFile->Fetch()) {
            for ($i = 0, $intCount = count($arRes); $i < $intCount; ++$i) {
                $arDataFileFields[$i] = $arRes[$i];
            }
        } else {
            $strImportErrorMessage .= GetMessage('CATI_NO_DATA').'<br>';
        }
        global $NUM_FIELDS;
        $NUM_FIELDS = count($arDataFileFields);
    }
}

if ('' === $strImportErrorMessage) {
    $bFieldsPres = false;
    for ($i = 0; $i < $NUM_FIELDS; ++$i) {
        if ((${'field_'.$i}) !== '') {
            $bFieldsPres = true;

            break;
        }
    }
    if (!$bFieldsPres) {
        $strImportErrorMessage .= GetMessage('CATI_NO_FIELDS').'<br>';
    }
}

if ('' === $strImportErrorMessage) {
    $USE_TRANSLIT = (isset($USE_TRANSLIT) && 'Y' === $USE_TRANSLIT ? 'Y' : 'N');
    if ('Y' === $USE_TRANSLIT) {
        $boolOutTranslit = false;
        if (isset($arIBlock['FIELDS']['CODE']['DEFAULT_VALUE'])) {
            if ('Y' === $arIBlock['FIELDS']['CODE']['DEFAULT_VALUE']['TRANSLITERATION']
                && 'Y' === $arIBlock['FIELDS']['CODE']['DEFAULT_VALUE']['USE_GOOGLE']) {
                $boolOutTranslit = true;
            }
        }
        if (isset($arIBlock['FIELDS']['SECTION_CODE']['DEFAULT_VALUE'])) {
            if ('Y' === $arIBlock['FIELDS']['SECTION_CODE']['DEFAULT_VALUE']['TRANSLITERATION']
                && 'Y' === $arIBlock['FIELDS']['SECTION_CODE']['DEFAULT_VALUE']['USE_GOOGLE']) {
                $boolOutTranslit = true;
            }
        }
        if ($boolOutTranslit) {
            $USE_TRANSLIT = 'N';
            $strImportErrorMessage .= GetMessage('CATI_USE_CODE_TRANSLIT_OUT').'<br>';
        }
    }
    if ('Y' === $USE_TRANSLIT) {
        $TRANSLIT_LANG = (isset($TRANSLIT_LANG) ? (string) $TRANSLIT_LANG : '');
        if (!empty($TRANSLIT_LANG)) {
            $rsTransLangs = CLanguage::GetByID($TRANSLIT_LANG);
            if (!($arTransLang = $rsTransLangs->Fetch())) {
                $TRANSLIT_LANG = '';
            }
        }
        if (empty($TRANSLIT_LANG)) {
            $USE_TRANSLIT = 'N';
            $strImportErrorMessage .= GetMessage('CATI_CODE_TRANSLIT_LANG_ERR').'<br>';
        }
    }
}

$IMAGE_RESIZE = (isset($IMAGE_RESIZE) && 'Y' === $IMAGE_RESIZE ? 'Y' : 'N');
$CLEAR_EMPTY_PRICE = (isset($CLEAR_EMPTY_PRICE) && 'Y' === $CLEAR_EMPTY_PRICE ? 'Y' : 'N');
$CML2_LINK_IS_XML = (isset($CML2_LINK_IS_XML) && 'Y' === $CML2_LINK_IS_XML ? 'Y' : 'N');
if (empty($arSku)) {
    $CML2_LINK_IS_XML = 'N';
}

if ('' === $strImportErrorMessage) {
    $currentUserID = $USER->GetID();

    $boolUseStoreControl = 'Y' === COption::GetOptionString('catalog', 'default_use_store_control', 'N');
    $arDisableFields = [
        'CP_QUANTITY' => true,
        'CP_PURCHASING_PRICE' => true,
        'CP_PURCHASING_CURRENCY' => true,
    ];

    $arProductCache = [];
    $arPropertyListCache = [];
    $arSectionCache = [];
    $arElementCache = [];

    $csvFile->SetPos($CUR_FILE_POS);
    $arRes = $csvFile->Fetch();
    if ($CUR_FILE_POS <= 0 && $bFirstHeaderTmp) {
        $arRes = $csvFile->Fetch();
    }

    $bs = new CIBlockSection();
    $el = new CIBlockElement();
    $bWasIterations = false;

    if ($arRes) {
        $bWasIterations = true;
        if ($bFirstLoadStep) {
            $tmpid = md5(uniqid(''));
            $line_num = 0;
            $correct_lines = 0;
            $error_lines = 0;
            $killed_lines = 0;

            $arIBlockProperty = [];
            $arIBlockPropertyValue = [];
            $bThereIsGroups = false;
            $bDeactivationStarted = false;
            $arProductGroups = [];
            $bUpdatePrice = 'N';
        }

        $boolTranslitElement = false;
        $boolTranslitSection = false;
        $arTranslitElement = [];
        $arTranslitSection = [];
        if ('Y' === $USE_TRANSLIT) {
            if (isset($arIBlock['FIELDS']['CODE']['DEFAULT_VALUE'])) {
                $arTransSettings = $arIBlock['FIELDS']['CODE']['DEFAULT_VALUE'];
                $boolTranslitElement = ('Y' === $arTransSettings['TRANSLITERATION'] ? true : false);
                $arTranslitElement = [
                    'max_len' => $arTransSettings['TRANS_LEN'],
                    'change_case' => $arTransSettings['TRANS_CASE'],
                    'replace_space' => $arTransSettings['TRANS_SPACE'],
                    'replace_other' => $arTransSettings['TRANS_OTHER'],
                    'delete_repeat_replace' => ('Y' === $arTransSettings['TRANS_EAT'] ? true : false),
                    'use_google' => ('Y' === $arTransSettings['USE_GOOGLE'] ? true : false),
                ];
            }
            if (isset($arIBlock['FIELDS']['SECTION_CODE']['DEFAULT_VALUE'])) {
                $arTransSettings = $arIBlock['FIELDS']['SECTION_CODE']['DEFAULT_VALUE'];
                $boolTranslitSection = ('Y' === $arTransSettings['TRANSLITERATION'] ? true : false);
                $arTranslitSection = [
                    'max_len' => $arTransSettings['TRANS_LEN'],
                    'change_case' => $arTransSettings['TRANS_CASE'],
                    'replace_space' => $arTransSettings['TRANS_SPACE'],
                    'replace_other' => $arTransSettings['TRANS_OTHER'],
                    'delete_repeat_replace' => ('Y' === $arTransSettings['TRANS_EAT'] ? true : false),
                    'use_google' => ('Y' === $arTransSettings['USE_GOOGLE'] ? true : false),
                ];
            }
        }

        // Prepare load arrays
        $strAvailGroupFields = COption::GetOptionString('catalog', 'allowed_group_fields', $defCatalogAvailGroupFields);
        $arAvailGroupFields = explode(',', $strAvailGroupFields);
        $arAvailGroupFields_names = [];
        for ($i = 0, $intCount = count($arAvailGroupFields), $intCount2 = count($arCatalogAvailGroupFields); $i < $intCount; ++$i) {
            for ($j = 0; $j < $intCount2; ++$j) {
                if ($arCatalogAvailGroupFields[$j]['value'] === $arAvailGroupFields[$i]) {
                    $arAvailGroupFields_names[$arAvailGroupFields[$i]] = [
                        'field' => $arCatalogAvailGroupFields[$j]['field'],
                        'important' => $arCatalogAvailGroupFields[$j]['important'],
                    ];

                    break;
                }
            }
        }

        // Prepare load arrays
        $strAvailProdFields = COption::GetOptionString('catalog', 'allowed_product_fields', $defCatalogAvailProdFields);
        $arAvailProdFields = explode(',', $strAvailProdFields);
        $arAvailProdFields_names = [];
        for ($i = 0, $intCount = count($arAvailProdFields), $intCount2 = count($arCatalogAvailProdFields); $i < $intCount; ++$i) {
            for ($j = 0; $j < $intCount2; ++$j) {
                if ($arCatalogAvailProdFields[$j]['value'] === $arAvailProdFields[$i]) {
                    $arAvailProdFields_names[$arAvailProdFields[$i]] = [
                        'field' => $arCatalogAvailProdFields[$j]['field'],
                        'important' => $arCatalogAvailProdFields[$j]['important'],
                    ];

                    break;
                }
            }
        }

        // Prepare load arrays
        $strAvailPriceFields = COption::GetOptionString('catalog', 'allowed_product_fields', $defCatalogAvailPriceFields);
        $arAvailPriceFields = explode(',', $strAvailPriceFields);
        $arAvailPriceFields_names = [];
        for ($i = 0, $intCount = count($arAvailPriceFields), $intCount2 = count($arCatalogAvailPriceFields); $i < $intCount; ++$i) {
            if ($boolUseStoreControl && array_key_exists($arAvailPriceFields[$i], $arDisableFields)) {
                continue;
            }

            for ($j = 0; $j < $intCount2; ++$j) {
                if ($arCatalogAvailPriceFields[$j]['value'] === $arAvailPriceFields[$i]) {
                    $arAvailPriceFields_names[$arAvailPriceFields[$i]] = [
                        'field' => $arCatalogAvailPriceFields[$j]['field'],
                        'important' => $arCatalogAvailPriceFields[$j]['important'],
                    ];

                    break;
                }
            }
        }

        // Prepare load arrays
        $strAvailValueFields = COption::GetOptionString('catalog', 'allowed_price_fields', $defCatalogAvailValueFields);
        $arAvailValueFields = explode(',', $strAvailValueFields);
        $arAvailValueFields_names = [];
        for ($i = 0, $intCount = count($arAvailValueFields), $intCount2 = count($arCatalogAvailValueFields); $i < $intCount; ++$i) {
            for ($j = 0; $j < $intCount2; ++$j) {
                if ($arCatalogAvailValueFields[$j]['value'] === $arAvailValueFields[$i]) {
                    $arAvailValueFields_names[$arAvailValueFields[$i]] = [
                        'field_name_size' => $arCatalogAvailValueFields[$j]['value_size'],
                        'field' => $arCatalogAvailValueFields[$j]['field'],
                        'important' => $arCatalogAvailValueFields[$j]['important'],
                    ];

                    break;
                }
            }
        }

        // main
        do {
            $strErrorR = '';
            ++$line_num;

            $arGroupsTmp = [];

            for ($i = 0; $i < $NUM_CATALOG_LEVELS; ++$i) {
                $arGroupsTmp1 = [];
                foreach ($arAvailGroupFields_names as $key => $value) {
                    $ind = -1;
                    for ($i_tmp = 0; $i_tmp < $NUM_FIELDS; ++$i_tmp) {
                        if (${'field_'.$i_tmp} === $key.$i) {
                            $ind = $i_tmp;

                            break;
                        }
                    }

                    if ($ind > -1) {
                        $arGroupsTmp1[$value['field']] = trim($arRes[$ind]);
                        $bThereIsGroups = true;
                    }
                }
                $arGroupsTmp[] = $arGroupsTmp1;
            }

            $i = count($arGroupsTmp) - 1;
            while ($i >= 0) {
                foreach ($arAvailGroupFields_names as $key => $value) {
                    if ('Y' === $value['important'] && isset($arGroupsTmp[$i][$value['field']]) && '' !== $arGroupsTmp[$i][$value['field']]) {
                        break 2;
                    }
                }
                unset($arGroupsTmp[$i]);
                --$i;
            }

            for ($i = 0, $intCount = count($arGroupsTmp); $i < $intCount; ++$i) {
                if (isset($arGroupsTmp[$i]['NAME']) && '' === $arGroupsTmp[$i]['NAME']) {
                    $arGroupsTmp[$i]['NAME'] = GetMessage('CATI_NOMAME');
                }
                $arGroupsTmp[$i]['TMP_ID'] = $tmpid;
            }

            $LAST_GROUP_CODE = 0;
            $sectionKey = '';
            for ($i = 0, $intCount = count($arGroupsTmp); $i < $intCount; ++$i) {
                $sectionFilter = '';
                $arFilter = ['IBLOCK_ID' => $IBLOCK_ID];
                if (isset($arGroupsTmp[$i]['XML_ID']) && '' !== $arGroupsTmp[$i]['XML_ID']) {
                    $arFilter['=XML_ID'] = $arGroupsTmp[$i]['XML_ID'];
                    $sectionFilter = 'XML'.md5($arGroupsTmp[$i]['XML_ID']);
                } elseif (isset($arGroupsTmp[$i]['NAME']) && '' !== $arGroupsTmp[$i]['NAME']) {
                    $arFilter['=NAME'] = $arGroupsTmp[$i]['NAME'];
                    $sectionFilter = 'NAME'.md5($arGroupsTmp[$i]['NAME']);
                }

                if ($LAST_GROUP_CODE > 0) {
                    $arFilter['SECTION_ID'] = $LAST_GROUP_CODE;
                    $arGroupsTmp[$i]['IBLOCK_SECTION_ID'] = $LAST_GROUP_CODE;
                } else {
                    $arFilter['SECTION_ID'] = 0;
                    $arGroupsTmp[$i]['IBLOCK_SECTION_ID'] = false;
                }
                $sectionKey .= $LAST_GROUP_CODE.':';
                $sectionIndex = $sectionKey.$sectionFilter;
                if (!isset($arSectionCache[$sectionIndex])) {
                    if ($boolTranslitSection) {
                        if (!isset($arGroupsTmp[$i]['CODE']) || '' === $arGroupsTmp[$i]['CODE']) {
                            $arGroupsTmp[$i]['CODE'] = CUtil::translit($arGroupsTmp[$i]['NAME'], $TRANSLIT_LANG, $arTranslitSection);
                        }
                    }

                    if (isset($arGroupsTmp[$i]['PICTURE'])) {
                        $bFilePres = false;
                        if ('' !== $arGroupsTmp[$i]['PICTURE']) {
                            if (preg_match('/^(http|https):\\/\\//', $arGroupsTmp[$i]['PICTURE'])) {
                                $arGroupsTmp[$i]['PICTURE'] = CFile::MakeFileArray($arGroupsTmp[$i]['PICTURE']);
                            } else {
                                $arGroupsTmp[$i]['PICTURE'] = CFile::MakeFileArray($io->GetPhysicalName($_SERVER['DOCUMENT_ROOT'].$PATH2IMAGE_FILES.'/'.$arGroupsTmp[$i]['PICTURE']));
                                if (!empty($arGroupsTmp[$i]['PICTURE']) && is_array($arGroupsTmp[$i]['PICTURE'])) {
                                    $arGroupsTmp[$i]['PICTURE']['COPY_FILE'] = 'Y';
                                }
                            }
                            $bFilePres = (
                                !empty($arGroupsTmp[$i]['PICTURE'])
                                && isset($arGroupsTmp[$i]['PICTURE']['tmp_name'])
                                && '' !== $arGroupsTmp[$i]['PICTURE']['tmp_name']
                            );
                        }
                        if (!$bFilePres) {
                            unset($arGroupsTmp[$i]['PICTURE']);
                        }
                    }
                    if (isset($arGroupsTmp[$i]['DETAIL_PICTURE'])) {
                        $bFilePres = false;
                        if ('' !== $arGroupsTmp[$i]['DETAIL_PICTURE']) {
                            if (preg_match('/^(http|https):\\/\\//', $arGroupsTmp[$i]['DETAIL_PICTURE'])) {
                                $arGroupsTmp[$i]['DETAIL_PICTURE'] = CFile::MakeFileArray($arGroupsTmp[$i]['DETAIL_PICTURE']);
                            } else {
                                $arGroupsTmp[$i]['DETAIL_PICTURE'] = CFile::MakeFileArray($io->GetPhysicalName($_SERVER['DOCUMENT_ROOT'].$PATH2IMAGE_FILES.'/'.$arGroupsTmp[$i]['DETAIL_PICTURE']));
                                if (!empty($arGroupsTmp[$i]['DETAIL_PICTURE']) && is_array($arGroupsTmp[$i]['DETAIL_PICTURE'])) {
                                    $arGroupsTmp[$i]['DETAIL_PICTURE']['COPY_FILE'] = 'Y';
                                }
                            }
                            $bFilePres = (
                                !empty($arGroupsTmp[$i]['DETAIL_PICTURE'])
                                && isset($arGroupsTmp[$i]['DETAIL_PICTURE']['tmp_name'])
                                && '' !== $arGroupsTmp[$i]['DETAIL_PICTURE']['tmp_name']
                            );
                        }
                        if (!$bFilePres) {
                            unset($arGroupsTmp[$i]['DETAIL_PICTURE']);
                        }
                    }

                    $res = CIBlockSection::GetList([], $arFilter, false, ['ID']);
                    if ($arr = $res->Fetch()) {
                        $LAST_GROUP_CODE = $arr['ID'];
                        $res = $bs->Update($LAST_GROUP_CODE, $arGroupsTmp[$i], true, true, 'Y' === $IMAGE_RESIZE);
                        if (!$res) {
                            $strErrorR .= GetMessage('CATI_LINE_NO').' '.$line_num.'. '.GetMessage('CATI_ERR_UPDATE_SECT').' '.$bs->LAST_ERROR.'<br>';
                        }
                    } else {
                        $arGroupsTmp[$i]['IBLOCK_ID'] = $IBLOCK_ID;
                        $arGroupsTmp[$i]['ACTIVE'] = (isset($arGroupsTmp[$i]['ACTIVE']) && 'N' === $arGroupsTmp[$i]['ACTIVE'] ? 'N' : 'Y');
                        $LAST_GROUP_CODE = $bs->Add($arGroupsTmp[$i], true, true, 'Y' === $IMAGE_RESIZE);
                        if (!$LAST_GROUP_CODE) {
                            $strErrorR .= GetMessage('CATI_LINE_NO').' '.$line_num.'. '.GetMessage('CATI_ERR_ADD_SECT').' '.$bs->LAST_ERROR.'<br>';
                        }
                    }

                    if ('' === $strErrorR) {
                        $arSectionCache[$sectionIndex] = $LAST_GROUP_CODE;
                    }
                } else {
                    $LAST_GROUP_CODE = $arSectionCache[$sectionIndex];
                }
            }

            if ('' === $strErrorR) {
                $arLoadProductArray = [
                    'MODIFIED_BY' => $currentUserID,
                    'IBLOCK_ID' => $IBLOCK_ID,
                    'TMP_ID' => $tmpid,
                ];
                foreach ($arAvailProdFields_names as $key => $value) {
                    $ind = -1;
                    for ($i_tmp = 0; $i_tmp < $NUM_FIELDS; ++$i_tmp) {
                        if (${'field_'.$i_tmp} === $key) {
                            $ind = $i_tmp;

                            break;
                        }
                    }

                    if ($ind > -1) {
                        $arLoadProductArray[$value['field']] = trim($arRes[$ind]);
                    }
                }

                $arFilter = ['IBLOCK_ID' => $IBLOCK_ID];
                if (isset($arLoadProductArray['XML_ID']) && '' !== $arLoadProductArray['XML_ID']) {
                    $arFilter['=XML_ID'] = $arLoadProductArray['XML_ID'];
                } else {
                    if (isset($arLoadProductArray['NAME']) && '' !== $arLoadProductArray['NAME']) {
                        $arFilter['=NAME'] = $arLoadProductArray['NAME'];
                    } else {
                        $strErrorR .= GetMessage('CATI_LINE_NO').' '.$line_num.'. '.GetMessage('CATI_NOIDNAME').'<br>';
                    }
                }
            }

            if ('' === $strErrorR) {
                if (isset($arLoadProductArray['PREVIEW_PICTURE'])) {
                    $bFilePres = false;
                    if ('' !== $arLoadProductArray['PREVIEW_PICTURE']) {
                        if (preg_match('/^(http|https):\\/\\//', $arLoadProductArray['PREVIEW_PICTURE'])) {
                            $arLoadProductArray['PREVIEW_PICTURE'] = CFile::MakeFileArray($arLoadProductArray['PREVIEW_PICTURE']);
                        } else {
                            $arLoadProductArray['PREVIEW_PICTURE'] = CFile::MakeFileArray($io->GetPhysicalName($_SERVER['DOCUMENT_ROOT'].$PATH2IMAGE_FILES.'/'.$arLoadProductArray['PREVIEW_PICTURE']));
                            if (!empty($arLoadProductArray['PREVIEW_PICTURE']) && is_array($arLoadProductArray['PREVIEW_PICTURE'])) {
                                $arLoadProductArray['PREVIEW_PICTURE']['COPY_FILE'] = 'Y';
                            }
                        }
                        $bFilePres = (
                            !empty($arLoadProductArray['PREVIEW_PICTURE'])
                            && isset($arLoadProductArray['PREVIEW_PICTURE']['tmp_name'])
                            && '' !== $arLoadProductArray['PREVIEW_PICTURE']['tmp_name']
                        );
                    }
                    if (!$bFilePres) {
                        unset($arLoadProductArray['PREVIEW_PICTURE']);
                    }
                }

                if (isset($arLoadProductArray['DETAIL_PICTURE'])) {
                    $bFilePres = false;
                    if ('' !== $arLoadProductArray['DETAIL_PICTURE']) {
                        if (preg_match('/^(http|https):\\/\\//', $arLoadProductArray['DETAIL_PICTURE'])) {
                            $arLoadProductArray['DETAIL_PICTURE'] = CFile::MakeFileArray($arLoadProductArray['DETAIL_PICTURE']);
                        } else {
                            $arLoadProductArray['DETAIL_PICTURE'] = CFile::MakeFileArray($io->GetPhysicalName($_SERVER['DOCUMENT_ROOT'].$PATH2IMAGE_FILES.'/'.$arLoadProductArray['DETAIL_PICTURE']));
                            if (!empty($arLoadProductArray['DETAIL_PICTURE']) && is_array($arLoadProductArray['DETAIL_PICTURE'])) {
                                $arLoadProductArray['DETAIL_PICTURE']['COPY_FILE'] = 'Y';
                            }
                        }
                        $bFilePres = (
                            !empty($arLoadProductArray['DETAIL_PICTURE'])
                            && isset($arLoadProductArray['DETAIL_PICTURE']['tmp_name'])
                            && '' !== $arLoadProductArray['DETAIL_PICTURE']['tmp_name']
                        );
                    }
                    if (!$bFilePres) {
                        unset($arLoadProductArray['DETAIL_PICTURE']);
                    }
                }

                if ($boolTranslitElement) {
                    if (!isset($arLoadProductArray['CODE']) || '' === $arLoadProductArray['CODE']) {
                        $arLoadProductArray['CODE'] = CUtil::translit($arLoadProductArray['NAME'], $TRANSLIT_LANG, $arTranslitElement);
                    }
                }

                $res = CIBlockElement::GetList(
                    [],
                    $arFilter,
                    false,
                    false,
                    ['ID', 'PREVIEW_PICTURE', 'DETAIL_PICTURE']
                );
                if ($arr = $res->Fetch()) {
                    $PRODUCT_ID = $arr['ID'];
                    if (isset($arLoadProductArray['PREVIEW_PICTURE']) && (int) $arr['PREVIEW_PICTURE'] > 0) {
                        $arLoadProductArray['PREVIEW_PICTURE']['old_file'] = $arr['PREVIEW_PICTURE'];
                    }
                    if (isset($arLoadProductArray['DETAIL_PICTURE']) && (int) $arr['DETAIL_PICTURE'] > 0) {
                        $arLoadProductArray['DETAIL_PICTURE']['old_file'] = $arr['DETAIL_PICTURE'];
                    }
                    if ($bThereIsGroups) {
                        $LAST_GROUP_CODE_tmp = (($LAST_GROUP_CODE > 0) ? $LAST_GROUP_CODE : false);
                        if (!isset($arProductGroups[$PRODUCT_ID])) {
                            $arProductGroups[$PRODUCT_ID] = [];
                        }
                        if (!in_array($LAST_GROUP_CODE_tmp, $arProductGroups[$PRODUCT_ID], true)) {
                            $arProductGroups[$PRODUCT_ID][] = $LAST_GROUP_CODE_tmp;
                        }
                        $arLoadProductArray['IBLOCK_SECTION'] = $arProductGroups[$PRODUCT_ID];
                    }
                    $res = $el->Update($PRODUCT_ID, $arLoadProductArray, $bWorkflow, true, 'Y' === $IMAGE_RESIZE);
                } else {
                    if ($bThereIsGroups) {
                        $arLoadProductArray['IBLOCK_SECTION'] = (($LAST_GROUP_CODE > 0) ? $LAST_GROUP_CODE : false);
                    }
                    if ('N' !== $arLoadProductArray['ACTIVE']) {
                        $arLoadProductArray['ACTIVE'] = 'Y';
                    }

                    $PRODUCT_ID = $el->Add($arLoadProductArray, $bWorkflow, true, 'Y' === $IMAGE_RESIZE);
                    if ($bThereIsGroups) {
                        if (!isset($arProductGroups[$PRODUCT_ID])) {
                            $arProductGroups[$PRODUCT_ID] = [];
                        }
                        $arProductGroups[$PRODUCT_ID][] = (($LAST_GROUP_CODE > 0) ? $LAST_GROUP_CODE : false);
                    }
                    $res = ($PRODUCT_ID > 0);
                }

                if (!$res) {
                    $strErrorR .= GetMessage('CATI_LINE_NO').' '.$line_num.'. '.GetMessage('CATI_ERROR_LOADING').' '.$el->LAST_ERROR.'<br>';
                }
            }

            if ('' === $strErrorR) {
                $PROP = [];
                for ($i = 0; $i < $NUM_FIELDS; ++$i) {
                    if (0 === strncmp(${'field_'.$i}, 'IP_PROP', 7)) {
                        $cur_prop_id = (int) substr(${'field_'.$i}, 7);
                        if (!isset($arIBlockProperty[$cur_prop_id])) {
                            $res1 = CIBlockProperty::GetByID($cur_prop_id, $IBLOCK_ID);
                            if ($arRes1 = $res1->Fetch()) {
                                $arIBlockProperty[$cur_prop_id] = $arRes1;
                            } else {
                                $arIBlockProperty[$cur_prop_id] = [];
                            }
                        }
                        if (!empty($arIBlockProperty[$cur_prop_id]) && is_array($arIBlockProperty[$cur_prop_id])) {
                            if ('Y' === $CML2_LINK_IS_XML && $cur_prop_id === $arSku['SKU_PROPERTY_ID']) {
                                $arRes[$i] = trim($arRes[$i]);
                                if ('' !== $arRes[$i]) {
                                    if (!isset($arProductCache[$arRes[$i]])) {
                                        $rsProducts = CIBlockElement::GetList(
                                            [],
                                            ['IBLOCK_ID' => $arSku['PRODUCT_IBLOCK_ID'], '=XML_ID' => $arRes[$i]],
                                            false,
                                            false,
                                            ['ID']
                                        );
                                        if ($arParentProduct = $rsProducts->Fetch()) {
                                            $arProductCache[$arRes[$i]] = $arParentProduct['ID'];
                                        }
                                    }
                                    $arRes[$i] = (isset($arProductCache[$arRes[$i]]) ? $arProductCache[$arRes[$i]] : '');
                                }
                            } elseif ('L' === $arIBlockProperty[$cur_prop_id]['PROPERTY_TYPE']) {
                                $arRes[$i] = trim($arRes[$i]);
                                if ('' !== $arRes[$i]) {
                                    $propValueHash = md5($arRes[$i]);
                                    if (!isset($arPropertyListCache[$cur_prop_id])) {
                                        $arPropertyListCache[$cur_prop_id] = [];
                                        $propEnumRes = CIBlockPropertyEnum::GetList(
                                            ['SORT' => 'ASC', 'VALUE' => 'ASC'],
                                            ['IBLOCK_ID' => $IBLOCK_ID, 'PROPERTY_ID' => $arIBlockProperty[$cur_prop_id]['ID']]
                                        );
                                        while ($propEnumValue = $propEnumRes->Fetch()) {
                                            $arPropertyListCache[$cur_prop_id][md5($propEnumValue['VALUE'])] = $propEnumValue['ID'];
                                        }
                                    }
                                    if (!isset($arPropertyListCache[$cur_prop_id][$propValueHash])) {
                                        $arPropertyListCache[$cur_prop_id][$propValueHash] = CIBlockPropertyEnum::Add(
                                            [
                                                'PROPERTY_ID' => $arIBlockProperty[$cur_prop_id]['ID'],
                                                'VALUE' => $arRes[$i],
                                                'TMP_ID' => $tmpid,
                                            ]
                                        );
                                    }
                                    if (isset($arPropertyListCache[$cur_prop_id][$propValueHash])) {
                                        $arRes[$i] = $arPropertyListCache[$cur_prop_id][$propValueHash];
                                    } else {
                                        $arRes[$i] = '';
                                    }
                                }
                            } elseif ('F' === $arIBlockProperty[$cur_prop_id]['PROPERTY_TYPE']) {
                                if (preg_match('/^(http|https):\\/\\//', $arRes[$i])) {
                                    $arRes[$i] = CFile::MakeFileArray($arRes[$i]);
                                } else {
                                    $arRes[$i] = CFile::MakeFileArray($io->GetPhysicalName($_SERVER['DOCUMENT_ROOT'].$PATH2IMAGE_FILES.$arRes[$i]));
                                }

                                if (!is_array($arRes[$i]) || !array_key_exists('tmp_name', $arRes[$i])) {
                                    $arRes[$i] = '';
                                }
                            }

                            if ('Y' === $arIBlockProperty[$cur_prop_id]['MULTIPLE']) {
                                if (
                                    !isset($arIBlockPropertyValue[$PRODUCT_ID][$cur_prop_id])
                                    || !is_array($arIBlockPropertyValue[$PRODUCT_ID][$cur_prop_id])
                                    || !in_array(trim($arRes[$i]), $arIBlockPropertyValue[$PRODUCT_ID][$cur_prop_id], true)
                                ) {
                                    $arIBlockPropertyValue[$PRODUCT_ID][$cur_prop_id][] = is_array($arRes[$i]) ? $arRes[$i] : trim($arRes[$i]);
                                }

                                $PROP[$cur_prop_id] = $arIBlockPropertyValue[$PRODUCT_ID][$cur_prop_id];
                            } else {
                                $PROP[$cur_prop_id][] = is_array($arRes[$i]) ? $arRes[$i] : trim($arRes[$i]);
                            }
                        }
                    }
                }

                CIBlockElement::SetPropertyValuesEx($PRODUCT_ID, $IBLOCK_ID, $PROP);
            }

            if ('' === $strErrorR && $bIBlockIsCatalog) {
                $arLoadOfferArray = [
                    'ID' => $PRODUCT_ID,
                ];
                foreach ($arAvailPriceFields_names as $key => $value) {
                    $ind = -1;
                    for ($i_tmp = 0; $i_tmp < $NUM_FIELDS; ++$i_tmp) {
                        if (${'field_'.$i_tmp} === $key) {
                            $ind = $i_tmp;

                            break;
                        }
                    }

                    if ($ind > -1) {
                        $arLoadOfferArray[$value['field']] = trim($arRes[$ind]);
                    }
                }

                CCatalogProduct::Add($arLoadOfferArray);

                $quantityFrom = 0;
                $quantityTo = 0;
                for ($j = 0; $j < $NUM_FIELDS; ++$j) {
                    if ('CV_QUANTITY_FROM' === ${'field_'.$j}) {
                        $quantityFrom = (int) $arRes[$j];
                    } elseif ('CV_QUANTITY_TO' === ${'field_'.$j}) {
                        $quantityTo = (int) $arRes[$j];
                    }
                }
                if (0 >= $quantityFrom) {
                    $quantityFrom = false;
                }
                if (0 >= $quantityTo) {
                    $quantityTo = false;
                }

                $arFields = [];
                for ($j = 0; $j < $NUM_FIELDS; ++$j) {
                    foreach ($arAvailValueFields_names as $key => $value) {
                        if (0 === strncmp(${'field_'.$j}, $key.'_', $value['field_name_size'] + 1)) {
                            $strTempKey = (int) substr(${'field_'.$j}, $value['field_name_size'] + 1);
                            if (!isset($arFields[$strTempKey])) {
                                $arFields[$strTempKey] = [
                                    'PRODUCT_ID' => $PRODUCT_ID,
                                    'CATALOG_GROUP_ID' => $strTempKey,
                                    'QUANTITY_FROM' => $quantityFrom,
                                    'QUANTITY_TO' => $quantityTo,
                                    'TMP_ID' => $tmpid,
                                ];
                            }
                            $arFields[$strTempKey][$value['field']] = trim($arRes[$j]);
                        }
                    }
                }

                foreach ($arFields as $key => $value) {
                    $strPriceErr = '';
                    $res = CPrice::GetListEx(
                        [],
                        [
                            'PRODUCT_ID' => $value['PRODUCT_ID'],
                            'CATALOG_GROUP_ID' => $value['CATALOG_GROUP_ID'],
                            'QUANTITY_FROM' => $value['QUANTITY_FROM'],
                            'QUANTITY_TO' => $value['QUANTITY_TO'],
                        ],
                        false,
                        false,
                        ['ID']
                    );
                    if ($arr = $res->Fetch()) {
                        $boolEraseClear = false;
                        if ('Y' === $CLEAR_EMPTY_PRICE) {
                            $boolEraseClear = (
                                (isset($value['PRICE']) && '' === $value['PRICE'])
                                && (isset($value['CURRENCY']) && '' === $value['CURRENCY'])
                            );
                        }
                        if ($boolEraseClear) {
                            if (!CPrice::Delete($arr['ID'])) {
                                $strPriceErr = GetMessage('CATI_ERR_PRICE_DELETE');
                            }
                        } else {
                            if (CPrice::Update($arr['ID'], $value)) {
                                $bUpdatePrice = 'Y';
                            } else {
                                if ($ex = $APPLICATION->GetException()) {
                                    $strPriceErr = GetMessage('CATI_ERR_PRICE_UPDATE').$ex->GetString();
                                } else {
                                    $strPriceErr = GetMessage('CATI_ERR_PRICE_UPDATE_UNKNOWN');
                                }
                            }
                        }
                    } else {
                        $boolEmptyNewPrice = (
                            (isset($value['PRICE']) && '' === $value['PRICE'])
                            && (isset($value['CURRENCY']) && '' === $value['CURRENCY'])
                        );
                        if (!$boolEmptyNewPrice) {
                            if (CPrice::Add($value)) {
                                $bUpdatePrice = 'Y';
                            } else {
                                if ($ex = $APPLICATION->GetException()) {
                                    $strPriceErr = GetMessage('CATI_ERR_PRICE_ADD').$ex->GetString();
                                } else {
                                    $strPriceErr = GetMessage('CATI_ERR_PRICE_ADD_UNKNOWN');
                                }
                            }
                        }
                    }
                    if ('' !== $strPriceErr) {
                        $strErrorR .= GetMessage('CATI_LINE_NO').' '.$line_num.'. '.$strPriceErr.'<br>';

                        break;
                    }
                }
            }

            if ('' === $strErrorR) {
                ++$correct_lines;
            } else {
                ++$error_lines;
                $strImportErrorMessage .= $strErrorR;
            }

            if (!($bAllLinesLoaded = CSVCheckTimeout($max_execution_time))) {
                break;
            }
        } while ($arRes = $csvFile->Fetch());
    }

    // ////////////////////////////
    // start additional actions //
    // ////////////////////////////

    // activate 'in-file' sections
    if ($bAllLinesLoaded && $bThereIsGroups && 'A' === $inFileAction && !$bDeactivationStarted) {
        $res = CIBlockSection::GetList(
            [],
            ['IBLOCK_ID' => $IBLOCK_ID, 'TMP_ID' => $tmpid, 'ACTIVE' => 'N'],
            false,
            ['ID', 'NAME']
        );
        while ($arr = $res->Fetch()) {
            $bs->Update($arr['ID'], ['NAME' => $arr['NAME'], 'ACTIVE' => 'Y']);
            if (!($bAllLinesLoaded = CSVCheckTimeout($max_execution_time))) {
                break;
            }
        }
    }

    // activate 'in-file' elements
    if ($bAllLinesLoaded && 'A' === $inFileAction && !$bDeactivationStarted) {
        $res = CIBlockElement::GetList(
            [],
            ['IBLOCK_ID' => $IBLOCK_ID, 'TMP_ID' => $tmpid, 'ACTIVE' => 'N'],
            false,
            false,
            ['ID']
        );
        while ($arr = $res->Fetch()) {
            $el->Update($arr['ID'], ['ACTIVE' => 'Y']);

            if (!($bAllLinesLoaded = CSVCheckTimeout($max_execution_time))) {
                break;
            }
        }
    }

    // update or delete 'not-in-file sections'
    if ($bAllLinesLoaded && 'F' !== $outFileAction && $bThereIsGroups) {
        $res = CIBlockSection::GetList(
            [],
            ['IBLOCK_ID' => $IBLOCK_ID, '!TMP_ID' => $tmpid],
            false,
            ['ID', 'NAME']
        );

        while ($arr = $res->Fetch()) {
            if ('D' === $outFileAction) {
                CIBlockSection::Delete($arr['ID']);
            } elseif ('F' === $outFileAction) {
            } else { // H or M
                $bDeactivationStarted = true;
                $bs->Update($arr['ID'], ['NAME' => $arr['NAME'], 'ACTIVE' => 'N', 'TMP_ID' => $tmpid]);
            }

            if (!($bAllLinesLoaded = CSVCheckTimeout($max_execution_time))) {
                break;
            }
        }
    }

    // update or delete 'not-in-file' elements
    if ($bAllLinesLoaded && 'F' !== $outFileAction) {
        $arProductArray = [
            'QUANTITY' => 0,
            'QUANTITY_TRACE' => 'Y',
            'CAN_BUY_ZERO' => 'N',
            'NEGATIVE_AMOUNT_TRACE' => 'N',
        ];
        $res = CIBlockElement::GetList(
            [],
            ['IBLOCK_ID' => $IBLOCK_ID, '!TMP_ID' => $tmpid],
            false,
            false,
            ['ID']
        );
        while ($arr = $res->Fetch()) {
            if ('D' === $outFileAction) {
                CIBlockElement::Delete($arr['ID'], 'Y', 'N');
                ++$killed_lines;
            } elseif ('F' === $outFileAction) {
            } elseif ($bIBlockIsCatalog && 'M' === $outFileAction) {
                CCatalogProduct::Update($arr['ID'], $arProductArray);
                ++$killed_lines;
            } else { // H
                $bDeactivationStarted = true;
                $el->Update($arr['ID'], ['ACTIVE' => 'N', 'TMP_ID' => $tmpid]);
                ++$killed_lines;
            }

            if (!($bAllLinesLoaded = CSVCheckTimeout($max_execution_time))) {
                break;
            }
        }
    }

    // delete 'not-in-file' element prices
    if ($bAllLinesLoaded && $bIBlockIsCatalog && 'Y' === $bUpdatePrice && 'D' === $outFileAction) {
        $res = CPrice::GetList(
            [],
            ['ELEMENT_IBLOCK_ID' => $IBLOCK_ID, '!TMP_ID' => $tmpid],
            false,
            false,
            ['ID']
        );

        while ($arr = $res->Fetch()) {
            CPrice::Delete($arr['ID']);

            if (!($bAllLinesLoaded = CSVCheckTimeout($max_execution_time))) {
                break;
            }
        }
    }

    if (!$bAllLinesLoaded) {
        $bAllDataLoaded = false;

        $INTERNAL_VARS_LIST = 'tmpid,line_num,correct_lines,error_lines,killed_lines,arIBlockProperty,bThereIsGroups,arProductGroups,arIBlockPropertyValue,bDeactivationStarted,bUpdatePrice';
        $SETUP_VARS_LIST = 'IBLOCK_ID,URL_DATA_FILE,fields_type,first_names_r,delimiter_r,delimiter_other_r,first_names_f,metki_f,PATH2IMAGE_FILES,outFileAction,inFileAction,max_execution_time,IMAGE_RESIZE,USE_TRANSLIT,TRANSLIT_LANG,CLEAR_EMPTY_PRICE,CML2_LINK_IS_XML';
        for ($i = 0; $i < $NUM_FIELDS; ++$i) {
            $SETUP_VARS_LIST .= ',field_'.$i;
        }
        $CUR_FILE_POS = $csvFile->GetPos();
    }
}

if ($bTmpUserCreated) {
    unset($USER);
    if (isset($USER_TMP)) {
        $USER = $USER_TMP;
        unset($USER_TMP);
    }
}
