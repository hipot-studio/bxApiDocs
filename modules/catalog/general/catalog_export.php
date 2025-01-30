<?php

class catalog_export
{
    public static function CheckFields($ACTION, &$arFields)
    {
        global $DB;
        global $USER;

        $ACTION = mb_strtoupper($ACTION);
        if ('UPDATE' !== $ACTION && 'ADD' !== $ACTION) {
            return false;
        }

        if ((is_set($arFields, 'FILE_NAME') || 'ADD' === $ACTION) && '' === $arFields['FILE_NAME']) {
            return false;
        }
        if ((is_set($arFields, 'NAME') || 'ADD' === $ACTION) && '' === $arFields['NAME']) {
            return false;
        }

        if ((is_set($arFields, 'IN_MENU') || 'ADD' === $ACTION) && 'Y' !== $arFields['IN_MENU']) {
            $arFields['IN_MENU'] = 'N';
        }
        if ((is_set($arFields, 'DEFAULT_PROFILE') || 'ADD' === $ACTION) && 'Y' !== $arFields['DEFAULT_PROFILE']) {
            $arFields['DEFAULT_PROFILE'] = 'N';
        }
        if ((is_set($arFields, 'IN_AGENT') || 'ADD' === $ACTION) && 'Y' !== $arFields['IN_AGENT']) {
            $arFields['IN_AGENT'] = 'N';
        }
        if ((is_set($arFields, 'IN_CRON') || 'ADD' === $ACTION) && 'Y' !== $arFields['IN_CRON']) {
            $arFields['IN_CRON'] = 'N';
        }
        if ((is_set($arFields, 'NEED_EDIT') || 'ADD' === $ACTION) && 'Y' !== $arFields['NEED_EDIT']) {
            $arFields['NEED_EDIT'] = 'N';
        }

        $arFields['IS_EXPORT'] = 'Y';

        $intUserID = 0;
        $boolUserExist = CCatalog::IsUserExists();
        if ($boolUserExist) {
            $intUserID = (int) $USER->GetID();
        }
        $strDateFunction = $DB->GetNowFunction();
        $boolNoUpdate = false;
        if (isset($arFields['=LAST_USE']) && $strDateFunction === $arFields['=LAST_USE']) {
            $arFields['~LAST_USE'] = $strDateFunction;
            $boolNoUpdate = ('UPDATE' === $ACTION);
        }
        foreach ($arFields as $key => $value) {
            if (0 === strncmp($key, '=', 1)) {
                unset($arFields[$key]);
            }
        }

        if (array_key_exists('TIMESTAMP_X', $arFields)) {
            unset($arFields['TIMESTAMP_X']);
        }
        if (array_key_exists('DATE_CREATE', $arFields)) {
            unset($arFields['DATE_CREATE']);
        }

        if ('ADD' === $ACTION) {
            $arFields['~TIMESTAMP_X'] = $strDateFunction;
            $arFields['~DATE_CREATE'] = $strDateFunction;
            if ($boolUserExist) {
                if (!array_key_exists('CREATED_BY', $arFields) || (int) $arFields['CREATED_BY'] <= 0) {
                    $arFields['CREATED_BY'] = $intUserID;
                }
                if (!array_key_exists('MODIFIED_BY', $arFields) || (int) $arFields['MODIFIED_BY'] <= 0) {
                    $arFields['MODIFIED_BY'] = $intUserID;
                }
            }
        }
        if ('UPDATE' === $ACTION) {
            if (array_key_exists('CREATED_BY', $arFields)) {
                unset($arFields['CREATED_BY']);
            }
            if ($boolNoUpdate) {
                if (array_key_exists('MODIFIED_BY', $arFields)) {
                    unset($arFields['MODIFIED_BY']);
                }
            } else {
                if ($boolUserExist) {
                    if (!array_key_exists('MODIFIED_BY', $arFields) || (int) $arFields['MODIFIED_BY'] <= 0) {
                        $arFields['MODIFIED_BY'] = $intUserID;
                    }
                }
                $arFields['~TIMESTAMP_X'] = $strDateFunction;
            }
        }

        return true;
    }

    public static function Delete($ID)
    {
        global $DB;

        $ID = (int) $ID;
        if ($ID <= 0) {
            return false;
        }

        return $DB->Query('DELETE FROM b_catalog_export WHERE ID = '.$ID." AND IS_EXPORT = 'Y'", true);
    }

    public static function GetList($arOrder = ['ID' => 'ASC'], $arFilter = [], $bCount = false)
    {
        global $DB;
        $arSqlSearch = [];

        if (!is_array($arFilter)) {
            $filter_keys = [];
        } else {
            $filter_keys = array_keys($arFilter);
        }

        for ($i = 0, $intCount = count($filter_keys); $i < $intCount; ++$i) {
            $val = $DB->ForSql($arFilter[$filter_keys[$i]]);
            if ('' === $val) {
                continue;
            }

            $bInvert = false;
            $key = $filter_keys[$i];
            if ('!' === mb_substr($key, 0, 1)) {
                $key = mb_substr($key, 1);
                $bInvert = true;
            }

            switch (mb_strtoupper($key)) {
                case 'ID':
                    $arSqlSearch[] = 'CE.ID '.($bInvert ? '<>' : '=').' '.(int) $val.'';

                    break;

                case 'FILE_NAME':
                    $arSqlSearch[] = 'CE.FILE_NAME '.($bInvert ? '<>' : '=')." '".$val."'";

                    break;

                case 'NAME':
                    $arSqlSearch[] = 'CE.NAME '.($bInvert ? '<>' : '=')." '".$val."'";

                    break;

                case 'DEFAULT_PROFILE':
                    $arSqlSearch[] = 'CE.DEFAULT_PROFILE '.($bInvert ? '<>' : '=')." '".$val."'";

                    break;

                case 'IN_MENU':
                    $arSqlSearch[] = 'CE.IN_MENU '.($bInvert ? '<>' : '=')." '".$val."'";

                    break;

                case 'IN_AGENT':
                    $arSqlSearch[] = 'CE.IN_AGENT '.($bInvert ? '<>' : '=')." '".$val."'";

                    break;

                case 'IN_CRON':
                    $arSqlSearch[] = 'CE.IN_CRON '.($bInvert ? '<>' : '=')." '".$val."'";

                    break;

                case 'NEED_EDIT':
                    $arSqlSearch[] = 'CE.NEED_EDIT '.($bInvert ? '<>' : '=')." '".$val."'";

                    break;

                case 'CREATED_BY':
                    $arSqlSearch[] = 'CE.CREATED_BY '.($bInvert ? '<>' : '=')." '".(int) $val."'";

                    break;

                case 'MODIFIED_BY':
                    $arSqlSearch[] = 'CE.MODIFIED_BY '.($bInvert ? '<>' : '=')." '".(int) $val."'";

                    break;
            }
        }

        $strSqlSearch = '';
        if (!empty($arSqlSearch)) {
            $strSqlSearch = ' AND ('.implode(') AND (', $arSqlSearch).') ';
        }

        $strSqlSelect =
            'SELECT CE.ID, CE.FILE_NAME, CE.NAME, CE.IN_MENU, CE.IN_AGENT, '.
            '	CE.IN_CRON, CE.SETUP_VARS, CE.DEFAULT_PROFILE, CE.LAST_USE, CE.NEED_EDIT, '.
            '	'.$DB->DateToCharFunction('CE.LAST_USE', 'FULL').' as LAST_USE_FORMAT, '.
            ' CE.CREATED_BY, CE.MODIFIED_BY, '.$DB->DateToCharFunction('CE.TIMESTAMP_X', 'FULL').' as TIMESTAMP_X, '.$DB->DateToCharFunction('CE.DATE_CREATE', 'FULL').' as DATE_CREATE ';

        $strSqlFrom =
            'FROM b_catalog_export CE ';

        if ($bCount) {
            $strSql =
                'SELECT COUNT(CE.ID) as CNT '.
                $strSqlFrom.
                "WHERE CE.IS_EXPORT = 'Y' ".
                $strSqlSearch;
            $db_res = $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
            $iCnt = 0;
            if ($ar_res = $db_res->Fetch()) {
                $iCnt = (int) $ar_res['CNT'];
            }

            return $iCnt;
        }

        $strSql =
            $strSqlSelect.
            $strSqlFrom.
            "WHERE CE.IS_EXPORT = 'Y' ".
            $strSqlSearch;

        $arSqlOrder = [];
        $arOrderKeys = [];
        foreach ($arOrder as $by => $order) {
            $by = mb_strtoupper($by);
            $order = mb_strtoupper($order);
            if ('ASC' !== $order) {
                $order = 'DESC';
            }
            if (!in_array($by, $arOrderKeys, true)) {
                if ('NAME' === $by) {
                    $arSqlOrder[] = 'CE.NAME '.$order;
                } elseif ('FILE_NAME' === $by) {
                    $arSqlOrder[] = 'CE.FILE_NAME '.$order;
                } elseif ('DEFAULT_PROFILE' === $by) {
                    $arSqlOrder[] = 'CE.DEFAULT_PROFILE '.$order;
                } elseif ('IN_MENU' === $by) {
                    $arSqlOrder[] = 'CE.IN_MENU '.$order;
                } elseif ('LAST_USE' === $by) {
                    $arSqlOrder[] = 'CE.LAST_USE '.$order;
                } elseif ('IN_AGENT' === $by) {
                    $arSqlOrder[] = 'CE.IN_AGENT '.$order;
                } elseif ('IN_CRON' === $by) {
                    $arSqlOrder[] = 'CE.IN_CRON '.$order;
                } elseif ('NEED_EDIT' === $by) {
                    $arSqlOrder[] = 'CE.NEED_EDIT '.$order;
                } else {
                    $by = 'ID';
                    if (in_array($by, $arOrderKeys, true)) {
                        continue;
                    }
                    $arSqlOrder[] = 'CE.ID '.$order;
                }
                $arOrderKeys[] = $by;
            }
        }

        $strSqlOrder = '';
        if (!empty($arSqlOrder)) {
            $strSqlOrder = ' ORDER BY '.implode(', ', $arSqlOrder);
        }

        $strSql .= $strSqlOrder;

        $db_res = $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);

        return $db_res;
    }

    public static function GetByID($ID)
    {
        global $DB;

        $strSql =
            'SELECT CE.ID, CE.FILE_NAME, CE.NAME, CE.IN_MENU, CE.IN_AGENT, '.
            '	CE.IN_CRON, CE.SETUP_VARS, CE.DEFAULT_PROFILE, CE.LAST_USE, CE.NEED_EDIT, '.
            '	'.$DB->DateToCharFunction('CE.LAST_USE', 'FULL').' as LAST_USE_FORMAT, '.
            ' CE.CREATED_BY, CE.MODIFIED_BY, '.$DB->DateToCharFunction('CE.TIMESTAMP_X', 'FULL').' as TIMESTAMP_X, '.$DB->DateToCharFunction('CE.DATE_CREATE', 'FULL').' as DATE_CREATE '.
            'FROM b_catalog_export CE '.
            'WHERE CE.ID = '.(int) $ID.' '.
            "	AND CE.IS_EXPORT = 'Y' ";
        $db_res = $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);

        if ($res = $db_res->Fetch()) {
            return $res;
        }

        return false;
    }

    public static function PreGenerateExport($profile_id)
    {
        global $DB;

        $profile_id = (int) $profile_id;
        if ($profile_id <= 0) {
            return false;
        }

        $ar_profile = CCatalogExport::GetByID($profile_id);
        if ((!$ar_profile) || ('Y' === $ar_profile['NEED_EDIT'])) {
            return false;
        }

        $strFile = CATALOG_PATH2EXPORTS.$ar_profile['FILE_NAME'].'_run.php';
        if (!file_exists($_SERVER['DOCUMENT_ROOT'].$strFile)) {
            $strFile = CATALOG_PATH2EXPORTS_DEF.$ar_profile['FILE_NAME'].'_run.php';
            if (!file_exists($_SERVER['DOCUMENT_ROOT'].$strFile)) {
                return false;
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

        if (!defined('CATALOG_EXPORT_NO_STEP')) {
            define('CATALOG_EXPORT_NO_STEP', true);
        }
        $firstStep = true;
        $finalExport = true;
        $CUR_ELEMENT_ID = 0;

        CCatalogDiscountSave::Disable();

        include $_SERVER['DOCUMENT_ROOT'].$strFile;
        CCatalogDiscountSave::Enable();

        CCatalogExport::Update(
            $profile_id,
            [
                '=LAST_USE' => $DB->GetNowFunction(),
            ]
        );

        return 'CCatalogExport::PreGenerateExport('.$profile_id.');';
    }
}
