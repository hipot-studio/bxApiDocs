<?php

use Bitrix\Catalog;

require_once $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/catalog/general/cataloggroup.php';

class cataloggroup extends CAllCatalogGroup
{
    public static function GetByID($ID, $lang = LANGUAGE_ID)
    {
        $ID = (int) $ID;
        if ($ID <= 0) {
            return false;
        }

        global $DB, $USER;

        $strUserGroups = (CCatalog::IsUserExists() ? $USER->GetGroups() : '2');

        $strSql =
            'SELECT CG.ID, CG.NAME, CG.BASE, CG.SORT, CG.XML_ID, '.
            'CG.CREATED_BY, CG.MODIFIED_BY, '.$DB->DateToCharFunction('CG.TIMESTAMP_X', 'FULL').' as TIMESTAMP_X, '.$DB->DateToCharFunction('CG.DATE_CREATE', 'FULL').' as DATE_CREATE, '.
            "CGL.NAME as NAME_LANG, IF(CGG.ID IS NULL, 'N', 'Y') as CAN_ACCESS,  IF(CGG1.ID IS NULL, 'N', 'Y') as CAN_BUY ".
            'FROM b_catalog_group CG '.
            '	LEFT JOIN b_catalog_group2group CGG ON (CG.ID = CGG.CATALOG_GROUP_ID AND CGG.GROUP_ID IN ('.$strUserGroups.") AND CGG.BUY <> 'Y') ".
            '	LEFT JOIN b_catalog_group2group CGG1 ON (CG.ID = CGG1.CATALOG_GROUP_ID AND CGG1.GROUP_ID IN ('.$strUserGroups.") AND CGG1.BUY = 'Y') ".
            "	LEFT JOIN b_catalog_group_lang CGL ON (CG.ID = CGL.CATALOG_GROUP_ID AND CGL.LANG = '".$DB->ForSql($lang)."') ".
            'WHERE CG.ID = '.$ID.' GROUP BY CG.ID, CG.NAME, CG.BASE, CG.SORT, CG.XML_ID, CG.CREATED_BY, CG.MODIFIED_BY, CG.TIMESTAMP_X, CG.DATE_CREATE, '.
            "CGL.NAME, IF(CGG.ID IS NULL, 'N', 'Y'),  IF(CGG1.ID IS NULL, 'N', 'Y')";

        $db_res = $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
        if ($res = $db_res->Fetch()) {
            return $res;
        }

        return false;
    }

    public static function Add($arFields)
    {
        global $DB, $CACHE_MANAGER;

        foreach (GetModuleEvents('catalog', 'OnBeforeGroupAdd', true) as $arEvent) {
            if (false === ExecuteModuleEventEx($arEvent, [&$arFields])) {
                return false;
            }
        }

        if (!static::CheckFields('ADD', $arFields, 0)) {
            return false;
        }

        if ('Y' === $arFields['BASE']) {
            self::clearBaseGroupFlag(null, $arFields);
        }

        $arInsert = $DB->PrepareInsert('b_catalog_group', $arFields);

        $strSql = 'INSERT INTO b_catalog_group('.$arInsert[0].') VALUES('.$arInsert[1].')';
        $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);

        $groupID = (int) $DB->LastID();

        foreach ($arFields['USER_GROUP'] as &$intValue) {
            $strSql = 'INSERT INTO b_catalog_group2group(CATALOG_GROUP_ID, GROUP_ID, BUY) VALUES('.$groupID.', '.$intValue.", 'N')";
            $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
        }
        if (isset($intValue)) {
            unset($intValue);
        }

        foreach ($arFields['USER_GROUP_BUY'] as &$intValue) {
            $strSql = 'INSERT INTO b_catalog_group2group(CATALOG_GROUP_ID, GROUP_ID, BUY) VALUES('.$groupID.', '.$intValue.", 'Y')";
            $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
        }
        if (isset($intValue)) {
            unset($intValue);
        }

        if (isset($arFields['USER_LANG']) && is_array($arFields['USER_LANG']) && !empty($arFields['USER_LANG'])) {
            foreach ($arFields['USER_LANG'] as $key => $value) {
                $strSql =
                    'INSERT INTO b_catalog_group_lang(CATALOG_GROUP_ID, LANG, NAME) VALUES('.$groupID.", '".$DB->ForSql($key)."', '".$DB->ForSql($value)."')";
                $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
            }
        }

        if (!defined('CATALOG_SKIP_CACHE') || !CATALOG_SKIP_CACHE) {
            $CACHE_MANAGER->CleanDir('catalog_group');
            $CACHE_MANAGER->Clean('catalog_group_perms');
        }

        Catalog\GroupTable::cleanCache();
        Catalog\Model\Price::clearSettings();

        foreach (GetModuleEvents('catalog', 'OnGroupAdd', true) as $arEvent) {
            ExecuteModuleEventEx($arEvent, [$groupID, $arFields]);
        }
        // strange copy-paste bug
        foreach (GetModuleEvents('catalog', 'OnGroupUpdate', true) as $arEvent) {
            ExecuteModuleEventEx($arEvent, [$groupID, $arFields]);
        }

        return $groupID;
    }

    public static function Update($ID, $arFields)
    {
        global $DB, $CACHE_MANAGER;

        $ID = (int) $ID;
        if ($ID <= 0) {
            return false;
        }

        foreach (GetModuleEvents('catalog', 'OnBeforeGroupUpdate', true) as $arEvent) {
            if (false === ExecuteModuleEventEx($arEvent, [$ID, &$arFields])) {
                return false;
            }
        }

        if (!static::CheckFields('UPDATE', $arFields, $ID)) {
            return false;
        }

        $strUpdate = $DB->PrepareUpdate('b_catalog_group', $arFields);
        if (!empty($strUpdate)) {
            if (isset($arFields['BASE']) && 'Y' === $arFields['BASE']) {
                self::clearBaseGroupFlag($ID, $arFields);
            }

            $strSql = 'UPDATE b_catalog_group SET '.$strUpdate.' WHERE ID = '.$ID;
            $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
        }

        if (isset($arFields['USER_GROUP']) && is_array($arFields['USER_GROUP']) && !empty($arFields['USER_GROUP'])) {
            $DB->Query('DELETE FROM b_catalog_group2group WHERE CATALOG_GROUP_ID = '.$ID." AND BUY <> 'Y'");
            foreach ($arFields['USER_GROUP'] as &$intValue) {
                $strSql = 'INSERT INTO b_catalog_group2group(CATALOG_GROUP_ID, GROUP_ID, BUY) VALUES('.$ID.', '.$intValue.", 'N')";
                $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
            }
            if (isset($intValue)) {
                unset($intValue);
            }
        }

        if (isset($arFields['USER_GROUP_BUY']) && is_array($arFields['USER_GROUP_BUY']) && !empty($arFields['USER_GROUP_BUY'])) {
            $DB->Query('DELETE FROM b_catalog_group2group WHERE CATALOG_GROUP_ID = '.$ID." AND BUY = 'Y'");
            foreach ($arFields['USER_GROUP_BUY'] as &$intValue) {
                $strSql = 'INSERT INTO b_catalog_group2group(CATALOG_GROUP_ID, GROUP_ID, BUY) VALUES('.$ID.', '.$intValue.", 'Y')";
                $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
            }
            if (isset($intValue)) {
                unset($intValue);
            }
        }

        if (isset($arFields['USER_LANG']) && is_array($arFields['USER_LANG']) && !empty($arFields['USER_LANG'])) {
            $DB->Query('DELETE FROM b_catalog_group_lang WHERE CATALOG_GROUP_ID = '.$ID);
            foreach ($arFields['USER_LANG'] as $key => $value) {
                $strSql =
                    'INSERT INTO b_catalog_group_lang(CATALOG_GROUP_ID, LANG, NAME) VALUES('.$ID.", '".$DB->ForSql($key)."', '".$DB->ForSql($value)."')";
                $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
            }
        }

        if (!defined('CATALOG_SKIP_CACHE') || !CATALOG_SKIP_CACHE) {
            $CACHE_MANAGER->CleanDir('catalog_group');
            $CACHE_MANAGER->Clean('catalog_group_perms');
        }

        Catalog\GroupTable::cleanCache();
        Catalog\Model\Price::clearSettings();

        foreach (GetModuleEvents('catalog', 'OnGroupUpdate', true) as $arEvent) {
            ExecuteModuleEventEx($arEvent, [$ID, $arFields]);
        }

        return true;
    }

    public static function Delete($ID)
    {
        global $DB, $CACHE_MANAGER, $APPLICATION;

        $ID = (int) $ID;
        if ($ID <= 0) {
            return false;
        }

        if ($res = static::GetByID($ID)) {
            if ('Y' !== $res['BASE']) {
                foreach (GetModuleEvents('catalog', 'OnBeforeGroupDelete', true) as $arEvent) {
                    if (false === ExecuteModuleEventEx($arEvent, [$ID])) {
                        return false;
                    }
                }

                foreach (GetModuleEvents('catalog', 'OnGroupDelete', true) as $arEvent) {
                    ExecuteModuleEventEx($arEvent, [$ID]);
                }

                if (!defined('CATALOG_SKIP_CACHE') || !CATALOG_SKIP_CACHE) {
                    $CACHE_MANAGER->CleanDir('catalog_group');
                    $CACHE_MANAGER->Clean('catalog_group_perms');
                }

                $DB->Query('DELETE FROM b_catalog_price WHERE CATALOG_GROUP_ID = '.$ID);
                $DB->Query('DELETE FROM b_catalog_group2group WHERE CATALOG_GROUP_ID = '.$ID);
                $DB->Query('DELETE FROM b_catalog_group_lang WHERE CATALOG_GROUP_ID = '.$ID);
                Catalog\RoundingTable::deleteByPriceType($ID);
                Catalog\GroupTable::cleanCache();
                Catalog\Model\Price::clearSettings();

                return $DB->Query('DELETE FROM b_catalog_group WHERE ID = '.$ID, true);
            }

            $APPLICATION->ThrowException(GetMessage('BT_MOD_CAT_GROUP_ERR_CANNOT_DELETE_BASE_TYPE'), 'BASE');
        }

        return false;
    }

    /**
     * @param array      $arOrder
     * @param array      $arFilter
     * @param array|bool $arGroupBy
     * @param array|bool $arNavStartParams
     * @param array      $arSelectFields
     *
     * @return bool|CDBResult
     */
    public static function GetList($arOrder = [], $arFilter = [], $arGroupBy = false, $arNavStartParams = false, $arSelectFields = [])
    {
        global $DB, $USER;

        // for old-style execution
        if (!is_array($arOrder) && !is_array($arFilter)) {
            $arOrder = (string) $arOrder;
            $arFilter = (string) $arFilter;
            if ('' !== $arOrder && '' !== $arFilter) {
                $arOrder = [$arOrder => $arFilter];
            } else {
                $arOrder = [];
            }
            if (is_array($arGroupBy)) {
                $arFilter = $arGroupBy;
            } else {
                $arFilter = [];
            }
            $arGroupBy = false;
            if (false !== $arNavStartParams && '' !== $arNavStartParams) {
                $arFilter['LID'] = $arNavStartParams;
            } else {
                $arFilter['LID'] = LANGUAGE_ID;
            }
        }
        if (!isset($arFilter['LID'])) {
            $arFilter['LID'] = LANGUAGE_ID;
        }

        $strUserGroups = (CCatalog::IsUserExists() ? $USER->GetGroups() : '2');

        if (empty($arSelectFields)) {
            $arSelectFields = ['ID', 'NAME', 'BASE', 'SORT', 'XML_ID', 'MODIFIED_BY', 'CREATED_BY', 'DATE_CREATE', 'TIMESTAMP_X', 'NAME_LANG', 'CAN_ACCESS', 'CAN_BUY'];
        }
        if (false === $arGroupBy) {
            $arGroupBy = ['ID', 'NAME', 'BASE', 'SORT', 'XML_ID', 'MODIFIED_BY', 'CREATED_BY', 'DATE_CREATE', 'TIMESTAMP_X', 'NAME_LANG', 'CAN_ACCESS', 'CAN_BUY'];
        }

        $arFields = [
            'ID' => ['FIELD' => 'CG.ID', 'TYPE' => 'int'],
            'NAME' => ['FIELD' => 'CG.NAME', 'TYPE' => 'string'],
            'BASE' => ['FIELD' => 'CG.BASE', 'TYPE' => 'char'],
            'SORT' => ['FIELD' => 'CG.SORT', 'TYPE' => 'int'],
            'XML_ID' => ['FIELD' => 'CG.XML_ID', 'TYPE' => 'string'],
            'TIMESTAMP_X' => ['FIELD' => 'CG.TIMESTAMP_X', 'TYPE' => 'datetime'],
            'MODIFIED_BY' => ['FIELD' => 'CG.MODIFIED_BY', 'TYPE' => 'int'],
            'DATE_CREATE' => ['FIELD' => 'CG.DATE_CREATE', 'TYPE' => 'datetime'],
            'CREATED_BY' => ['FIELD' => 'CG.CREATED_BY', 'TYPE' => 'int'],
            'NAME_LANG' => ['FIELD' => 'CGL.NAME', 'TYPE' => 'string', 'FROM' => "LEFT JOIN b_catalog_group_lang CGL ON (CG.ID = CGL.CATALOG_GROUP_ID AND CGL.LANG = '".$DB->ForSql($arFilter['LID'], 2)."')"],
        ];

        $arFields['CAN_ACCESS'] = [
            'FIELD' => "IF(CGG.ID IS NULL, 'N', 'Y')",
            'TYPE' => 'char',
            'FROM' => 'LEFT JOIN b_catalog_group2group CGG ON (CG.ID = CGG.CATALOG_GROUP_ID AND CGG.GROUP_ID IN ('.$strUserGroups.") AND CGG.BUY <> 'Y')",
            'GROUPED' => 'N',
        ];
        $arFields['CAN_BUY'] = [
            'FIELD' => "IF(CGG1.ID IS NULL, 'N', 'Y')",
            'TYPE' => 'char',
            'FROM' => 'LEFT JOIN b_catalog_group2group CGG1 ON (CG.ID = CGG1.CATALOG_GROUP_ID AND CGG1.GROUP_ID IN ('.$strUserGroups.") AND CGG1.BUY = 'Y')",
            'GROUPED' => 'N',
        ];

        $arSqls = CCatalog::_PrepareSql($arFields, $arOrder, $arFilter, $arGroupBy, $arSelectFields);

        $arSqls['SELECT'] = str_replace('%%_DISTINCT_%%', '', $arSqls['SELECT']);

        if (empty($arGroupBy) && is_array($arGroupBy)) {
            $strSql = 'SELECT '.$arSqls['SELECT'].' FROM b_catalog_group CG '.$arSqls['FROM'];
            if (!empty($arSqls['WHERE'])) {
                $strSql .= ' WHERE '.$arSqls['WHERE'];
            }
            if (!empty($arSqls['GROUPBY'])) {
                $strSql .= ' GROUP BY '.$arSqls['GROUPBY'];
            }
            if (!empty($arSqls['HAVING'])) {
                $strSql .= ' HAVING '.$arSqls['HAVING'];
            }

            $dbRes = $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
            if ($arRes = $dbRes->Fetch()) {
                return $arRes['CNT'];
            }

            return false;
        }

        $strSql = 'SELECT '.$arSqls['SELECT'].' FROM b_catalog_group CG '.$arSqls['FROM'];
        if (!empty($arSqls['WHERE'])) {
            $strSql .= ' WHERE '.$arSqls['WHERE'];
        }
        if (!empty($arSqls['GROUPBY'])) {
            $strSql .= ' GROUP BY '.$arSqls['GROUPBY'];
        }
        if (!empty($arSqls['HAVING'])) {
            $strSql .= ' HAVING '.$arSqls['HAVING'];
        }
        if (!empty($arSqls['ORDERBY'])) {
            $strSql .= ' ORDER BY '.$arSqls['ORDERBY'];
        }

        $intTopCount = 0;
        $boolNavStartParams = (!empty($arNavStartParams) && is_array($arNavStartParams));
        if ($boolNavStartParams && array_key_exists('nTopCount', $arNavStartParams)) {
            $intTopCount = (int) $arNavStartParams['nTopCount'];
        }
        if ($boolNavStartParams && 0 >= $intTopCount) {
            $strSql_tmp = "SELECT COUNT('x') as CNT FROM b_catalog_group CG ".$arSqls['FROM'];
            if (!empty($arSqls['WHERE'])) {
                $strSql_tmp .= ' WHERE '.$arSqls['WHERE'];
            }
            if (!empty($arSqls['GROUPBY'])) {
                $strSql_tmp .= ' GROUP BY '.$arSqls['GROUPBY'];
            }
            if (!empty($arSqls['HAVING'])) {
                $strSql_tmp .= ' HAVING '.$arSqls['HAVING'];
            }

            $dbRes = $DB->Query($strSql_tmp, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
            $cnt = 0;
            if (empty($arSqls['GROUPBY'])) {
                if ($arRes = $dbRes->Fetch()) {
                    $cnt = $arRes['CNT'];
                }
            } else {
                $cnt = $dbRes->SelectedRowsCount();
            }

            $dbRes = new CDBResult();

            $dbRes->NavQuery($strSql, $cnt, $arNavStartParams);
        } else {
            if ($boolNavStartParams && 0 < $intTopCount) {
                $strSql .= ' LIMIT '.$intTopCount;
            }
            $dbRes = $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
        }

        return $dbRes;
    }

    /**
     * @param array      $arOrder
     * @param array      $arFilter
     * @param array|bool $arGroupBy
     * @param array|bool $arNavStartParams
     * @param array      $arSelectFields
     *
     * @return bool|CDBResult
     */
    public static function GetListEx($arOrder = [], $arFilter = [], $arGroupBy = false, $arNavStartParams = false, $arSelectFields = [])
    {
        global $DB;

        if (empty($arSelectFields)) {
            $arSelectFields = ['ID', 'NAME', 'BASE', 'SORT', 'NAME_LANG', 'XML_ID', 'MODIFIED_BY', 'CREATED_BY', 'DATE_CREATE', 'TIMESTAMP_X'];
        }

        $arFields = [
            'ID' => ['FIELD' => 'CG.ID', 'TYPE' => 'int'],
            'NAME' => ['FIELD' => 'CG.NAME', 'TYPE' => 'string'],
            'BASE' => ['FIELD' => 'CG.BASE', 'TYPE' => 'char'],
            'SORT' => ['FIELD' => 'CG.SORT', 'TYPE' => 'int'],
            'XML_ID' => ['FIELD' => 'CG.XML_ID', 'TYPE' => 'string'],
            'TIMESTAMP_X' => ['FIELD' => 'CG.TIMESTAMP_X', 'TYPE' => 'datetime'],
            'MODIFIED_BY' => ['FIELD' => 'CG.MODIFIED_BY', 'TYPE' => 'int'],
            'DATE_CREATE' => ['FIELD' => 'CG.DATE_CREATE', 'TYPE' => 'datetime'],
            'CREATED_BY' => ['FIELD' => 'CG.CREATED_BY', 'TYPE' => 'int'],

            'GROUP_ID' => ['FIELD' => 'CG2G.ID', 'TYPE' => 'int', 'FROM' => 'INNER JOIN b_catalog_group2group CG2G ON (CG.ID = CG2G.CATALOG_GROUP_ID)'],
            'GROUP_CATALOG_GROUP_ID' => ['FIELD' => 'CG2G.CATALOG_GROUP_ID', 'TYPE' => 'int', 'FROM' => 'INNER JOIN b_catalog_group2group CG2G ON (CG.ID = CG2G.CATALOG_GROUP_ID)'],
            'GROUP_GROUP_ID' => ['FIELD' => 'CG2G.GROUP_ID', 'TYPE' => 'int', 'FROM' => 'INNER JOIN b_catalog_group2group CG2G ON (CG.ID = CG2G.CATALOG_GROUP_ID)'],
            'GROUP_BUY' => ['FIELD' => 'CG2G.BUY', 'TYPE' => 'char', 'FROM' => 'INNER JOIN b_catalog_group2group CG2G ON (CG.ID = CG2G.CATALOG_GROUP_ID)'],

            'NAME_LANG' => ['FIELD' => 'CGL.NAME', 'TYPE' => 'string', 'FROM' => "LEFT JOIN b_catalog_group_lang CGL ON (CG.ID = CGL.CATALOG_GROUP_ID AND CGL.LANG = '".LANGUAGE_ID."')"],
        ];

        $arSqls = CCatalog::PrepareSql($arFields, $arOrder, $arFilter, $arGroupBy, $arSelectFields);

        $arSqls['SELECT'] = str_replace('%%_DISTINCT_%%', '', $arSqls['SELECT']);

        if (empty($arGroupBy) && is_array($arGroupBy)) {
            $strSql = 'SELECT '.$arSqls['SELECT'].' FROM b_catalog_group CG '.$arSqls['FROM'];
            if (!empty($arSqls['WHERE'])) {
                $strSql .= ' WHERE '.$arSqls['WHERE'];
            }
            if (!empty($arSqls['GROUPBY'])) {
                $strSql .= ' GROUP BY '.$arSqls['GROUPBY'];
            }
            if (!empty($arSqls['HAVING'])) {
                $strSql .= ' HAVING '.$arSqls['HAVING'];
            }

            $dbRes = $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
            if ($arRes = $dbRes->Fetch()) {
                return $arRes['CNT'];
            }

            return false;
        }

        $strSql = 'SELECT '.$arSqls['SELECT'].' FROM b_catalog_group CG '.$arSqls['FROM'];
        if (!empty($arSqls['WHERE'])) {
            $strSql .= ' WHERE '.$arSqls['WHERE'];
        }
        if (!empty($arSqls['GROUPBY'])) {
            $strSql .= ' GROUP BY '.$arSqls['GROUPBY'];
        }
        if (!empty($arSqls['HAVING'])) {
            $strSql .= ' HAVING '.$arSqls['HAVING'];
        }
        if (!empty($arSqls['ORDERBY'])) {
            $strSql .= ' ORDER BY '.$arSqls['ORDERBY'];
        }

        $intTopCount = 0;
        $boolNavStartParams = (!empty($arNavStartParams) && is_array($arNavStartParams));
        if ($boolNavStartParams && array_key_exists('nTopCount', $arNavStartParams)) {
            $intTopCount = (int) $arNavStartParams['nTopCount'];
        }
        if ($boolNavStartParams && 0 >= $intTopCount) {
            $strSql_tmp = "SELECT COUNT('x') as CNT FROM b_catalog_group CG ".$arSqls['FROM'];
            if (!empty($arSqls['WHERE'])) {
                $strSql_tmp .= ' WHERE '.$arSqls['WHERE'];
            }
            if (!empty($arSqls['GROUPBY'])) {
                $strSql_tmp .= ' GROUP BY '.$arSqls['GROUPBY'];
            }
            if (!empty($arSqls['HAVING'])) {
                $strSql_tmp .= ' HAVING '.$arSqls['HAVING'];
            }

            $dbRes = $DB->Query($strSql_tmp, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
            $cnt = 0;
            if (empty($arSqls['GROUPBY'])) {
                if ($arRes = $dbRes->Fetch()) {
                    $cnt = $arRes['CNT'];
                }
            } else {
                $cnt = $dbRes->SelectedRowsCount();
            }

            $dbRes = new CDBResult();

            $dbRes->NavQuery($strSql, $cnt, $arNavStartParams);
        } else {
            if ($boolNavStartParams && 0 < $intTopCount) {
                $strSql .= ' LIMIT '.$intTopCount;
            }
            $dbRes = $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
        }

        return $dbRes;
    }

    public static function GetGroupsList($arFilter = [])
    {
        global $DB;

        $arFields = [
            'ID' => ['FIELD' => 'CGG.ID', 'TYPE' => 'int'],
            'CATALOG_GROUP_ID' => ['FIELD' => 'CGG.CATALOG_GROUP_ID', 'TYPE' => 'int'],
            'GROUP_ID' => ['FIELD' => 'CGG.GROUP_ID', 'TYPE' => 'int'],
            'BUY' => ['FIELD' => 'CGG.BUY', 'TYPE' => 'char'],
        ];

        $arSqls = CCatalog::PrepareSql($arFields, [], $arFilter, false, []);

        $arSqls['SELECT'] = str_replace('%%_DISTINCT_%%', '', $arSqls['SELECT']);

        $strSql = 'SELECT '.$arSqls['SELECT'].' FROM b_catalog_group2group CGG '.$arSqls['FROM'];
        if (!empty($arSqls['WHERE'])) {
            $strSql .= ' WHERE '.$arSqls['WHERE'];
        }
        if (!empty($arSqls['GROUPBY'])) {
            $strSql .= ' GROUP BY '.$arSqls['GROUPBY'];
        }
        if (!empty($arSqls['ORDERBY'])) {
            $strSql .= ' ORDER BY '.$arSqls['ORDERBY'];
        }

        return $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
    }

    public static function GetLangList($arFilter = [])
    {
        global $DB;

        $arFields = [
            'ID' => ['FIELD' => 'CGL.ID', 'TYPE' => 'int'],
            'CATALOG_GROUP_ID' => ['FIELD' => 'CGL.CATALOG_GROUP_ID', 'TYPE' => 'int'],
            'LID' => ['FIELD' => 'CGL.LANG', 'TYPE' => 'string'],
            'LANG' => ['FIELD' => 'CGL.LANG', 'TYPE' => 'string'],
            'NAME' => ['FIELD' => 'CGL.NAME', 'TYPE' => 'string'],
        ];

        $arSqls = CCatalog::PrepareSql($arFields, [], $arFilter, false, []);

        $arSqls['SELECT'] = str_replace('%%_DISTINCT_%%', '', $arSqls['SELECT']);

        $strSql = 'SELECT '.$arSqls['SELECT'].' FROM b_catalog_group_lang CGL '.$arSqls['FROM'];
        if (!empty($arSqls['WHERE'])) {
            $strSql .= ' WHERE '.$arSqls['WHERE'];
        }
        if (!empty($arSqls['GROUPBY'])) {
            $strSql .= ' GROUP BY '.$arSqls['GROUPBY'];
        }
        if (!empty($arSqls['ORDERBY'])) {
            $strSql .= ' ORDER BY '.$arSqls['ORDERBY'];
        }

        return $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
    }

    protected static function clearBaseGroupFlag(?int $id, array $fields): void
    {
        global $DB;

        $data = [
            'BASE' => 'N',
            '~TIMESTAMP_X' => $DB->GetNowFunction(),
        ];
        if (isset($fields['MODIFIED_BY'])) {
            $data['MODIFIED_BY'] = $fields['MODIFIED_BY'];
        }

        $parsedData = $DB->PrepareUpdate('b_catalog_group', $data);

        $query = 'UPDATE b_catalog_group SET '.$parsedData.' WHERE ';
        $query .= null !== $id ? 'ID !='.$id.' and BASE = \'Y\'' : 'BASE = \'Y\'';
        $DB->Query($query, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);

        Catalog\GroupTable::cleanCache();
        Catalog\Model\Price::clearSettings();
        self::$arBaseGroupCache = [];
        if (defined('CATALOG_GLOBAL_VARS') && 'Y' === CATALOG_GLOBAL_VARS) {
            // @global array $CATALOG_BASE_GROUP
            global $CATALOG_BASE_GROUP;
            $CATALOG_BASE_GROUP = self::$arBaseGroupCache;
        }
    }
}
