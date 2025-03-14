<?php

// <title>Froogle</title>
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

function PrepareString($str, $KillTags = false)
{
    if ($KillTags) {
        $str = strip_tags($str);
    }

    return str_replace("\r", '', str_replace("\n", '', str_replace("\t", ' ', $str)));
}

$strExportErrorMessage = '';

if (CModule::IncludeModule('iblock') && CModule::IncludeModule('catalog')) {
    $IBLOCK_ID = (int) $IBLOCK_ID;
    $db_iblock = CIBlock::GetByID($IBLOCK_ID);
    if ($IBLOCK_ID <= 0 || (!($ar_iblock = $db_iblock->Fetch()))) {
        $strExportErrorMessage .= 'Information block #'.$IBLOCK_ID." does not exist.\n";
    }
    /*	elseif (!CIBlockRights::UserHasRightTo($IBLOCK_ID, $IBLOCK_ID, 'iblock_admin_display'))
        {
            $strCSVError .= str_replace('#IBLOCK_ID#',$IBLOCK_ID,GetMessage('CET_ERROR_IBLOCK_PERM')).'<br>';
        } */

    if ('' === $strExportErrorMessage) {
        $bAllSections = false;
        $arSections = [];
        if (!empty($V) && is_array($V)) {
            foreach ($V as $key => $value) {
                if ('0' === trim($value)) {
                    $bAllSections = true;

                    break;
                }
                if ((int) $value > 0) {
                    $arSections[] = (int) $value;
                }
            }
        }

        if (!$bAllSections && empty($arSections)) {
            $strExportErrorMessage .= "Section list is not set.\n";
        }
    }

    if ('' === $strExportErrorMessage) {
        $arFilter = ['IBLOCK_ID' => $IBLOCK_ID, 'ACTIVE_DATE' => 'Y', 'ACTIVE' => 'Y', 'CHECK_PERMISSIONS' => 'N'];
        if (!$bAllSections) {
            $arFilter['INCLUDE_SUBSECTIONS'] = 'Y';
            $arFilter['SECTION_ID'] = $arSections;
        }

        $arSelect = ['ID', 'IBLOCK_ID', 'IBLOCK_SECTION_ID', 'NAME', 'PREVIEW_PICTURE', 'PREVIEW_TEXT', 'PREVIEW_TEXT_TYPE', 'DETAIL_PICTURE', 'LANG_DIR', 'DETAIL_PAGE_URL', 'EXTERNAL_ID'];
        $db_res = CCatalogGroup::GetGroupsList(['GROUP_ID' => 2]);
        $arPTypes = [];
        while ($ar_res = $db_res->Fetch()) {
            if (!in_array($ar_res['CATALOG_GROUP_ID'], $arPTypes, true)) {
                $arPTypes[] = $ar_res['CATALOG_GROUP_ID'];
                $arSelect[] = 'CATALOG_GROUP_'.$ar_res['CATALOG_GROUP_ID'];
            }
        }

        $arSectionPaths = [];
    }

    if ('' === $SETUP_FILE_NAME) {
        $strExportErrorMessage .= GetMessage('CATI_NO_SAVE_FILE').'<br>';
    } elseif (preg_match(BX_CATALOG_FILENAME_REG, $SETUP_FILE_NAME)) {
        $strExportErrorMessage .= GetMessage('CES_ERROR_BAD_EXPORT_FILENAME').'<br>';
    }

    if ('' === $strExportErrorMessage) {
        $SETUP_FILE_NAME = Rel2Abs('/', $SETUP_FILE_NAME);
        if ('.txt' !== strtolower(substr($SETUP_FILE_NAME, strlen($SETUP_FILE_NAME) - 4))) {
            $SETUP_FILE_NAME .= '.txt';
        }
        /*		if ($GLOBALS["APPLICATION"]->GetFileAccessPermission($SETUP_FILE_NAME) < "W")
                    $strExportErrorMessage .= str_replace("#FILE#", $SETUP_FILE_NAME, "You do not have access rights to add or modify #FILE#")."<br>"; */
    }

    if ('' === $strExportErrorMessage) {
        if (!$fp = @fopen($_SERVER['DOCUMENT_ROOT'].$SETUP_FILE_NAME, 'w')) {
            $strExportErrorMessage .= 'Can not open "'.$_SERVER['DOCUMENT_ROOT'].$SETUP_FILE_NAME."\" file for writing.\n";
        } else {
            if (!@fwrite($fp, "product_url	name	description	image_url	category	price\n")) {
                $strExportErrorMessage .= 'Can not write in "'.$_SERVER['DOCUMENT_ROOT'].$SETUP_FILE_NAME."\" file.\n";
                @fclose($fp);
            }
        }
    }

    if ('' === $strExportErrorMessage) {
        if (!($ar_usd_cur = CCurrency::GetByID('USD'))) {
            $strExportErrorMessage .= "USD currency is not found.\n";
        }
    }

    if ('' === $strExportErrorMessage) {
        $db_elems = CIBlockElement::GetList([], $arFilter, false, false, $arSelect);
        while ($ar_elems = $db_elems->GetNext()) {
            $ar_file = CFile::GetFileArray($ar_elems['DETAIL_PICTURE']);
            if (!$ar_file) {
                $ar_file = CFile::GetFileArray($ar_elems['PREVIEW_PICTURE']);
            }

            if ($ar_file) {
                if ('/' === substr($ar_file['SRC'], 0, 1)) {
                    $strImage = 'http://'.COption::GetOptionString('main', 'server_name', $SERVER_NAME).$ar_file['SRC'];
                } else {
                    $strImage = $ar_file['SRC'];
                }
            } else {
                $strImage = '';
            }

            if (!is_set($arSectionPaths, (int) $ar_elems['IBLOCK_SECTION_ID'])) {
                $strCategory = $ar_iblock['NAME'];
                $sections_path = GetIBlockSectionPath($IBLOCK_ID, $ar_elems['IBLOCK_SECTION_ID']);
                while ($arSection = $sections_path->GetNext()) {
                    if ('' !== $strCategory) {
                        $strCategory .= '>';
                    }
                    $strCategory .= $arSection['NAME'];
                }
                $arSectionPaths[(int) $ar_elems['IBLOCK_SECTION_ID']] = PrepareString($strCategory);
            }

            $minPrice = 0;
            for ($i = 0, $intPCount = count($arPTypes); $i < $intPCount; ++$i) {
                if ('' === $ar_elems['CATALOG_CURRENCY_'.$arPTypes[$i]]) {
                    continue;
                }
                $tmpPrice = round(CCurrencyRates::ConvertCurrency($ar_elems['CATALOG_PRICE_'.$arPTypes[$i]], $ar_elems['CATALOG_CURRENCY_'.$arPTypes[$i]], 'USD'), 2);
                if ($minPrice <= 0 || $minPrice > $tmpPrice) {
                    $minPrice = $tmpPrice;
                }
            }

            if ($minPrice <= 0) {
                continue;
            }

            @fwrite($fp, 'http://'.
                COption::GetOptionString('main', 'server_name', $SERVER_NAME).
                str_replace('//', '/', $ar_elems['DETAIL_PAGE_URL']).
                '	'.
                $ar_elems['~NAME'].
                '	'.
                PrepareString($ar_elems['~PREVIEW_TEXT'], true).
                '	'.
                $strImage.
                '	'.
                $arSectionPaths[(int) $ar_elems['IBLOCK_SECTION_ID']].
                '	'.
                $minPrice."\n");
        }
        @fclose($fp);
    }
}

CCatalogDiscountSave::Enable();

if ($bTmpUserCreated) {
    unset($USER);
    if (isset($USER_TMP)) {
        $USER = $USER_TMP;
        unset($USER_TMP);
    }
}
