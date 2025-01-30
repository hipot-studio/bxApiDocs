<?php

use Bitrix\Catalog;
use Bitrix\Iblock;
use Bitrix\Main;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

class catalog
{
    protected static $arCatalogCache = [];
    protected static $catalogVatCache = [];

    private static $disableCheckIblock = 0;

    public static function CheckFields($ACTION, &$arFields, $ID = 0)
    {
        global $APPLICATION;

        $arMsg = [];
        $boolResult = true;

        if (!is_array($arFields)) {
            return false;
        }
        if (array_key_exists('OFFERS', $arFields)) {
            unset($arFields['OFFERS']);
        }

        $defaultFields = [
            'YANDEX_EXPORT' => 'N',
            'SUBSCRIPTION' => 'N',
            'VAT_ID' => 0,
            'PRODUCT_IBLOCK_ID' => 0,
            'SKU_PROPERTY_ID' => 0,
        ];

        $ID = (int) $ID;
        $arCatalog = false;
        if (0 < $ID) {
            $arCatalog = CCatalog::GetByID($ID);
        }
        if ($boolResult) {
            if (('UPDATE' === $ACTION) && (false === $arCatalog)) {
                $boolResult = false;
                $arMsg[] = ['id' => 'ID', 'text' => Loc::getMessage('BT_MOD_CATALOG_ERR_UPDATE_BAD_ID')];
            }
        }

        if ($boolResult) {
            if ('ADD' === $ACTION) {
                $arFields = array_merge($defaultFields, $arFields);
            }
            if ('ADD' === $ACTION || is_set($arFields, 'IBLOCK_ID')) {
                if (!is_set($arFields, 'IBLOCK_ID')) {
                    $arMsg[] = ['id' => 'IBLOCK_ID', 'text' => Loc::getMessage('BT_MOD_CATALOG_ERR_IBLOCK_ID_FIELD_ABSENT')];
                    $boolResult = false;
                } elseif ((int) $arFields['IBLOCK_ID'] <= 0) {
                    $arMsg[] = ['id' => 'IBLOCK_ID', 'text' => Loc::getMessage('BT_MOD_CATALOG_ERR_IBLOCK_ID_INVALID')];
                    $boolResult = false;
                } else {
                    $arFields['IBLOCK_ID'] = (int) $arFields['IBLOCK_ID'];
                    $rsIBlocks = CIBlock::GetByID($arFields['IBLOCK_ID']);
                    if (!($arIBlock = $rsIBlocks->Fetch())) {
                        $arMsg[] = ['id' => 'IBLOCK_ID', 'text' => Loc::getMessage('BT_MOD_CATALOG_ERR_IBLOCK_ID_ABSENT')];
                        $boolResult = false;
                    }
                }
            }
            if ((is_set($arFields, 'SUBSCRIPTION') || 'ADD' === $ACTION) && 'Y' !== $arFields['SUBSCRIPTION']) {
                $arFields['SUBSCRIPTION'] = 'N';
            }
            if ((is_set($arFields, 'YANDEX_EXPORT') || 'ADD' === $ACTION) && 'Y' !== $arFields['YANDEX_EXPORT']) {
                $arFields['YANDEX_EXPORT'] = 'N';
            }

            if (is_set($arFields, 'VAT_ID') || ('ADD' === $ACTION)) {
                $arFields['VAT_ID'] = (int) $arFields['VAT_ID'];
                if ($arFields['VAT_ID'] <= 0) {
                    $arFields['VAT_ID'] = 0;
                }
            }
        }

        if ($boolResult) {
            if ('ADD' === $ACTION) {
                if (!is_set($arFields, 'PRODUCT_IBLOCK_ID')) {
                    $arFields['PRODUCT_IBLOCK_ID'] = 0;
                } elseif (0 > (int) $arFields['PRODUCT_IBLOCK_ID']) {
                    $arMsg[] = ['id' => 'PRODUCT_IBLOCK_ID', 'text' => Loc::getMessage('BT_MOD_CATALOG_ERR_PRODUCT_ID_INVALID')];
                    $arFields['PRODUCT_IBLOCK_ID'] = 0;
                    $boolResult = false;
                } elseif (0 < (int) $arFields['PRODUCT_IBLOCK_ID']) {
                    $arFields['PRODUCT_IBLOCK_ID'] = (int) $arFields['PRODUCT_IBLOCK_ID'];
                    $rsIBlocks = CIBlock::GetByID($arFields['PRODUCT_IBLOCK_ID']);
                    if (!($arIBlock = $rsIBlocks->Fetch())) {
                        $arMsg[] = ['id' => 'PRODUCT_IBLOCK_ID', 'text' => Loc::getMessage('BT_MOD_CATALOG_ERR_PRODUCT_ID_ABSENT')];
                        $arFields['PRODUCT_IBLOCK_ID'] = 0;
                        $boolResult = false;
                    } else {
                        if ($arFields['PRODUCT_IBLOCK_ID'] === $arFields['IBLOCK_ID']) {
                            $arMsg[] = ['id' => 'PRODUCT_IBLOCK_ID', 'text' => Loc::getMessage('BT_MOD_CATALOG_ERR_PRODUCT_ID_SELF')];
                            $arFields['PRODUCT_IBLOCK_ID'] = 0;
                            $boolResult = false;
                        }
                    }
                } else {
                    $arFields['PRODUCT_IBLOCK_ID'] = 0;
                }

                if (!is_set($arFields, 'SKU_PROPERTY_ID')) {
                    $arFields['SKU_PROPERTY_ID'] = 0;
                } elseif (0 > (int) $arFields['SKU_PROPERTY_ID']) {
                    $arMsg[] = ['id' => 'SKU_PROPERTY_ID', 'text' => Loc::getMessage('BT_MOD_CATALOG_ERR_SKU_PROP_ID_INVALID')];
                    $arFields['SKU_PROPERTY_ID'] = 0;
                    $boolResult = false;
                } else {
                    $arFields['SKU_PROPERTY_ID'] = (int) $arFields['SKU_PROPERTY_ID'];
                }

                if ((0 < $arFields['PRODUCT_IBLOCK_ID']) && (0 === $arFields['SKU_PROPERTY_ID'])) {
                    $arMsg[] = ['id' => 'SKU_PROPERTY_ID', 'text' => Loc::getMessage('BT_MOD_CATALOG_ERR_PRODUCT_WITHOUT_SKU_PROP')];
                    $boolResult = false;
                } elseif ((0 === $arFields['PRODUCT_IBLOCK_ID']) && (0 < $arFields['SKU_PROPERTY_ID'])) {
                    $arMsg[] = ['id' => 'PRODUCT_IBLOCK_ID', 'text' => Loc::getMessage('BT_MOD_CATALOG_ERR_SKU_PROP_WITHOUT_PRODUCT')];
                    $boolResult = false;
                } elseif ((0 < $arFields['PRODUCT_IBLOCK_ID']) && (0 < $arFields['SKU_PROPERTY_ID'])) {
                    $rsProps = CIBlockProperty::GetList([], ['IBLOCK_ID' => $arFields['IBLOCK_ID'], 'ID' => $arFields['SKU_PROPERTY_ID'], 'ACTIVE' => 'Y']);
                    if ($arProp = $rsProps->Fetch()) {
                        if (('E' !== $arProp['PROPERTY_TYPE']) || ($arFields['PRODUCT_IBLOCK_ID'] !== $arProp['LINK_IBLOCK_ID'])) {
                            $arMsg[] = ['id' => 'SKU_PROPERTY_ID', 'text' => Loc::getMessage('BT_MOD_CATALOG_ERR_SKU_PROP_WITHOUT_PRODUCT')];
                            $boolResult = false;
                        }
                    } else {
                        $arMsg[] = ['id' => 'SKU_PROPERTY_ID', 'text' => Loc::getMessage('BT_MOD_CATALOG_ERR_SKU_PROP_NOT_FOUND')];
                        $boolResult = false;
                    }
                }
            } elseif ('UPDATE' === $ACTION) {
                $boolLocalFlag = (is_set($arFields, 'PRODUCT_IBLOCK_ID') === is_set($arFields, 'SKU_PROPERTY_ID'));
                if (!$boolLocalFlag) {
                    $arMsg[] = ['id' => 'PRODUCT_IBLOCK_ID', 'text' => Loc::getMessage('BT_MOD_CATALOG_ERR_PRODUCT_ID_AND_SKU_PROPERTY_ID_NEED')];
                    $boolResult = false;
                } else {
                    if (is_set($arFields, 'PRODUCT_IBLOCK_ID')) {
                        if (0 > (int) $arFields['PRODUCT_IBLOCK_ID']) {
                            $arMsg[] = ['id' => 'PRODUCT_IBLOCK_ID', 'text' => Loc::getMessage('BT_MOD_CATALOG_ERR_PRODUCT_ID_INVALID')];
                            $arFields['PRODUCT_IBLOCK_ID'] = 0;
                            $boolResult = false;
                        } elseif (0 < (int) $arFields['PRODUCT_IBLOCK_ID']) {
                            $arFields['PRODUCT_IBLOCK_ID'] = (int) $arFields['PRODUCT_IBLOCK_ID'];
                            $rsIBlocks = CIBlock::GetByID($arFields['PRODUCT_IBLOCK_ID']);
                            if (!($arIBlock = $rsIBlocks->Fetch())) {
                                $arMsg[] = ['id' => 'PRODUCT_IBLOCK_ID', 'text' => Loc::getMessage('BT_MOD_CATALOG_ERR_PRODUCT_ID_ABSENT')];
                                $arFields['PRODUCT_IBLOCK_ID'] = 0;
                                $boolResult = false;
                            } else {
                                if (0 < $ID && $arFields['PRODUCT_IBLOCK_ID'] === $ID) {
                                    $arMsg[] = ['id' => 'PRODUCT_IBLOCK_ID', 'text' => Loc::getMessage('BT_MOD_CATALOG_ERR_PRODUCT_ID_SELF')];
                                    $arFields['PRODUCT_IBLOCK_ID'] = 0;
                                    $boolResult = false;
                                } else {
                                    if (is_set($arFields, 'IBLOCK_ID') && $arFields['PRODUCT_IBLOCK_ID'] === $arFields['IBLOCK_ID']) {
                                        $arMsg[] = ['id' => 'PRODUCT_IBLOCK_ID', 'text' => Loc::getMessage('BT_MOD_CATALOG_ERR_PRODUCT_ID_SELF')];
                                        $arFields['PRODUCT_IBLOCK_ID'] = 0;
                                        $boolResult = false;
                                    }
                                }
                            }
                        }
                    }

                    if (is_set($arFields, 'SKU_PROPERTY_ID')) {
                        if (0 > (int) $arFields['SKU_PROPERTY_ID']) {
                            $arMsg[] = ['id' => 'SKU_PROPERTY_ID', 'text' => Loc::getMessage('BT_MOD_CATALOG_ERR_SKU_PROP_ID_INVALID')];
                            $arFields['SKU_PROPERTY_ID'] = 0;
                            $boolResult = false;
                        } else {
                            $arFields['SKU_PROPERTY_ID'] = (int) $arFields['SKU_PROPERTY_ID'];
                        }
                    }
                    if (is_set($arFields, 'PRODUCT_IBLOCK_ID') && is_set($arFields, 'SKU_PROPERTY_ID')) {
                        if ((0 < $arFields['PRODUCT_IBLOCK_ID']) && (0 === $arFields['SKU_PROPERTY_ID'])) {
                            $arMsg[] = ['id' => 'SKU_PROPERTY_ID', 'text' => Loc::getMessage('BT_MOD_CATALOG_ERR_PRODUCT_WITHOUT_SKU_PROP')];
                            $boolResult = false;
                        } elseif ((0 === $arFields['PRODUCT_IBLOCK_ID']) && (0 < $arFields['SKU_PROPERTY_ID'])) {
                            $arMsg[] = ['id' => 'PRODUCT_IBLOCK_ID', 'text' => Loc::getMessage('BT_MOD_CATALOG_ERR_SKU_PROP_WITHOUT_PRODUCT')];
                            $boolResult = false;
                        } elseif ((0 < $arFields['PRODUCT_IBLOCK_ID']) && (0 < $arFields['SKU_PROPERTY_ID'])) {
                            $rsProps = CIBlockProperty::GetList([], ['IBLOCK_ID' => $ID, 'ID' => $arFields['SKU_PROPERTY_ID'], 'ACTIVE' => 'Y']);
                            if ($arProp = $rsProps->Fetch()) {
                                if (('E' !== $arProp['PROPERTY_TYPE']) || ($arFields['PRODUCT_IBLOCK_ID'] !== $arProp['LINK_IBLOCK_ID'])) {
                                    $arMsg[] = ['id' => 'SKU_PROPERTY_ID', 'text' => Loc::getMessage('BT_MOD_CATALOG_ERR_SKU_PROP_WITHOUT_PRODUCT')];
                                    $boolResult = false;
                                }
                            } else {
                                $arMsg[] = ['id' => 'SKU_PROPERTY_ID', 'text' => Loc::getMessage('BT_MOD_CATALOG_ERR_SKU_PROP_NOT_FOUND')];
                                $boolResult = false;
                            }
                        }
                    }
                }
            }
        }

        if (!$boolResult) {
            $obError = new CAdminException($arMsg);
            $APPLICATION->ResetException();
            $APPLICATION->ThrowException($obError);
        }

        return $boolResult;
    }

    public static function GetByID($ID)
    {
        global $DB;

        $ID = (int) $ID;
        if ($ID <= 0) {
            return false;
        }

        if (isset(self::$arCatalogCache[$ID])) {
            return self::$arCatalogCache[$ID];
        }

        $strSql = 'SELECT CI.*, I.ID as ID, I.IBLOCK_TYPE_ID, I.LID, I.NAME,
					OFFERS.IBLOCK_ID OFFERS_IBLOCK_ID, OFFERS.SKU_PROPERTY_ID OFFERS_PROPERTY_ID
				FROM
					b_catalog_iblock CI INNER JOIN b_iblock I ON CI.IBLOCK_ID = I.ID
					LEFT JOIN b_catalog_iblock OFFERS ON CI.IBLOCK_ID = OFFERS.PRODUCT_IBLOCK_ID
				WHERE
					CI.IBLOCK_ID = '.$ID;
        $db_res = $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
        if ($res = $db_res->Fetch()) {
            $res['OFFERS'] = $res['PRODUCT_IBLOCK_ID'] ? 'Y' : 'N';
            self::$arCatalogCache[$ID] = $res;
            if (defined('CATALOG_GLOBAL_VARS') && 'Y' === CATALOG_GLOBAL_VARS) {
                global $CATALOG_CATALOG_CACHE;
                $CATALOG_CATALOG_CACHE = self::$arCatalogCache;
            }

            return $res;
        }

        return false;
    }

    public static function GetFilterOperation($key)
    {
        $arResult = [
            'FIELD' => '',
            'NEGATIVE' => 'N',
            'OPERATION' => '',
            'OR_NULL' => 'N',
        ];

        static $arDoubleModify = [
            '>=' => '>=',
            '<=' => '<=',
        ];

        static $arOneModify = [
            '>' => '>',
            '<' => '<',
            '@' => 'IN',
            '~' => 'LIKE',
            '%' => 'QUERY',
            '=' => '=',
        ];

        $key = (string) $key;
        if ('' === $key) {
            return false;
        }
        if (0 === strncmp($key, '!', 1)) {
            $arResult['NEGATIVE'] = 'Y';
            $key = mb_substr($key, 1);
            if ('' === $key) {
                return false;
            }
            if (0 === strncmp($key, '+', 1)) {
                $arResult['OR_NULL'] = 'Y';
                $key = mb_substr($key, 1);
            }
        } elseif (0 === strncmp($key, '+', 1)) {
            $arResult['OR_NULL'] = 'Y';
            $key = mb_substr($key, 1);
            if ('' === $key) {
                return false;
            }
            if (0 === strncmp($key, '!', 1)) {
                $arResult['NEGATIVE'] = 'Y';
                $key = mb_substr($key, 1);
            }
        }
        if ('' === $key) {
            return false;
        }
        $strKeyOp = mb_substr($key, 0, 2);
        if ('' !== $strKeyOp && isset($arDoubleModify[$strKeyOp])) {
            $arResult['OPERATION'] = $arDoubleModify[$strKeyOp];
            $arResult['FIELD'] = mb_substr($key, 2);

            return $arResult;
        }
        $strKeyOp = mb_substr($key, 0, 1);
        if ('' !== $strKeyOp && isset($arOneModify[$strKeyOp])) {
            $arResult['OPERATION'] = $arOneModify[$strKeyOp];
            $arResult['FIELD'] = mb_substr($key, 1);

            return $arResult;
        }
        $arResult['OPERATION'] = '=';
        $arResult['FIELD'] = $key;

        return $arResult;
    }

    public static function PrepareSql(&$arFields, $arOrder, $arFilter, $arGroupBy, $arSelectFields)
    {
        global $DB;

        $strSqlSelect = '';
        $strSqlFrom = '';
        $strSqlWhere = '';
        $strSqlGroupBy = '';
        $strSqlOrderBy = '';

        $sqlGroupByList = [];
        $sqlFrom = [];
        $sqlSelect = [];

        reset($arFields);
        $firstField = current($arFields);

        $strDBType = $DB->type;
        $oracleEdition = ('ORACLE' === $strDBType);

        $arGroupByFunct = [
            'COUNT' => true,
            'AVG' => true,
            'MIN' => true,
            'MAX' => true,
            'SUM' => true,
        ];

        // GROUP BY -->
        if (!empty($arGroupBy) && is_array($arGroupBy)) {
            $arSelectFields = $arGroupBy;
            foreach ($arGroupBy as $key => $val) {
                $val = mb_strtoupper($val);
                $key = mb_strtoupper($key);
                if (isset($arFields[$val]) && !isset($arGroupByFunct[$key])) {
                    $sqlGroupByList[] = $arFields[$val]['FIELD'];
                    if (isset($arFields[$val]['FROM']) && !empty($arFields[$val]['FROM'])) {
                        $sqlFrom[$arFields[$val]['FROM']] = true;
                    }
                }
            }
        }
        if (!empty($sqlGroupByList)) {
            $strSqlGroupBy = implode(', ', $sqlGroupByList);
        }
        // <-- GROUP BY

        // SELECT -->
        if (empty($arGroupBy) && is_array($arGroupBy)) {
            $sqlSelect[] = 'COUNT(%%_DISTINCT_%% '.$firstField['FIELD'].') as CNT';
        } else {
            if (isset($arSelectFields) && !is_array($arSelectFields)) {
                $arSelectFields = [$arSelectFields];
            }
            if (!empty($arSelectFields) && is_array($arSelectFields) && !in_array('*', $arSelectFields, true)) {
                $arClearFields = [];
                foreach ($arSelectFields as $key => $val) {
                    if (isset($arFields[$val])) {
                        $arClearFields[$key] = $val;
                    }
                }
                $arSelectFields = $arClearFields;
            }

            if (
                empty($arSelectFields)
                || !is_array($arSelectFields)
                || in_array('*', $arSelectFields, true)
            ) {
                foreach ($arFields as $fieldKey => $fieldDescr) {
                    if (isset($fieldDescr['WHERE_ONLY']) && 'Y' === $fieldDescr['WHERE_ONLY']) {
                        continue;
                    }

                    switch ($fieldDescr['TYPE']) {
                        case 'datetime':
                        case 'date':
                            if (isset($arOrder[$fieldKey])) {
                                $sqlSelect[] = $fieldDescr['FIELD'].' as '.$fieldKey.'_X1';
                            }

                            $sqlSelect[] = $DB->DateToCharFunction(
                                $fieldDescr['FIELD'],
                                'datetime' === $fieldDescr['TYPE'] ? 'FULL' : 'SHORT'
                            ).' as '.$fieldKey;

                            break;

                        default:
                            $sqlSelect[] = $fieldDescr['FIELD'].' as '.$fieldKey;

                            break;
                    }
                    if (isset($fieldDescr['FROM']) && !empty($fieldDescr['FROM'])) {
                        $sqlFrom[$fieldDescr['FROM']] = true;
                    }
                }
            } else {
                foreach ($arSelectFields as $key => $val) {
                    $val = mb_strtoupper($val);
                    $key = mb_strtoupper($key);
                    if (isset($arFields[$val])) {
                        if (isset($arGroupByFunct[$key])) {
                            $sqlSelect[] = $key.'('.$arFields[$val]['FIELD'].') as '.$val;
                        } else {
                            switch ($arFields[$val]['TYPE']) {
                                case 'datetime':
                                case 'date':
                                    if (isset($arOrder[$val])) {
                                        $sqlSelect[] = $arFields[$val]['FIELD'].' as '.$val.'_X1';
                                    }

                                    $sqlSelect[] = $DB->DateToCharFunction(
                                        $arFields[$val]['FIELD'],
                                        'datetime' === $arFields[$val]['TYPE'] ? 'FULL' : 'SHORT'
                                    ).' as '.$val;

                                    break;

                                default:
                                    $sqlSelect[] = $arFields[$val]['FIELD'].' as '.$val;

                                    break;
                            }
                        }
                        if (isset($arFields[$val]['FROM']) && !empty($arFields[$val]['FROM'])) {
                            $sqlFrom[$arFields[$val]['FROM']] = true;
                        }
                    }
                }
            }

            if (!empty($sqlGroupByList)) {
                $sqlSelect[] = 'COUNT(%%_DISTINCT_%% '.$firstField['FIELD'].') as CNT';
            } else {
                $sqlSelect[0] = '%%_DISTINCT_%% '.$sqlSelect[0];
            }
        }
        // <-- SELECT

        // WHERE -->
        $arSqlSearch = [];

        $filter_keys = (!is_array($arFilter) ? [] : array_keys($arFilter));

        for ($i = 0, $intCount = count($filter_keys); $i < $intCount; ++$i) {
            $vals = $arFilter[$filter_keys[$i]];
            $vals = (!is_array($vals) ? [$vals] : array_values($vals));

            $key = $filter_keys[$i];
            $key_res = CCatalog::GetFilterOperation($key);
            if (empty($key_res)) {
                continue;
            }
            $key = $key_res['FIELD'];
            $strNegative = $key_res['NEGATIVE'];
            $strOperation = $key_res['OPERATION'];
            $strOrNull = $key_res['OR_NULL'];

            if ('' !== $key && isset($arFields[$key])) {
                $arSqlSearch_tmp = [];

                if (!empty($vals)) {
                    if ('IN' === $strOperation) {
                        if (isset($arFields[$key]['WHERE'])) {
                            $arSqlSearch_tmp1 = call_user_func_array(
                                $arFields[$key]['WHERE'],
                                [$vals, $key, $strOperation, $strNegative, $arFields[$key]['FIELD'], &$arFields, &$arFilter]
                            );
                            if (false !== $arSqlSearch_tmp1) {
                                $arSqlSearch_tmp[] = $arSqlSearch_tmp1;
                            }
                        } else {
                            if ('int' === $arFields[$key]['TYPE']) {
                                $clearVals = [];
                                foreach ($vals as $item) {
                                    $item = (int) $item;
                                    $clearVals[$item] = $item;
                                }
                                unset($item);
                                if (empty($clearVals)) {
                                    $arSqlSearch_tmp[] = '(1 = 2)';
                                } else {
                                    $arSqlSearch_tmp[] = ('Y' === $strNegative ? ' NOT ' : '').'('.$arFields[$key]['FIELD'].' IN ('.implode(',', $clearVals).'))';
                                }
                                unset($clearVals);
                            } elseif ('double' === $arFields[$key]['TYPE']) {
                                $clearVals = [];
                                foreach ($vals as $item) {
                                    $clearVals[] = (float) $item;
                                }
                                unset($item);
                                if (empty($clearVals)) {
                                    $arSqlSearch_tmp[] = '(1 = 2)';
                                } else {
                                    $clearVals = array_unique($clearVals);
                                    $arSqlSearch_tmp[] = ('Y' === $strNegative ? ' NOT ' : '').'('.$arFields[$key]['FIELD'].' IN ('.implode(',', $clearVals).'))';
                                }
                                unset($clearVals);
                            } elseif ('string' === $arFields[$key]['TYPE'] || 'char' === $arFields[$key]['TYPE']) {
                                $clearVals = [];
                                foreach ($vals as $item) {
                                    $clearVals[] = "'".$DB->ForSql($item)."'";
                                }
                                unset($item);
                                if (empty($clearVals)) {
                                    $arSqlSearch_tmp[] = '(1 = 2)';
                                } else {
                                    $clearVals = array_unique($clearVals);
                                    $arSqlSearch_tmp[] = (('Y' === $strNegative) ? ' NOT ' : '').'('.$arFields[$key]['FIELD'].' '.$strOperation.' ('.implode(',', $clearVals).'))';
                                }
                                unset($clearVals);
                            } elseif ('datetime' === $arFields[$key]['TYPE'] || 'date' === $arFields[$key]['TYPE']) {
                                $valueFormat = ('datetime' === $arFields[$key]['TYPE'] ? 'FULL' : 'SHORT');
                                $clearVals = [];
                                foreach ($vals as $item) {
                                    $clearVals[] = $DB->CharToDateFunction($item, $valueFormat);
                                }
                                unset($item);
                                if (empty($clearVals)) {
                                    $arSqlSearch_tmp[] = '(1 = 2)';
                                } else {
                                    $clearVals = array_unique($clearVals);
                                    $arSqlSearch_tmp[] = ('Y' === $strNegative ? ' NOT ' : '').'('.$arFields[$key]['FIELD'].' '.$strOperation.' ('.implode(',', $clearVals).'))';
                                }
                                unset($clearVals, $valueFormat);
                            }
                        }
                    } else {
                        for ($j = 0, $intCountVals = count($vals); $j < $intCountVals; ++$j) {
                            $val = $vals[$j];

                            if (isset($arFields[$key]['WHERE'])) {
                                $arSqlSearch_tmp1 = call_user_func_array(
                                    $arFields[$key]['WHERE'],
                                    [$val, $key, $strOperation, $strNegative, $arFields[$key]['FIELD'], &$arFields, &$arFilter]
                                );
                                if (false !== $arSqlSearch_tmp1) {
                                    $arSqlSearch_tmp[] = $arSqlSearch_tmp1;
                                }
                            } else {
                                if ('int' === $arFields[$key]['TYPE']) {
                                    if (0 === (int) $val && false !== mb_strpos($strOperation, '=')) {
                                        $arSqlSearch_tmp[] = '('.$arFields[$key]['FIELD'].' IS '.(('Y' === $strNegative) ? 'NOT ' : '').'NULL) '.(('Y' === $strNegative) ? 'AND' : 'OR').' '.(('Y' === $strNegative) ? 'NOT ' : '').'('.$arFields[$key]['FIELD'].' '.$strOperation.' 0)';
                                    } else {
                                        $arSqlSearch_tmp[] = (('Y' === $strNegative) ? ' '.$arFields[$key]['FIELD'].' IS NULL OR NOT ' : '').'('.$arFields[$key]['FIELD'].' '.$strOperation.' '.(int) $val.' )';
                                    }
                                } elseif ('double' === $arFields[$key]['TYPE']) {
                                    $val = str_replace(',', '.', $val);

                                    if (0 === (float) $val && false !== mb_strpos($strOperation, '=')) {
                                        $arSqlSearch_tmp[] = '('.$arFields[$key]['FIELD'].' IS '.(('Y' === $strNegative) ? 'NOT ' : '').'NULL) '.(('Y' === $strNegative) ? 'AND' : 'OR').' '.(('Y' === $strNegative) ? 'NOT ' : '').'('.$arFields[$key]['FIELD'].' '.$strOperation.' 0)';
                                    } else {
                                        $arSqlSearch_tmp[] = (('Y' === $strNegative) ? ' '.$arFields[$key]['FIELD'].' IS NULL OR NOT ' : '').'('.$arFields[$key]['FIELD'].' '.$strOperation.' '.(float) $val.' )';
                                    }
                                } elseif ('string' === $arFields[$key]['TYPE'] || 'char' === $arFields[$key]['TYPE']) {
                                    if ('QUERY' === $strOperation) {
                                        $arSqlSearch_tmp[] = GetFilterQuery($arFields[$key]['FIELD'], $val, 'Y');
                                    } else {
                                        if (('' === $val) && (false !== mb_strpos($strOperation, '='))) {
                                            $arSqlSearch_tmp[] = '('.$arFields[$key]['FIELD'].' IS '.(('Y' === $strNegative) ? 'NOT ' : '').'NULL) '.(('Y' === $strNegative) ? 'AND NOT' : 'OR').' ('.$DB->Length($arFields[$key]['FIELD']).' <= 0) '.(('Y' === $strNegative) ? 'AND NOT' : 'OR').' ('.$arFields[$key]['FIELD'].' '.$strOperation." '".$DB->ForSql($val)."' )";
                                        } else {
                                            $arSqlSearch_tmp[] = (('Y' === $strNegative) ? ' '.$arFields[$key]['FIELD'].' IS NULL OR NOT ' : '').'('.$arFields[$key]['FIELD'].' '.$strOperation." '".$DB->ForSql($val)."' )";
                                        }
                                    }
                                } elseif ('datetime' === $arFields[$key]['TYPE']) {
                                    if ('' === $val) {
                                        $arSqlSearch_tmp[] = ('Y' === $strNegative ? 'NOT' : '').'('.$arFields[$key]['FIELD'].' IS NULL)';
                                    } else {
                                        $arSqlSearch_tmp[] = ('Y' === $strNegative ? ' '.$arFields[$key]['FIELD'].' IS NULL OR NOT ' : '').'('.$arFields[$key]['FIELD'].' '.$strOperation.' '.$DB->CharToDateFunction($val, 'FULL').')';
                                    }
                                } elseif ('date' === $arFields[$key]['TYPE']) {
                                    if ('' === $val) {
                                        $arSqlSearch_tmp[] = ('Y' === $strNegative ? 'NOT' : '').'('.$arFields[$key]['FIELD'].' IS NULL)';
                                    } else {
                                        $arSqlSearch_tmp[] = ('Y' === $strNegative ? ' '.$arFields[$key]['FIELD'].' IS NULL OR NOT ' : '').'('.$arFields[$key]['FIELD'].' '.$strOperation.' '.$DB->CharToDateFunction($val, 'SHORT').')';
                                    }
                                }
                            }
                        }
                    }
                }

                if (isset($arFields[$key]['FROM']) && !empty($arFields[$key]['FROM'])) {
                    $sqlFrom[$arFields[$key]['FROM']] = true;
                }

                $strSqlSearch_tmp = '';
                for ($j = 0, $intCountSearch = count($arSqlSearch_tmp); $j < $intCountSearch; ++$j) {
                    if ($j > 0) {
                        $strSqlSearch_tmp .= ('Y' === $strNegative ? ' AND ' : ' OR ');
                    }
                    $strSqlSearch_tmp .= '('.$arSqlSearch_tmp[$j].')';
                }
                if ('Y' === $strOrNull) {
                    if ('' !== $strSqlSearch_tmp) {
                        $strSqlSearch_tmp .= ('Y' === $strNegative ? ' AND ' : ' OR ');
                    }
                    $strSqlSearch_tmp .= '('.$arFields[$key]['FIELD'].' IS '.('Y' === $strNegative ? 'NOT ' : '').'NULL)';

                    if ('int' === $arFields[$key]['TYPE'] || 'double' === $arFields[$key]['TYPE']) {
                        if ('' !== $strSqlSearch_tmp) {
                            $strSqlSearch_tmp .= ('Y' === $strNegative ? ' AND ' : ' OR ');
                        }
                        $strSqlSearch_tmp .= '('.$arFields[$key]['FIELD'].' '.('Y' === $strNegative ? '<>' : '=').' 0)';
                    } elseif ('string' === $arFields[$key]['TYPE'] || 'char' === $arFields[$key]['TYPE']) {
                        if ('' !== $strSqlSearch_tmp) {
                            $strSqlSearch_tmp .= ('Y' === $strNegative ? ' AND ' : ' OR ');
                        }
                        $strSqlSearch_tmp .= '('.$arFields[$key]['FIELD'].' '.('Y' === $strNegative ? '<>' : '=')." '')";
                    }
                }

                if ('' !== $strSqlSearch_tmp) {
                    $arSqlSearch[] = '('.$strSqlSearch_tmp.')';
                }
            }
        }

        if (!empty($arSqlSearch)) {
            $strSqlWhere = '('.implode(') and (', $arSqlSearch).')';
        }
        // <-- WHERE

        // ORDER BY -->
        $arSqlOrder = [];
        $sortExist = [];
        foreach ($arOrder as $by => $order) {
            $by = mb_strtoupper($by);
            $order = mb_strtoupper($order);

            if ('ASC' !== $order) {
                $order = 'DESC';
            }
            if ($oracleEdition) {
                $order .= ('ASC' === $order ? ' NULLS FIRST' : ' NULLS LAST');
            }

            if (isset($arFields[$by])) {
                if (isset($sortExist[$by])) {
                    continue;
                }
                $sortExist[$by] = true;
                $arSqlOrder[] = $arFields[$by]['FIELD'].' '.$order;
                if (isset($arFields[$by]['FROM']) && !empty($arFields[$by]['FROM'])) {
                    $sqlFrom[$arFields[$by]['FROM']] = true;
                }
            }
        }
        if (!empty($arSqlOrder)) {
            $strSqlOrderBy = implode(', ', $arSqlOrder);
        }
        // <-- ORDER BY

        $sqlFromTables = [];
        if (!empty($sqlFrom)) {
            $sqlFromTables = array_keys($sqlFrom);
            $strSqlFrom = implode(' ', $sqlFromTables);
        }

        if (!empty($sqlSelect)) {
            $strSqlSelect = implode(', ', $sqlSelect);
        }

        return [
            'SELECT' => $strSqlSelect,
            'FROM' => $strSqlFrom,
            'WHERE' => $strSqlWhere,
            'GROUPBY' => $strSqlGroupBy,
            'ORDERBY' => $strSqlOrderBy,
            'SELECT_FIELDS' => $sqlSelect,
            'FROM_TABLES' => $sqlFromTables,
            'GROUPBY_FIELDS' => $sqlGroupByList,
            'ORDERBY_FIELDS' => array_keys($sortExist),
        ];
    }

    public static function _PrepareSql(&$arFields, $arOrder, $arFilter, $arGroupBy, $arSelectFields)
    {
        global $DB;

        $strSqlSelect = '';
        $strSqlFrom = '';
        $strSqlWhere = '';
        $strSqlGroupBy = '';

        $sqlGroupByList = [];

        $strDBType = $DB->type;
        $oracleEdition = ('ORACLE' === $strDBType);

        $arGroupByFunct = [
            'COUNT' => true,
            'AVG' => true,
            'MIN' => true,
            'MAX' => true,
            'SUM' => true,
        ];

        $arAlreadyJoined = [];

        // GROUP BY -->
        if (!empty($arGroupBy) && is_array($arGroupBy)) {
            foreach ($arGroupBy as $key => $val) {
                $val = mb_strtoupper($val);
                $key = mb_strtoupper($key);
                if (isset($arFields[$val]) && !isset($arGroupByFunct[$key])) {
                    $sqlGroupByList[] = $arFields[$val]['FIELD'];

                    if (isset($arFields[$val]['FROM'])
                        && '' !== $arFields[$val]['FROM']
                        && !in_array($arFields[$val]['FROM'], $arAlreadyJoined, true)) {
                        if ('' !== $strSqlFrom) {
                            $strSqlFrom .= ' ';
                        }
                        $strSqlFrom .= $arFields[$val]['FROM'];
                        $arAlreadyJoined[] = $arFields[$val]['FROM'];
                    }
                }
            }
        }
        if (!empty($sqlGroupByList)) {
            $strSqlGroupBy = implode(', ', $sqlGroupByList);
        }
        // <-- GROUP BY

        // SELECT -->
        $arFieldsKeys = array_keys($arFields);

        if (empty($arGroupBy) && is_array($arGroupBy)) {
            $strSqlSelect = 'COUNT(%%_DISTINCT_%% '.$arFields[$arFieldsKeys[0]]['FIELD'].') as CNT ';
        } else {
            if (
                isset($arSelectFields)
                && is_string($arSelectFields)
                && '' !== $arSelectFields
                && isset($arFields[$arSelectFields])
            ) {
                $arSelectFields = [$arSelectFields];
            }

            if (empty($arSelectFields)
                || !is_array($arSelectFields)
                || in_array('*', $arSelectFields, true)) {
                for ($i = 0, $intCount = count($arFieldsKeys); $i < $intCount; ++$i) {
                    if (isset($arFields[$arFieldsKeys[$i]]['WHERE_ONLY'])
                        && 'Y' === $arFields[$arFieldsKeys[$i]]['WHERE_ONLY']) {
                        continue;
                    }

                    if ('' !== $strSqlSelect) {
                        $strSqlSelect .= ', ';
                    }

                    if ('datetime' === $arFields[$arFieldsKeys[$i]]['TYPE']) {
                        if (isset($arOrder[$arFieldsKeys[$i]])) {
                            $strSqlSelect .= $arFields[$arFieldsKeys[$i]]['FIELD'].' as '.$arFieldsKeys[$i].'_X1, ';
                        }

                        $strSqlSelect .= $DB->DateToCharFunction($arFields[$arFieldsKeys[$i]]['FIELD'], 'FULL').' as '.$arFieldsKeys[$i];
                    } elseif ('date' === $arFields[$arFieldsKeys[$i]]['TYPE']) {
                        if (isset($arOrder[$arFieldsKeys[$i]])) {
                            $strSqlSelect .= $arFields[$arFieldsKeys[$i]]['FIELD'].' as '.$arFieldsKeys[$i].'_X1, ';
                        }

                        $strSqlSelect .= $DB->DateToCharFunction($arFields[$arFieldsKeys[$i]]['FIELD'], 'SHORT').' as '.$arFieldsKeys[$i];
                    } else {
                        $strSqlSelect .= $arFields[$arFieldsKeys[$i]]['FIELD'].' as '.$arFieldsKeys[$i];
                    }

                    if (isset($arFields[$arFieldsKeys[$i]]['FROM'])
                        && '' !== $arFields[$arFieldsKeys[$i]]['FROM']
                        && !in_array($arFields[$arFieldsKeys[$i]]['FROM'], $arAlreadyJoined, true)) {
                        if ('' !== $strSqlFrom) {
                            $strSqlFrom .= ' ';
                        }
                        $strSqlFrom .= $arFields[$arFieldsKeys[$i]]['FROM'];
                        $arAlreadyJoined[] = $arFields[$arFieldsKeys[$i]]['FROM'];
                    }
                }
            } else {
                foreach ($arSelectFields as $key => $val) {
                    $val = mb_strtoupper($val);
                    $key = mb_strtoupper($key);
                    if (isset($arFields[$val])) {
                        if ('' !== $strSqlSelect) {
                            $strSqlSelect .= ', ';
                        }

                        if (isset($arGroupByFunct[$key])) {
                            $strSqlSelect .= $key.'('.$arFields[$val]['FIELD'].') as '.$val;
                        } else {
                            if ('datetime' === $arFields[$val]['TYPE']) {
                                if (isset($arOrder[$val])) {
                                    $strSqlSelect .= $arFields[$val]['FIELD'].' as '.$val.'_X1, ';
                                }

                                $strSqlSelect .= $DB->DateToCharFunction($arFields[$val]['FIELD'], 'FULL').' as '.$val;
                            } elseif ('date' === $arFields[$val]['TYPE']) {
                                if (isset($arOrder[$val])) {
                                    $strSqlSelect .= $arFields[$val]['FIELD'].' as '.$val.'_X1, ';
                                }

                                $strSqlSelect .= $DB->DateToCharFunction($arFields[$val]['FIELD'], 'SHORT').' as '.$val;
                            } else {
                                $strSqlSelect .= $arFields[$val]['FIELD'].' as '.$val;
                            }
                        }

                        if (isset($arFields[$val]['FROM'])
                            && '' !== $arFields[$val]['FROM']
                            && !in_array($arFields[$val]['FROM'], $arAlreadyJoined, true)) {
                            if ('' !== $strSqlFrom) {
                                $strSqlFrom .= ' ';
                            }
                            $strSqlFrom .= $arFields[$val]['FROM'];
                            $arAlreadyJoined[] = $arFields[$val]['FROM'];
                        }
                    }
                }
            }

            if ('' !== $strSqlGroupBy) {
                if ('' !== $strSqlSelect) {
                    $strSqlSelect .= ', ';
                }
                $strSqlSelect .= 'COUNT(%%_DISTINCT_%% '.$arFields[$arFieldsKeys[0]]['FIELD'].') as CNT';
            } else {
                $strSqlSelect = '%%_DISTINCT_%% '.$strSqlSelect;
            }
        }
        // <-- SELECT

        // WHERE -->
        $arSqlSearch = [];
        $arSqlHaving = [];

        $filter_keys = (!is_array($arFilter) ? [] : array_keys($arFilter));

        for ($i = 0, $intCount = count($filter_keys); $i < $intCount; ++$i) {
            $vals = $arFilter[$filter_keys[$i]];
            $vals = (!is_array($vals) ? [$vals] : array_values($vals));

            $key = $filter_keys[$i];
            $key_res = CCatalog::GetFilterOperation($key);
            if (empty($key_res)) {
                continue;
            }
            $key = $key_res['FIELD'];
            $strNegative = $key_res['NEGATIVE'];
            $strOperation = $key_res['OPERATION'];
            $strOrNull = $key_res['OR_NULL'];

            if ('' !== $key && isset($arFields[$key])) {
                $arSqlSearch_tmp = [];
                $arSqlHaving_tmp = [];
                for ($j = 0, $intCountVals = count($vals); $j < $intCountVals; ++$j) {
                    $val = $vals[$j];

                    if (isset($arFields[$key]['WHERE'])) {
                        $arSqlSearch_tmp1 = call_user_func_array(
                            $arFields[$key]['WHERE'],
                            [$val, $key, $strOperation, $strNegative, $arFields[$key]['FIELD'], &$arFields, &$arFilter]
                        );
                        if (false !== $arSqlSearch_tmp1) {
                            if (isset($arFields[$key]['GROUPED']) && 'Y' === $arFields[$key]['GROUPED']) {
                                $arSqlHaving_tmp[] = $arSqlSearch_tmp1;
                            } else {
                                $arSqlSearch_tmp[] = $arSqlSearch_tmp1;
                            }
                        }
                    } else {
                        $arSqlSearch_tmp1 = '';

                        if ('int' === $arFields[$key]['TYPE']) {
                            if (0 === (int) $val && false !== mb_strpos($strOperation, '=')) {
                                $arSqlSearch_tmp1 = '('.$arFields[$key]['FIELD'].' IS '.(('Y' === $strNegative) ? 'NOT ' : '').'NULL) '.(('Y' === $strNegative) ? 'AND' : 'OR').' '.(('Y' === $strNegative) ? 'NOT ' : '').'('.$arFields[$key]['FIELD'].' '.$strOperation.' 0)';
                            } else {
                                $arSqlSearch_tmp1 = (('Y' === $strNegative) ? ' '.$arFields[$key]['FIELD'].' IS NULL OR NOT ' : '').'('.$arFields[$key]['FIELD'].' '.$strOperation.' '.(int) $val.' )';
                            }
                        } elseif ('double' === $arFields[$key]['TYPE']) {
                            $val = str_replace(',', '.', $val);

                            if (0 === (float) $val && false !== mb_strpos($strOperation, '=')) {
                                $arSqlSearch_tmp1 = '('.$arFields[$key]['FIELD'].' IS '.(('Y' === $strNegative) ? 'NOT ' : '').'NULL) '.(('Y' === $strNegative) ? 'AND' : 'OR').' '.(('Y' === $strNegative) ? 'NOT ' : '').'('.$arFields[$key]['FIELD'].' '.$strOperation.' 0)';
                            } else {
                                $arSqlSearch_tmp1 = (('Y' === $strNegative) ? ' '.$arFields[$key]['FIELD'].' IS NULL OR NOT ' : '').'('.$arFields[$key]['FIELD'].' '.$strOperation.' '.(float) $val.' )';
                            }
                        } elseif ('string' === $arFields[$key]['TYPE'] || 'char' === $arFields[$key]['TYPE']) {
                            if ('QUERY' === $strOperation) {
                                $arSqlSearch_tmp1 = GetFilterQuery($arFields[$key]['FIELD'], $val, 'Y');
                            } else {
                                if (('' === $val) && (false !== mb_strpos($strOperation, '='))) {
                                    $arSqlSearch_tmp1 = '('.$arFields[$key]['FIELD'].' IS '.(('Y' === $strNegative) ? 'NOT ' : '').'NULL) '.(('Y' === $strNegative) ? 'AND NOT' : 'OR').' ('.$DB->Length($arFields[$key]['FIELD']).' <= 0) '.(('Y' === $strNegative) ? 'AND NOT' : 'OR').' ('.$arFields[$key]['FIELD'].' '.$strOperation." '".$DB->ForSql($val)."' )";
                                } else {
                                    $arSqlSearch_tmp1 = (('Y' === $strNegative) ? ' '.$arFields[$key]['FIELD'].' IS NULL OR NOT ' : '').'('.$arFields[$key]['FIELD'].' '.$strOperation." '".$DB->ForSql($val)."' )";
                                }
                            }
                        } elseif ('datetime' === $arFields[$key]['TYPE']) {
                            if ('' === $val) {
                                $arSqlSearch_tmp1 = ('Y' === $strNegative ? 'NOT' : '').'('.$arFields[$key]['FIELD'].' IS NULL)';
                            } else {
                                $arSqlSearch_tmp1 = ('Y' === $strNegative ? ' '.$arFields[$key]['FIELD'].' IS NULL OR NOT ' : '').'('.$arFields[$key]['FIELD'].' '.$strOperation.' '.$DB->CharToDateFunction($val, 'FULL').')';
                            }
                        } elseif ('date' === $arFields[$key]['TYPE']) {
                            if ('' === $val) {
                                $arSqlSearch_tmp1 = ('Y' === $strNegative ? 'NOT' : '').'('.$arFields[$key]['FIELD'].' IS NULL)';
                            } else {
                                $arSqlSearch_tmp1 = ('Y' === $strNegative ? ' '.$arFields[$key]['FIELD'].' IS NULL OR NOT ' : '').'('.$arFields[$key]['FIELD'].' '.$strOperation.' '.$DB->CharToDateFunction($val, 'SHORT').')';
                            }
                        }

                        if (isset($arFields[$key]['GROUPED']) && 'Y' === $arFields[$key]['GROUPED']) {
                            $arSqlHaving_tmp[] = $arSqlSearch_tmp1;
                        } else {
                            $arSqlSearch_tmp[] = $arSqlSearch_tmp1;
                        }
                    }
                }

                if (isset($arFields[$key]['FROM'])
                    && '' !== $arFields[$key]['FROM']
                    && !in_array($arFields[$key]['FROM'], $arAlreadyJoined, true)) {
                    if ('' !== $strSqlFrom) {
                        $strSqlFrom .= ' ';
                    }
                    $strSqlFrom .= $arFields[$key]['FROM'];
                    $arAlreadyJoined[] = $arFields[$key]['FROM'];
                }

                $strSqlSearch_tmp = '';
                for ($j = 0, $intCountSearchTmp = count($arSqlSearch_tmp); $j < $intCountSearchTmp; ++$j) {
                    if ($j > 0) {
                        $strSqlSearch_tmp .= ('Y' === $strNegative ? ' AND ' : ' OR ');
                    }
                    $strSqlSearch_tmp .= '('.$arSqlSearch_tmp[$j].')';
                }
                if ('Y' === $strOrNull) {
                    if ('' !== $strSqlSearch_tmp) {
                        $strSqlSearch_tmp .= ('Y' === $strNegative ? ' AND ' : ' OR ');
                    }
                    $strSqlSearch_tmp .= '('.$arFields[$key]['FIELD'].' IS '.('Y' === $strNegative ? 'NOT ' : '').'NULL)';

                    if ('int' === $arFields[$key]['TYPE'] || 'double' === $arFields[$key]['TYPE']) {
                        if ('' !== $strSqlSearch_tmp) {
                            $strSqlSearch_tmp .= ('Y' === $strNegative ? ' AND ' : ' OR ');
                        }
                        $strSqlSearch_tmp .= '('.$arFields[$key]['FIELD'].' '.('Y' === $strNegative ? '<>' : '=').' 0)';
                    } elseif ('string' === $arFields[$key]['TYPE'] || 'char' === $arFields[$key]['TYPE']) {
                        if ('' !== $strSqlSearch_tmp) {
                            $strSqlSearch_tmp .= ('Y' === $strNegative ? ' AND ' : ' OR ');
                        }
                        $strSqlSearch_tmp .= '('.$arFields[$key]['FIELD'].' '.('Y' === $strNegative ? '<>' : '=')." '')";
                    }
                }

                if ('' !== $strSqlSearch_tmp) {
                    $arSqlSearch[] = '('.$strSqlSearch_tmp.')';
                }

                $strSqlHaving_tmp = '';
                for ($j = 0, $intCountHavingTmp = count($arSqlHaving_tmp); $j < $intCountHavingTmp; ++$j) {
                    if ($j > 0) {
                        $strSqlHaving_tmp .= ('Y' === $strNegative ? ' AND ' : ' OR ');
                    }
                    $strSqlHaving_tmp .= '('.$arSqlHaving_tmp[$j].')';
                }
                if ('Y' === $strOrNull) {
                    if ('' !== $strSqlHaving_tmp) {
                        $strSqlHaving_tmp .= ('Y' === $strNegative ? ' AND ' : ' OR ');
                    }
                    $strSqlHaving_tmp .= '('.$arFields[$key]['FIELD'].' IS '.('Y' === $strNegative ? 'NOT ' : '').'NULL)';

                    if ('' !== $strSqlHaving_tmp) {
                        $strSqlHaving_tmp .= ('Y' === $strNegative ? ' AND ' : ' OR ');
                    }
                    if ('int' === $arFields[$key]['TYPE'] || 'double' === $arFields[$key]['TYPE']) {
                        $strSqlHaving_tmp .= '('.$arFields[$key]['FIELD'].' '.('Y' === $strNegative ? '<>' : '=').' 0)';
                    } elseif ('string' === $arFields[$key]['TYPE'] || 'char' === $arFields[$key]['TYPE']) {
                        $strSqlHaving_tmp .= '('.$arFields[$key]['FIELD'].' '.('Y' === $strNegative ? '<>' : '=')." '')";
                    }
                }

                if ('' !== $strSqlHaving_tmp) {
                    $arSqlHaving[] = '('.$strSqlHaving_tmp.')';
                }
            }
        }

        if (!empty($arSqlSearch)) {
            $strSqlWhere = '('.implode(') and (', $arSqlSearch).')';
        }

        $strSqlHaving = '';
        if (!empty($arSqlHaving)) {
            $strSqlHaving = '('.implode(') and (', $arSqlHaving).')';
        }
        // <-- WHERE

        // ORDER BY -->
        $arSqlOrder = [];
        foreach ($arOrder as $by => $order) {
            $by = mb_strtoupper($by);
            $order = mb_strtoupper($order);

            if ('ASC' !== $order) {
                $order = 'DESC'.($oracleEdition ? ' NULLS LAST' : '');
            } else {
                $order = 'ASC'.($oracleEdition ? ' NULLS FIRST' : '');
            }

            if (isset($arFields[$by])) {
                $arSqlOrder[] = ' '.$arFields[$by]['FIELD'].' '.$order.' ';

                if (isset($arFields[$by]['FROM'])
                    && '' !== $arFields[$by]['FROM']
                    && !in_array($arFields[$by]['FROM'], $arAlreadyJoined, true)) {
                    if ('' !== $strSqlFrom) {
                        $strSqlFrom .= ' ';
                    }
                    $strSqlFrom .= $arFields[$by]['FROM'];
                    $arAlreadyJoined[] = $arFields[$by]['FROM'];
                }
            }
        }

        $strSqlOrder = '';
        DelDuplicateSort($arSqlOrder);
        if (!empty($arSqlOrder)) {
            $strSqlOrder = implode(', ', $arSqlOrder);
        }
        // <-- ORDER BY

        return [
            'SELECT' => $strSqlSelect,
            'FROM' => $strSqlFrom,
            'WHERE' => $strSqlWhere,
            'GROUPBY' => $strSqlGroupBy,
            'ORDERBY' => $strSqlOrder,
            'HAVING' => $strSqlHaving,
        ];
    }

    public static function Add($arFields)
    {
        global $DB;

        if (!CCatalog::CheckFields('ADD', $arFields, 0)) {
            return false;
        }

        $arInsert = $DB->PrepareInsert('b_catalog_iblock', $arFields);

        $strSql = 'INSERT INTO b_catalog_iblock('.$arInsert[0].') VALUES('.$arInsert[1].')';
        $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);

        CCatalogSku::ClearCache();
        Catalog\CatalogIblockTable::cleanCache();

        return true;
    }

    public static function Update($ID, $arFields)
    {
        global $DB;
        $ID = (int) $ID;

        if (!CCatalog::CheckFields('UPDATE', $arFields, $ID)) {
            return false;
        }

        $strUpdate = $DB->PrepareUpdate('b_catalog_iblock', $arFields);
        if (!empty($strUpdate)) {
            $strSql = 'UPDATE b_catalog_iblock SET '.$strUpdate.' WHERE IBLOCK_ID = '.$ID;
            $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);

            if (isset(self::$arCatalogCache[$ID])) {
                unset(self::$arCatalogCache[$ID]);
                if (defined('CATALOG_GLOBAL_VARS') && 'Y' === CATALOG_GLOBAL_VARS) {
                    global $CATALOG_CATALOG_CACHE;
                    $CATALOG_CATALOG_CACHE = self::$arCatalogCache;
                }
            }
            if (isset(self::$catalogVatCache[$ID])) {
                unset(self::$catalogVatCache[$ID]);
            }
        }
        CCatalogSku::ClearCache();
        Catalog\CatalogIblockTable::cleanCache();

        return true;
    }

    public static function Delete($ID)
    {
        global $DB;
        $ID = (int) $ID;

        foreach (GetModuleEvents('catalog', 'OnBeforeCatalogDelete', true) as $arEvent) {
            if (false === ExecuteModuleEventEx($arEvent, [$ID])) {
                return false;
            }
        }

        foreach (GetModuleEvents('catalog', 'OnCatalogDelete', true) as $arEvent) {
            ExecuteModuleEventEx($arEvent, [$ID]);
        }

        if (isset(self::$arCatalogCache[$ID])) {
            unset(self::$arCatalogCache[$ID]);
            if (defined('CATALOG_GLOBAL_VARS') && CATALOG_GLOBAL_VARS === 'Y') {
                global $CATALOG_CATALOG_CACHE;
                $CATALOG_CATALOG_CACHE = self::$arCatalogCache;
            }
        }
        if (isset(self::$catalogVatCache[$ID])) {
            unset(self::$catalogVatCache[$ID]);
        }

        CCatalogSku::ClearCache();
        Catalog\CatalogIblockTable::cleanCache();
        CCatalogProduct::ClearCache();

        return $DB->Query('DELETE FROM b_catalog_iblock WHERE IBLOCK_ID = '.$ID, true);
    }

    public static function OnIBlockDelete($ID)
    {
        return CCatalog::Delete($ID);
    }

    public static function PreGenerateXML($xml_type = 'yandex'): string
    {
        if ('yandex' === $xml_type) {
            $strYandexAgent = (string) Main\Config\Option::get('catalog', 'yandex_agent_file');
            if ('' !== $strYandexAgent) {
                if (file_exists($_SERVER['DOCUMENT_ROOT'].$strYandexAgent) && is_file($_SERVER['DOCUMENT_ROOT'].$strYandexAgent)) {
                    include_once $_SERVER['DOCUMENT_ROOT'].$strYandexAgent;
                } else {
                    CEventLog::Log('WARNING', 'CAT_YAND_FILE', 'catalog', 'YandexAgent', $strYandexAgent);

                    include_once $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/catalog/load/yandex.php';
                }
            } else {
                include_once $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/catalog/load/yandex.php';
            }
        }

        global $pPERIOD;
        $pPERIOD = (int) Main\Config\Option::get('catalog', 'yandex_xml_period') * 3_600;

        return 'CCatalog::PreGenerateXML("'.$xml_type.'");';
    }

    /**
     * @deprecated deprecated since catalog 11.0.2
     * @see CCatalogSku::GetInfoByProductIBlock()
     *
     * @param int $ID
     *
     * @return array|false
     */
    public static function GetSkuInfoByProductID($ID)
    {
        return CCatalogSku::GetInfoByProductIBlock($ID);
    }

    /**
     * @deprecated deprecated since catalog 11.0.2
     * @see CCatalogSku::GetInfoByLinkProperty()
     *
     * @param int $ID
     *
     * @return array|false
     */
    public static function GetSkuInfoByPropID($ID)
    {
        return CCatalogSku::GetInfoByLinkProperty($ID);
    }

    public static function OnBeforeIBlockElementDelete($ID): bool
    {
        global $APPLICATION;

        $ID = (int) $ID;
        if (0 < $ID) {
            $intIBlockID = (int) CIBlockElement::GetIBlockByID($ID);
            if (0 < $intIBlockID) {
                $arCatalog = CCatalogSku::GetInfoByProductIBlock($intIBlockID);
                if (!empty($arCatalog)) {
                    $arFilter = ['IBLOCK_ID' => $arCatalog['IBLOCK_ID'], '=PROPERTY_'.$arCatalog['SKU_PROPERTY_ID'] => $ID];
                    $rsOffers = CIBlockElement::GetList([], $arFilter, false, false, ['ID', 'IBLOCK_ID']);
                    while ($arOffer = $rsOffers->Fetch()) {
                        foreach (GetModuleEvents('iblock', 'OnBeforeIBlockElementDelete', true) as $arEvent) {
                            if (false === ExecuteModuleEventEx($arEvent, [$arOffer['ID']])) {
                                $err = '';
                                $err_id = false;
                                if ($ex = $APPLICATION->GetException()) {
                                    $err = $ex->GetString();
                                    $err_id = $ex->GetID();
                                }
                                $APPLICATION->ThrowException($err, $err_id);

                                return false;
                            }
                        }
                        if (!CIBlockElement::Delete($arOffer['ID'])) {
                            $APPLICATION->ThrowException(Loc::getMessage('BT_MOD_CATALOG_ERR_CANNOT_DELETE_OFFERS'));

                            return false;
                        }
                    }
                }
            }
        }

        return true;
    }

    public static function OnBeforeCatalogDelete($ID): bool
    {
        global $APPLICATION;

        $arMsg = [];

        $ID = (int) $ID;
        if (0 >= $ID) {
            return true;
        }
        $arCatalog = CCatalogSku::GetInfoByIBlock($ID);
        if (empty($arCatalog)) {
            return true;
        }
        if (CCatalogSku::TYPE_CATALOG !== $arCatalog['CATALOG_TYPE']) {
            if (CCatalogSku::TYPE_OFFERS === $arCatalog['CATALOG_TYPE']) {
                $arMsg[] = ['id' => 'IBLOCK_ID', 'text' => Loc::getMessage('BT_MOD_CATALOG_ERR_CANNOT_DELETE_SKU_IBLOCK')];
                $obError = new CAdminException($arMsg);
                $APPLICATION->ThrowException($obError);

                return false;
            }

            $arMsg[] = ['id' => 'PRODUCT_IBLOCK_ID', 'text' => Loc::getMessage('BT_MOD_CATALOG_ERR_CANNOT_DELETE_PRODUCT_IBLOCK')];
            $obError = new CAdminException($arMsg);
            $APPLICATION->ThrowException($obError);

            return false;
        }
        foreach (GetModuleEvents('catalog', 'OnBeforeCatalogDelete', true) as $arEvent) {
            if (false === ExecuteModuleEventEx($arEvent, [$ID])) {
                $strError = Loc::getMessage('BT_MOD_CATALOG_ERR_BEFORE_DEL_TITLE').' '.$arEvent['TO_NAME'];
                if ($ex = $APPLICATION->GetException()) {
                    $strError .= ': '.$ex->GetString();
                }
                $APPLICATION->ThrowException($strError);

                return false;
            }
        }

        return true;
    }

    public static function OnBeforeIBlockPropertyUpdate(array &$fields): bool
    {
        global $APPLICATION;

        $messages = [];
        $id = (int) $fields['ID'];
        if ($id > 0) {
            $changeActive = isset($fields['ACTIVE']) && 'Y' !== $fields['ACTIVE'];
            $changeMultiple = isset($fields['MULTIPLE']) && 'N' !== $fields['MULTIPLE'];
            $changeType = isset($fields['TYPE']) || array_key_exists('USER_TYPE', $fields);
            if (
                $changeActive
                || $changeMultiple
                || $changeType
            ) {
                $iterator = Catalog\CatalogIblockTable::getList([
                    'select' => [
                        'IBLOCK_ID',
                        'PRODUCT_IBLOCK_ID',
                        'SKU_PROPERTY_ID',
                    ],
                    'filter' => ['=SKU_PROPERTY_ID' => $id],
                ]);
                $row = $iterator->fetch();
                unset($iterator);
                if (!empty($row)) {
                    if ($changeActive) {
                        $messages[] = Loc::getMessage(
                            'BT_MOD_CATALOG_ERR_CANNOT_DEACTIVE_SKU_PROPERTY',
                            [
                                '#SKU_PROPERTY_ID#' => $row['SKU_PROPERTY_ID'],
                                '#PRODUCT_IBLOCK_ID#' => $row['PRODUCT_IBLOCK_ID'],
                                '#IBLOCK_ID#' => $row['IBLOCK_ID'],
                            ]
                        );
                    }
                    if ($changeMultiple) {
                        $messages[] = Loc::getMessage(
                            'BT_MOD_CATALOG_ERR_CANNOT_SET_MULTIPLE_SKU_PROPERTY',
                            [
                                '#SKU_PROPERTY_ID#' => $row['SKU_PROPERTY_ID'],
                                '#PRODUCT_IBLOCK_ID#' => $row['PRODUCT_IBLOCK_ID'],
                                '#IBLOCK_ID#' => $row['IBLOCK_ID'],
                            ]
                        );
                    }
                    if ($changeType) {
                        if (
                            (isset($fields['TYPE']) && Iblock\PropertyTable::TYPE_ELEMENT !== $fields['TYPE'])
                            || (array_key_exists('USER_TYPE', $fields) && CIBlockPropertySKU::USER_TYPE !== $fields['USER_TYPE'])
                        ) {
                            $messages[] = Loc::getMessage(
                                'BT_MOD_CATALOG_ERR_CANNOT_CHANGE_TYPE_SKU_PROPERTY',
                                [
                                    '#SKU_PROPERTY_ID#' => $row['SKU_PROPERTY_ID'],
                                    '#PRODUCT_IBLOCK_ID#' => $row['PRODUCT_IBLOCK_ID'],
                                    '#IBLOCK_ID#' => $row['IBLOCK_ID'],
                                ]
                            );
                        }
                    }
                }
                unset($row);
            }
            if (self::isCrmCatalogBrandProperty($id)) {
                $property = CIBlockProperty::GetByID($id)->Fetch();

                if (isset($fields['NAME']) && $fields['NAME'] !== $property['NAME']) {
                    $messages[] = Loc::getMessage('BT_MOD_CATALOG_ERR_CANNOT_CHANGE_BRAND_PROPERTY_NAME');
                } elseif (isset($fields['CODE']) && 'BRAND_FOR_FACEBOOK' !== $fields['CODE']) {
                    $messages[] = Loc::getMessage('BT_MOD_CATALOG_ERR_CANNOT_CHANGE_BRAND_PROPERTY_CODE');
                } elseif (isset($fields['MULTIPLE']) && 'Y' !== $fields['MULTIPLE']) {
                    $messages[] = Loc::getMessage('BT_MOD_CATALOG_ERR_CANNOT_CHANGE_BRAND_PROPERTY_MULTIPLE');
                }
            }
            unset($id);
        }

        if (!empty($messages)) {
            $APPLICATION->ThrowException(implode('. ', $messages));

            return false;
        }

        return true;
    }

    /**
     * @param int $intPropertyID
     */
    public static function OnBeforeIBlockPropertyDelete($intPropertyID): bool
    {
        global $APPLICATION;

        $result = true;
        $intPropertyID = (int) $intPropertyID;
        if ($intPropertyID <= 0) {
            return $result;
        }
        $propertyIterator = Catalog\CatalogIblockTable::getList([
            'select' => ['IBLOCK_ID', 'PRODUCT_IBLOCK_ID', 'SKU_PROPERTY_ID'],
            'filter' => ['=SKU_PROPERTY_ID' => $intPropertyID],
        ]);
        $property = $propertyIterator->fetch();
        unset($propertyIterator);
        if (!empty($property)) {
            $APPLICATION->ThrowException(Loc::getMessage(
                'BT_MOD_CATALOG_ERR_CANNOT_DELETE_SKU_PROPERTY',
                [
                    '#SKU_PROPERTY_ID#' => $property['SKU_PROPERTY_ID'],
                    '#PRODUCT_IBLOCK_ID#' => $property['PRODUCT_IBLOCK_ID'],
                    '#IBLOCK_ID#' => $property['IBLOCK_ID'],
                ]
            ));
            $result = false;
        } elseif (self::isCrmCatalogBrandProperty($intPropertyID)) {
            $APPLICATION->throwException(GetMessage('BT_MOD_CATALOG_ERR_CANNOT_DELETE_BRAND_PROPERTY'));
            $result = false;
        }
        unset($property);

        return $result;
    }

    public static function OnIBlockModuleUnInstall(): bool
    {
        global $APPLICATION;

        $APPLICATION->ThrowException(Loc::getMessage('BT_MOD_CATALOG_ERR_IBLOCK_REQUIRED'));

        return false;
    }

    public static function OnBeforeIBlockUpdate(array &$fields): bool
    {
        if (!self::isEnabledHandler()) {
            return true;
        }
        if (isset($fields['ID'], $fields['ACTIVE'])) {
            $catalog = CCatalogSku::GetInfoByOfferIBlock($fields['ID']);
            if (!empty($catalog)) {
                $parentActive = CIBlock::GetArrayByID($catalog['PRODUCT_IBLOCK_ID'], 'ACTIVE');
                if (!empty($parentActive)) {
                    $fields['ACTIVE'] = $parentActive;
                }
                unset($parentActive);
            }
            unset($catalog);
        }

        return true;
    }

    public static function OnAfterIBlockUpdate(array &$fields): void
    {
        if (!self::isEnabledHandler()) {
            return;
        }
        if (!$fields['RESULT']) {
            return;
        }
        if (!isset($fields['ID']) || !isset($fields['ACTIVE'])) {
            return;
        }

        $catalog = CCatalogSku::GetInfoByProductIBlock($fields['ID']);
        if (!empty($catalog)) {
            self::disableHandler();
            $iblock = new CIBlock();
            $result = $iblock->Update($catalog['IBLOCK_ID'], ['ACTIVE' => $fields['ACTIVE']]);
            unset($result);
            self::enableHandler();
        }
        unset($catalog);
    }

    /**
     * @deprecated deprecated since catalog 14.0.0
     * @see CCatalogSku::GetInfoByIBlock()
     *
     * @param int $ID
     *
     * @return array|false
     */
    public static function GetByIDExt($ID)
    {
        $arResult = CCatalogSku::GetInfoByIBlock($ID);
        if (!empty($arResult)) {
            $arResult['OFFERS_IBLOCK_ID'] = 0;
            $arResult['OFFERS_PROPERTY_ID'] = 0;
            $arResult['OFFERS'] = 'N';
            if (CCatalogSku::TYPE_PRODUCT === $arResult['CATALOG_TYPE'] || CCatalogSku::TYPE_FULL === $arResult['CATALOG_TYPE']) {
                $arResult['OFFERS_IBLOCK_ID'] = $arResult['IBLOCK_ID'];
                $arResult['OFFERS_PROPERTY_ID'] = $arResult['SKU_PROPERTY_ID'];
                $arResult['OFFERS'] = 'Y';
            }
            if (CCatalogSku::TYPE_PRODUCT !== $arResult['CATALOG_TYPE']) {
                $arResult['ID'] = $arResult['IBLOCK_ID'];
                $arResult['IBLOCK_TYPE_ID'] = '';
                $arResult['NAME'] = '';
                $arResult['LID'] = '';
                $arIBlock = CIBlock::GetArrayByID($arResult['IBLOCK_ID']);
                if (is_array($arIBlock)) {
                    $arResult['IBLOCK_TYPE_ID'] = $arIBlock['IBLOCK_TYPE_ID'];
                    $arResult['NAME'] = $arIBlock['NAME'];
                    $arResult['LID'] = $arIBlock['LID'];
                }
            }
        }

        return $arResult;
    }

    public static function UnLinkSKUIBlock($ID): bool
    {
        global $APPLICATION;

        $arMsg = [];
        $boolResult = true;

        $ID = (int) $ID;
        if (0 >= $ID) {
            $arMsg[] = ['id' => 'PRODUCT_IBLOCK_ID', 'text' => Loc::getMessage('BT_MOD_CATALOG_ERR_PRODUCT_ID_INVALID')];
            $boolResult = false;
        }

        if ($boolResult) {
            $rsCatalog = CCatalog::GetList(
                [],
                ['PRODUCT_IBLOCK_ID' => $ID],
                false,
                false,
                ['IBLOCK_ID']
            );
            if ($arCatalog = $rsCatalog->Fetch()) {
                $arCatalog['IBLOCK_ID'] = (int) $arCatalog['IBLOCK_ID'];
                $arFields = [
                    'PRODUCT_IBLOCK_ID' => 0,
                    'SKU_PROPERTY_ID' => 0,
                ];
                if (!CCatalog::Update($arCatalog['IBLOCK_ID'], $arFields)) {
                    return false;
                }
            }
        }
        if (!$boolResult) {
            $obError = new CAdminException($arMsg);
            $APPLICATION->ResetException();
            $APPLICATION->ThrowException($obError);
        } else {
            CCatalogSku::ClearCache();
        }

        return $boolResult;
    }

    /**
     * @deprecated deprecated since catalog 16.0.0
     * @see CIBlockPropertyTools::createProperty
     *
     * @param int $ID    parent iblock id
     * @param int $SKUID offer iblock id
     *
     * @return false|int
     */
    public static function LinkSKUIBlock($ID, $SKUID)
    {
        global $APPLICATION;

        $arMsg = [];
        $boolResult = true;

        $propertyId = 0;
        $ID = (int) $ID;
        if (0 >= $ID) {
            $arMsg[] = ['id' => 'PRODUCT_IBLOCK_ID', 'text' => Loc::getMessage('BT_MOD_CATALOG_ERR_PRODUCT_ID_INVALID')];
            $boolResult = false;
        }
        $SKUID = (int) $SKUID;
        if (0 >= $SKUID) {
            $arMsg[] = ['id' => 'OFFERS_IBLOCK_ID', 'text' => Loc::getMessage('BT_MOD_CATALOG_ERR_OFFERS_ID_INVALID')];
            $boolResult = false;
        }
        if ($ID === $SKUID) {
            $arMsg[] = ['id' => 'OFFERS_IBLOCK_ID', 'text' => Loc::getMessage('BT_MOD_CATALOG_ERR_PRODUCT_ID_SELF')];
            $boolResult = false;
        }

        if ($boolResult) {
            $propertyId = CIBlockPropertyTools::createProperty(
                $SKUID,
                CIBlockPropertyTools::CODE_SKU_LINK,
                ['LINK_IBLOCK_ID' => $ID]
            );
            if (!$propertyId) {
                $arMsg = CIBlockPropertyTools::getErrors();
                $boolResult = false;
            }
        }

        if (!$boolResult) {
            $obError = new CAdminException($arMsg);
            $APPLICATION->ResetException();
            $APPLICATION->ThrowException($obError);

            return false;
        }

        return $propertyId;
    }

    /**
     * @deprecated deprecated since catalog 10.0.3
     *
     * @internal
     */
    public static function GetCatalogFieldsList(): array
    {
        global $DB;
        $arFieldsList = $DB->GetTableFieldsList('b_catalog_iblock');
        $arFieldsList[] = 'CATALOG';
        $arFieldsList[] = 'CATALOG_TYPE';
        $arFieldsList[] = 'OFFERS_IBLOCK_ID';
        $arFieldsList[] = 'OFFERS_PROPERTY_ID';

        return array_unique($arFieldsList);
    }

    public static function IsUserExists(): bool
    {
        global $USER;

        return isset($USER) && $USER instanceof CUser;
    }

    public static function clearCache(): void
    {
        self::$arCatalogCache = [];
        self::$catalogVatCache = [];
    }

    private static function isCrmCatalogBrandProperty($propertyId): bool
    {
        if (
            !Loader::includeModule('crm')
            || !Loader::includeModule('bitrix24')
        ) {
            return false;
        }

        $crmCatalogId = CCrmCatalog::GetDefaultID();
        $property = CIBlockProperty::GetByID($propertyId)->Fetch();

        return 'BRAND_FOR_FACEBOOK' === $property['CODE'] && (int) $property['IBLOCK_ID'] === $crmCatalogId;
    }

    private static function disableHandler(): void
    {
        --self::$disableCheckIblock;
    }

    private static function enableHandler(): void
    {
        ++self::$disableCheckIblock;
    }

    private static function isEnabledHandler(): bool
    {
        return self::$disableCheckIblock >= 0;
    }
}
