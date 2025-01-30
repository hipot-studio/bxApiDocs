<?php

use Bitrix\Catalog;
use Bitrix\Main;

require_once $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/catalog/general/discount.php';

class discount extends CAllCatalogDiscount
{
    /**
     * @param array      $arOrder
     * @param array      $arFilter
     * @param array|bool $arGroupBy
     * @param array|bool $arNavStartParams
     * @param array      $arSelectFields
     *
     * @return bool|CDBResult
     */
    protected static function __GetDiscountEntityList($arOrder = [], $arFilter = [], $arGroupBy = false, $arNavStartParams = false, $arSelectFields = [])
    {
        global $DB;

        $arFields = [
            'ID' => ['FIELD' => 'DC.ID', 'TYPE' => 'int'],
            'DISCOUNT_ID' => ['FIELD' => 'DC.DISCOUNT_ID', 'TYPE' => 'int'],
            'CATALOG_GROUP_ID' => ['FIELD' => 'DC.PRICE_TYPE_ID', 'TYPE' => 'int'],
            'PRICE_TYPE_ID' => ['FIELD' => 'DC.PRICE_TYPE_ID', 'TYPE' => 'int'],
            'USER_GROUP_ID' => ['FIELD' => 'DC.USER_GROUP_ID', 'TYPE' => 'int'],
            'GROUP_ID' => ['FIELD' => 'DC.USER_GROUP_ID', 'TYPE' => 'int'],
        ];

        $arSqls = CCatalog::PrepareSql($arFields, $arOrder, $arFilter, $arGroupBy, $arSelectFields);

        $arSqls['SELECT'] = str_replace('%%_DISTINCT_%%', '', $arSqls['SELECT']);

        if (empty($arGroupBy) && is_array($arGroupBy)) {
            $strSql = 'SELECT '.$arSqls['SELECT'].' FROM b_catalog_discount_cond DC '.$arSqls['FROM'];
            if (!empty($arSqls['WHERE'])) {
                $strSql .= ' WHERE '.$arSqls['WHERE'];
            }
            if (!empty($arSqls['GROUPBY'])) {
                $strSql .= ' GROUP BY '.$arSqls['GROUPBY'];
            }

            $dbRes = $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
            if ($arRes = $dbRes->Fetch()) {
                return $arRes['CNT'];
            }

            return false;
        }

        $strSql = 'SELECT '.$arSqls['SELECT'].' FROM b_catalog_discount_cond DC '.$arSqls['FROM'];
        if (!empty($arSqls['WHERE'])) {
            $strSql .= ' WHERE '.$arSqls['WHERE'];
        }
        if (!empty($arSqls['GROUPBY'])) {
            $strSql .= ' GROUP BY '.$arSqls['GROUPBY'];
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
            $strSql_tmp = "SELECT COUNT('x') as CNT FROM b_catalog_discount_cond DC ".$arSqls['FROM'];
            if (!empty($arSqls['WHERE'])) {
                $strSql_tmp .= ' WHERE '.$arSqls['WHERE'];
            }
            if (!empty($arSqls['GROUPBY'])) {
                $strSql_tmp .= ' GROUP BY '.$arSqls['GROUPBY'];
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
     * @deprecated deprecated since catalog 14.5.6
     *
     * @param array $arParams
     */
    protected static function __SaveFilterForEntity($arParams)
    {
        global $DB;

        if (!is_array($arParams) || empty($arParams)) {
            return;
        }
        $strFilter = 'N';
        $arDiscList = [];
        $strQuery = str_replace('#ENTITY_ID#', $arParams['ENTITY_ID'], 'SELECT DISCOUNT_ID FROM b_catalog_discount_cond WHERE #ENTITY_ID# != -1');
        $rsDiscounts = $DB->Query($strQuery, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
        while ($arDiscount = $rsDiscounts->Fetch()) {
            $arDiscList[] = (int) $arDiscount['DISCOUNT_ID'];
        }
        if (!empty($arDiscList)) {
            $arDiscList = array_unique($arDiscList);
            $strQuery = "SELECT 'x' FROM b_catalog_discount D WHERE ID IN (".implode(',', $arDiscList).") AND D.ACTIVE = 'Y' AND (D.ACTIVE_TO > ".$DB->CurrentTimeFunction().' OR D.ACTIVE_TO IS NULL) LIMIT 0, 1';
            $rsDiscounts = $DB->Query($strQuery, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
            if ($arDiscount = $rsDiscounts->Fetch()) {
                $strFilter = 'Y';
            }
        }
        COption::SetOptionString('catalog', $arParams['OPTION_ID'], $strFilter);
    }

    protected static function __UpdateSubdiscount($intDiscountID, &$arConditions, $active = '')
    {
        global $DB;

        $arMsg = [];
        $boolResult = true;

        $intDiscountID = (int) $intDiscountID;
        if ($intDiscountID <= 0) {
            $arMsg[] = ['id' => 'ID', 'text' => GetMessage('BT_MOD_CATALOG_DISC_ERR_DISCOUNT_ID_ABSENT')];
            $boolResult = false;
        }
        if (empty($arConditions) || !is_array($arConditions)) {
            $arMsg[] = ['id' => 'SUBDISCOUNT', 'text' => GetMessage('BT_MOD_CATALOG_DISC_ERR_SUBDISCOUNT_ROWS_ABSENT')];
            $boolResult = false;
        }

        $active = (string) $active;
        if ('Y' !== $active && 'N' !== $active) {
            $strQuery = 'select ID, ACTIVE from b_catalog_discount where ID = '.$intDiscountID;
            $rsActive = $DB->Query($strQuery, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
            if ($activeFromDatabase = $rsActive->Fetch()) {
                $active = $activeFromDatabase['ACTIVE'];
            } else {
                $arMsg[] = ['id' => 'ID', 'text' => GetMessage('BT_MOD_CATALOG_DISC_ERR_DISCOUNT_ID_ABSENT')];
                $boolResult = false;
            }
        }

        $arEmptyRow = [
            'DISCOUNT_ID' => $intDiscountID,
            'ACTIVE' => $active,
            'USER_GROUP_ID' => -1,
            'PRICE_TYPE_ID' => -1,
        ];

        if ($boolResult) {
            $strQuery = 'DELETE from b_catalog_discount_cond where DISCOUNT_ID = '.$intDiscountID;
            $DB->Query($strQuery, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);

            foreach ($arConditions as $arOneCondition) {
                $arRow = $arEmptyRow;
                if (!empty($arOneCondition['EQUAL']) && is_array($arOneCondition['EQUAL'])) {
                    foreach ($arOneCondition['EQUAL'] as $strKey => $intOneEntity) {
                        $arRow[$strKey] = $intOneEntity;
                    }
                }
                $arInsert = $DB->PrepareInsert('b_catalog_discount_cond', $arRow);
                $strInserCond = 'INSERT INTO b_catalog_discount_cond('.$arInsert[0].') VALUES('.$arInsert[1].')';
                $DB->Query($strInserCond, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
            }
            if (isset($arOneCondition)) {
                unset($arOneCondition);
            }
        }

        return $boolResult;
    }

    protected static function __GetDiscountID($arFilter)
    {
        global $DB;

        $arResult = [];
        $boolRest = array_key_exists('RESTRICTIONS', $arFilter);

        $arFields = [
            'DISCOUNT_ID' => ['FIELD' => 'DC.DISCOUNT_ID', 'TYPE' => 'int'],
            'ACTIVE' => ['FIELD' => 'DC.ACTIVE', 'TYPE' => 'char'],
            'USER_GROUP_ID' => ['FIELD' => 'DC.USER_GROUP_ID', 'TYPE' => 'int'],
            'PRICE_TYPE_ID' => ['FIELD' => 'DC.PRICE_TYPE_ID', 'TYPE' => 'int'],
        ];

        if (!isset($arFilter['USER_GROUP_ID'])) {
            $arFilter['USER_GROUP_ID'] = [];
        } elseif (!is_array($arFilter['USER_GROUP_ID'])) {
            $arFilter['USER_GROUP_ID'] = [$arFilter['USER_GROUP_ID']];
        }
        if (!empty($arFilter['USER_GROUP_ID'])) {
            if (!in_array(-1, $arFilter['USER_GROUP_ID'], true)) {
                $arFilter['USER_GROUP_ID'][] = -1;
            }
        } else {
            unset($arFilter['USER_GROUP_ID']);
        }

        if (!isset($arFilter['PRICE_TYPE_ID'])) {
            $arFilter['PRICE_TYPE_ID'] = [];
        } elseif (!is_array($arFilter['PRICE_TYPE_ID'])) {
            $arFilter['PRICE_TYPE_ID'] = [$arFilter['PRICE_TYPE_ID']];
        }
        if (!empty($arFilter['PRICE_TYPE_ID'])) {
            if (!in_array(-1, $arFilter['PRICE_TYPE_ID'], true)) {
                $arFilter['PRICE_TYPE_ID'][] = -1;
            }
        } else {
            unset($arFilter['PRICE_TYPE_ID']);
        }

        $active = 'Y';
        if (array_key_exists('ACTIVE', $arFilter)) {
            if (null === $arFilter['ACTIVE']) {
                $active = '';
            } elseif ('Y' === $arFilter['ACTIVE'] || 'N' === $arFilter['ACTIVE']) {
                $active = $arFilter['ACTIVE'];
            }
            unset($arFilter['ACTIVE']);
        }
        if ('' !== $active) {
            $arFilter['ACTIVE'] = $active;
        }

        if (array_key_exists('DISCOUNT_ID', $arFilter)) {
            unset($arFilter['DISCOUNT_ID']);
        }

        $arSelectFields = ['DISCOUNT_ID'];
        if ($boolRest) {
            $arSelectFields[] = 'USER_GROUP_ID';
            $arSelectFields[] = 'PRICE_TYPE_ID';
            unset($arFilter['RESTRICTIONS']);
        }

        $arSqls = CCatalog::PrepareSql($arFields, ['DISCOUNT_ID' => 'ASC'], $arFilter, false, $arSelectFields);

        $arSqls['SELECT'] = str_replace('%%_DISTINCT_%%', '', $arSqls['SELECT']);
        if (empty($arSqls['WHERE'])) {
            $arSqls['WHERE'] = '1=1';
        }

        $strQuery = 'SELECT '.$arSqls['SELECT'].' FROM b_catalog_discount_cond DC WHERE '.$arSqls['WHERE'];

        $arDiscountID = [];
        $rsDiscounts = $DB->Query($strQuery, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
        if ($boolRest) {
            $arRestrictions = [];
            while ($arDiscount = $rsDiscounts->Fetch()) {
                $arDiscount['DISCOUNT_ID'] = (int) $arDiscount['DISCOUNT_ID'];
                $arDiscountID[$arDiscount['DISCOUNT_ID']] = true;
                if (!isset($arRestrictions[$arDiscount['DISCOUNT_ID']])) {
                    $arRestrictions[$arDiscount['DISCOUNT_ID']] = [
                        'USER_GROUP' => [],
                        'PRICE_TYPE' => [],
                    ];
                }
                $arDiscount['USER_GROUP_ID'] = (int) $arDiscount['USER_GROUP_ID'];
                $arDiscount['PRICE_TYPE_ID'] = (int) $arDiscount['PRICE_TYPE_ID'];
                $arRestrictions[$arDiscount['DISCOUNT_ID']]['USER_GROUP'][$arDiscount['USER_GROUP_ID']] = true;
                $arRestrictions[$arDiscount['DISCOUNT_ID']]['PRICE_TYPE'][$arDiscount['PRICE_TYPE_ID']] = true;
            }
            if (!empty($arDiscountID)) {
                $arDiscountID = array_keys($arDiscountID);
                foreach ($arRestrictions as $intKey => $arOneRestrictions) {
                    if (array_key_exists(-1, $arOneRestrictions['USER_GROUP'])) {
                        $arOneRestrictions['USER_GROUP'] = [];
                    }
                    if (array_key_exists(-1, $arOneRestrictions['PRICE_TYPE'])) {
                        $arOneRestrictions['PRICE_TYPE'] = [];
                    }
                    $arRestrictions[$intKey] = $arOneRestrictions;
                }
            }
            $arResult = [
                'DISCOUNTS' => $arDiscountID,
                'RESTRICTIONS' => $arRestrictions,
            ];
        } else {
            while ($arDiscount = $rsDiscounts->Fetch()) {
                $arDiscount['DISCOUNT_ID'] = (int) $arDiscount['DISCOUNT_ID'];
                $arDiscountID[$arDiscount['DISCOUNT_ID']] = true;
            }
            if (!empty($arDiscountID)) {
                $arResult = array_keys($arDiscountID);
            }
        }

        return $arResult;
    }

    protected static function __UpdateOldEntities($ID, &$arFields, $boolUpdate)
    {
        $ID = (int) $ID;
        if (0 >= $ID) {
            return;
        }
        CCatalogDiscount::__UpdateOldOneEntity(
            $ID,
            $arFields,
            [
                'ENTITY_ID' => 'IBLOCK_IDS',
                'TABLE_ID' => 'b_catalog_discount2iblock',
                'FIELD_ID' => 'IBLOCK_ID',
            ],
            $boolUpdate
        );
        CCatalogDiscount::__UpdateOldOneEntity(
            $ID,
            $arFields,
            [
                'ENTITY_ID' => 'SECTION_IDS',
                'TABLE_ID' => 'b_catalog_discount2section',
                'FIELD_ID' => 'SECTION_ID',
            ],
            $boolUpdate
        );
        CCatalogDiscount::__UpdateOldOneEntity(
            $ID,
            $arFields,
            [
                'ENTITY_ID' => 'PRODUCT_IDS',
                'TABLE_ID' => 'b_catalog_discount2product',
                'FIELD_ID' => 'PRODUCT_ID',
            ],
            $boolUpdate
        );
    }

    protected static function __FillArrays($intDiscountID, &$arFields, $strEntityID)
    {
        $boolResult = false;
        $intDiscountID = (int) $intDiscountID;
        if (0 >= $intDiscountID) {
            return $boolResult;
        }
        $strEntityID = trim((string) $strEntityID);
        if (!empty($strEntityID) && ('GROUP_IDS' === $strEntityID || 'CATALOG_GROUP_IDS' === $strEntityID)) {
            $boolCheck = false;
            $strEntityResult = ('GROUP_IDS' === $strEntityID ? 'USER_GROUP_ID' : 'PRICE_TYPE_ID');
            $arValues = [];
            $rsDiscounts = self::__GetDiscountEntityList(
                [],
                ['DISCOUNT_ID' => $intDiscountID],
                false,
                false,
                ['ID', 'DISCOUNT_ID', $strEntityResult]
            );
            while ($arDiscount = $rsDiscounts->Fetch()) {
                $boolCheck = true;
                $intValue = (int) $arDiscount[$strEntityResult];
                if (0 < $intValue) {
                    $arValues[$intValue] = true;
                }
            }
            if ($boolCheck) {
                $arFields[$strEntityID] = (!empty($arValues) ? array_keys($arValues) : []);
                $boolResult = true;
            }
        }

        return $boolResult;
    }

    public static function _Add(&$arFields)
    {
        global $DB;

        if (!CCatalogDiscount::CheckFields('ADD', $arFields, 0)) {
            return false;
        }

        $arInsert = $DB->PrepareInsert('b_catalog_discount', $arFields);

        $strSql = 'INSERT INTO b_catalog_discount('.$arInsert[0].') VALUES('.$arInsert[1].')';
        $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);

        $ID = (int) $DB->LastID();

        if (isset($arFields['HANDLERS'])) {
            self::updateDiscountHandlers($ID, $arFields['HANDLERS'], false);
        }

        return $ID;
    }

    public static function _Update($ID, &$arFields)
    {
        global $DB;
        global $APPLICATION;

        $ID = (int) $ID;
        if ($ID <= 0) {
            return false;
        }

        if (!CCatalogDiscount::CheckFields('UPDATE', $arFields, $ID)) {
            return false;
        }

        if (isset($arFields['VALUE']) !== isset($arFields['VALUE_TYPE'])) {
            $rsDiscounts = CCatalogDiscount::GetList([], ['ID' => $ID], false, ['nTopCount' => 1], ['ID', 'VALUE', 'VALUE_TYPE']);
            if ($arDiscount = $rsDiscounts->Fetch()) {
                if (!isset($arFields['VALUE'])) {
                    $arFields['VALUE'] = (float) $arDiscount['VALUE'];
                }
                if (!isset($arFields['VALUE_TYPE'])) {
                    $arFields['VALUE_TYPE'] = $arDiscount['VALUE_TYPE'];
                }
                if (self::TYPE_PERCENT === $arFields['VALUE_TYPE'] && 100 < $arFields['VALUE']) {
                    $APPLICATION->ThrowException(GetMessage('BT_MOD_CATALOG_DISC_ERR_BAD_VALUE'), 'VALUE');

                    return false;
                }
            } else {
                $APPLICATION->ThrowException(str_replace('#ID#', $ID, GetMessage('BT_MOD_CATALOG_DISC_ERR_BAD_ID')), 'ID');

                return false;
            }
        }

        $strUpdate = $DB->PrepareUpdate('b_catalog_discount', $arFields);
        if (!empty($strUpdate)) {
            $strSql = 'UPDATE b_catalog_discount SET '.$strUpdate.' WHERE ID = '.$ID.' AND TYPE = '.self::ENTITY_ID;
            $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);

            if (isset($arFields['HANDLERS'])) {
                self::updateDiscountHandlers($ID, $arFields['HANDLERS'], true);
            }
        }

        return $ID;
    }

    public static function Delete($ID)
    {
        global $DB;

        $ID = (int) $ID;
        if ($ID <= 0) {
            return false;
        }

        foreach (GetModuleEvents('catalog', 'OnBeforeDiscountDelete', true) as $arEvent) {
            if (false === ExecuteModuleEventEx($arEvent, [$ID])) {
                return false;
            }
        }

        $DB->Query('delete from b_catalog_discount2iblock where DISCOUNT_ID = '.$ID);
        $DB->Query('delete from b_catalog_discount2section where DISCOUNT_ID = '.$ID);
        $DB->Query('delete from b_catalog_discount2product where DISCOUNT_ID = '.$ID);
        Catalog\DiscountRestrictionTable::deleteByDiscount($ID);
        Catalog\DiscountModuleTable::deleteByDiscount($ID);
        Catalog\DiscountEntityTable::deleteByDiscount($ID);
        Catalog\DiscountCouponTable::deleteByDiscount($ID);

        $DB->Query('delete from b_catalog_discount where ID = '.$ID.' and TYPE = '.self::ENTITY_ID);

        foreach (GetModuleEvents('catalog', 'OnDiscountDelete', true) as $arEvent) {
            ExecuteModuleEventEx($arEvent, [$ID]);
        }

        return true;
    }

    /**
     * @param int $ID
     *
     * @return array|bool
     */
    public static function GetByID($ID)
    {
        global $DB;

        $ID = (int) $ID;
        if ($ID <= 0) {
            return false;
        }

        $strSql =
            'SELECT CD.ID, CD.SITE_ID, CD.ACTIVE, CD.NAME, CD.MAX_USES, '.
            'CD.COUNT_USES, CD.COUPON, CD.SORT, CD.MAX_DISCOUNT, CD.VALUE_TYPE, '.
            'CD.VALUE, CD.CURRENCY, CD.MIN_ORDER_SUM, CD.NOTES, CD.RENEWAL, '.
            $DB->DateToCharFunction('CD.TIMESTAMP_X', 'FULL').' as TIMESTAMP_X, '.
            $DB->DateToCharFunction('CD.ACTIVE_FROM', 'FULL').' as ACTIVE_FROM, '.
            $DB->DateToCharFunction('CD.ACTIVE_TO', 'FULL').' as ACTIVE_TO, '.
            'CD.CREATED_BY, CD.MODIFIED_BY, '.$DB->DateToCharFunction('CD.DATE_CREATE', 'FULL').' as DATE_CREATE, '.
            'CD.PRIORITY, CD.LAST_DISCOUNT, CD.VERSION, CD.CONDITIONS, CD.UNPACK, CD.SALE_ID '.
            'FROM b_catalog_discount CD WHERE CD.ID = '.$ID.' AND CD.TYPE = '.self::ENTITY_ID;

        $db_res = $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
        if ($res = $db_res->Fetch()) {
            return $res;
        }

        return false;
    }

    /**
     * @param mixed  $val
     * @param mixed  $key
     * @param string $operation
     * @param string $negative
     * @param string $field
     * @param array  $arField
     * @param array  $arFilter
     *
     * @return bool|string
     *
     * @noinspection PhpUnusedParameterInspection
     */
    public static function PrepareSection4Where($val, $key, $operation, $negative, $field, $arField, $arFilter)
    {
        $val = (int) $val;
        if ($val <= 0) {
            return false;
        }

        $dbSection = CIBlockSection::GetByID($val);
        if ($arSection = $dbSection->Fetch()) {
            $arIDs = [0];
            $dbSectionTree = CIBlockSection::GetList(
                ['LEFT_MARGIN' => 'DESC'],
                [
                    'IBLOCK_ID' => $arSection['IBLOCK_ID'],
                    'ACTIVE' => 'Y',
                    'GLOBAL_ACTIVE' => 'Y',
                    'IBLOCK_ACTIVE' => 'Y',
                    '>=LEFT_BORDER' => $arSection['LEFT_MARGIN'],
                    '<=RIGHT_BORDER' => $arSection['RIGHT_MARGIN'],
                ]
            );
            while ($arSectionTree = $dbSectionTree->Fetch()) {
                $arIDs[] = (int) $arSectionTree['ID'];
            }

            return '(CDS.SECTION_ID '.(('Y' === $negative) ? 'NOT ' : '').'IN ('.implode(',', $arIDs).'))';
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
        global $DB;

        $arFields = [
            'ID' => ['FIELD' => 'CD.ID', 'TYPE' => 'int'],
            'XML_ID' => ['FIELD' => 'CD.XML_ID', 'TYPE' => 'string'],
            'SITE_ID' => ['FIELD' => 'CD.SITE_ID', 'TYPE' => 'string'],
            'TYPE' => ['FIELD' => 'CD.TYPE', 'TYPE' => 'int'],
            'ACTIVE' => ['FIELD' => 'CD.ACTIVE', 'TYPE' => 'char'],
            'ACTIVE_FROM' => ['FIELD' => 'CD.ACTIVE_FROM', 'TYPE' => 'datetime'],
            'ACTIVE_TO' => ['FIELD' => 'CD.ACTIVE_TO', 'TYPE' => 'datetime'],
            'RENEWAL' => ['FIELD' => 'CD.RENEWAL', 'TYPE' => 'char'],
            'NAME' => ['FIELD' => 'CD.NAME', 'TYPE' => 'string'],
            'MAX_USES' => ['FIELD' => 'CD.MAX_USES', 'TYPE' => 'int'],
            'COUNT_USES' => ['FIELD' => 'CD.COUNT_USES', 'TYPE' => 'int'],
            'SORT' => ['FIELD' => 'CD.SORT', 'TYPE' => 'int'],
            'MAX_DISCOUNT' => ['FIELD' => 'CD.MAX_DISCOUNT', 'TYPE' => 'double'],
            'VALUE_TYPE' => ['FIELD' => 'CD.VALUE_TYPE', 'TYPE' => 'char'],
            'VALUE' => ['FIELD' => 'CD.VALUE', 'TYPE' => 'double'],
            'CURRENCY' => ['FIELD' => 'CD.CURRENCY', 'TYPE' => 'string'],
            'MIN_ORDER_SUM' => ['FIELD' => 'CD.MIN_ORDER_SUM', 'TYPE' => 'double'],
            'TIMESTAMP_X' => ['FIELD' => 'CD.TIMESTAMP_X', 'TYPE' => 'datetime'],
            'MODIFIED_BY' => ['FIELD' => 'CD.MODIFIED_BY', 'TYPE' => 'int'],
            'DATE_CREATE' => ['FIELD' => 'CD.DATE_CREATE', 'TYPE' => 'datetime'],
            'CREATED_BY' => ['FIELD' => 'CD.CREATED_BY', 'TYPE' => 'int'],
            'NOTES' => ['FIELD' => 'CD.NOTES', 'TYPE' => 'string'],
            'PRIORITY' => ['FIELD' => 'CD.PRIORITY', 'TYPE' => 'int'],
            'LAST_DISCOUNT' => ['FIELD' => 'CD.LAST_DISCOUNT', 'TYPE' => 'char'],
            'VERSION' => ['FIELD' => 'CD.VERSION', 'TYPE' => 'int'],
            'CONDITIONS' => ['FIELD' => 'CD.CONDITIONS', 'TYPE' => 'string'],
            'UNPACK' => ['FIELD' => 'CD.UNPACK', 'TYPE' => 'string'],
            'SALE_ID' => ['FIELD' => 'CD.SALE_ID', 'TYPE' => 'int'],
            'USE_COUPONS' => ['FIELD' => 'CD.USE_COUPONS', 'TYPE' => 'char'],

            'PRODUCT_ID' => ['FIELD' => 'CDP.PRODUCT_ID', 'TYPE' => 'int', 'FROM' => 'LEFT JOIN b_catalog_discount2product CDP ON (CD.ID = CDP.DISCOUNT_ID)'],
            'SECTION_ID' => ['FIELD' => 'CDS.SECTION_ID', 'TYPE' => 'int', 'FROM' => 'LEFT JOIN b_catalog_discount2section CDS ON (CD.ID = CDS.DISCOUNT_ID)', 'WHERE' => ['CCatalogDiscount', 'PrepareSection4Where']],
            'SECTION_LIST' => ['FIELD' => 'CDSL.SECTION_ID', 'TYPE' => 'int', 'FROM' => 'LEFT JOIN b_catalog_discount2section CDSL ON (CD.ID = CDSL.DISCOUNT_ID)'],
            'IBLOCK_ID' => ['FIELD' => 'CDI.IBLOCK_ID', 'TYPE' => 'int', 'FROM' => 'LEFT JOIN b_catalog_discount2iblock CDI ON (CD.ID = CDI.DISCOUNT_ID)'],
            'GROUP_ID' => ['FIELD' => 'CDC.USER_GROUP_ID', 'TYPE' => 'int', 'FROM' => 'LEFT JOIN b_catalog_discount_cond CDC ON (CD.ID = CDC.DISCOUNT_ID)'],
            'USER_GROUP_ID' => ['FIELD' => 'CDC.USER_GROUP_ID', 'TYPE' => 'int', 'FROM' => 'LEFT JOIN b_catalog_discount_cond CDC ON (CD.ID = CDC.DISCOUNT_ID)'],
            'CATALOG_GROUP_ID' => ['FIELD' => 'CDC.PRICE_TYPE_ID', 'TYPE' => 'int', 'FROM' => 'LEFT JOIN b_catalog_discount_cond CDC ON (CD.ID = CDC.DISCOUNT_ID)'],
            'PRICE_TYPE_ID' => ['FIELD' => 'CDC.PRICE_TYPE_ID', 'TYPE' => 'int', 'FROM' => 'LEFT JOIN b_catalog_discount_cond CDC ON (CD.ID = CDC.DISCOUNT_ID)'],

            'COUPON' => ['FIELD' => 'CDCP.COUPON', 'TYPE' => 'string', 'FROM' => 'LEFT JOIN b_catalog_discount_coupon CDCP ON (CD.ID = CDCP.DISCOUNT_ID)'],
            'COUPON_ACTIVE' => ['FIELD' => 'CDCP.ACTIVE', 'TYPE' => 'char', 'FROM' => 'LEFT JOIN b_catalog_discount_coupon CDCP ON (CD.ID = CDCP.DISCOUNT_ID)'],
            'COUPON_ONE_TIME' => ['FIELD' => 'CDCP.ONE_TIME', 'TYPE' => 'char', 'FROM' => 'LEFT JOIN b_catalog_discount_coupon CDCP ON (CD.ID = CDCP.DISCOUNT_ID)'],
        ];

        if (!is_array($arFilter)) {
            $arFilter = [];
        }
        if (!empty($arFilter)) {
            $filterKeys = array_keys($arFilter);
            foreach ($filterKeys as &$oneKey) {
                if (
                    1 === preg_match('/^\!?\+.{0,2}(GROUP_ID|USER_GROUP_ID|CATALOG_GROUP_ID|PRICE_TYPE_ID)$/', $oneKey)
                ) {
                    if (is_array($arFilter[$oneKey])) {
                        $arFilter[$oneKey][] = -1;
                    } else {
                        $arFilter[$oneKey] = [$arFilter[$oneKey], -1];
                    }
                }
            }
            unset($oneKey, $filterKeys);
        }
        $arFilter['TYPE'] = self::ENTITY_ID;

        $arSqls = CCatalog::PrepareSql($arFields, $arOrder, $arFilter, $arGroupBy, $arSelectFields);

        $arSqls['SELECT'] = str_replace('%%_DISTINCT_%%', '', $arSqls['SELECT']);

        if (empty($arGroupBy) && is_array($arGroupBy)) {
            $strSql = 'SELECT '.$arSqls['SELECT'].' FROM b_catalog_discount CD '.$arSqls['FROM'];
            if (!empty($arSqls['WHERE'])) {
                $strSql .= ' WHERE '.$arSqls['WHERE'];
            }
            if (!empty($arSqls['GROUPBY'])) {
                $strSql .= ' GROUP BY '.$arSqls['GROUPBY'];
            }

            $dbRes = $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
            if ($arRes = $dbRes->Fetch()) {
                return $arRes['CNT'];
            }

            return false;
        }

        $strSql = 'SELECT '.$arSqls['SELECT'].' FROM b_catalog_discount CD '.$arSqls['FROM'];
        if (!empty($arSqls['WHERE'])) {
            $strSql .= ' WHERE '.$arSqls['WHERE'];
        }
        if (!empty($arSqls['GROUPBY'])) {
            $strSql .= ' GROUP BY '.$arSqls['GROUPBY'];
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
            $strSql_tmp = "SELECT COUNT('x') as CNT FROM b_catalog_discount CD ".$arSqls['FROM'];
            if (!empty($arSqls['WHERE'])) {
                $strSql_tmp .= ' WHERE '.$arSqls['WHERE'];
            }
            if (!empty($arSqls['GROUPBY'])) {
                $strSql_tmp .= ' GROUP BY '.$arSqls['GROUPBY'];
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
    public static function GetDiscountGroupsList($arOrder = [], $arFilter = [], $arGroupBy = false, $arNavStartParams = false, $arSelectFields = [])
    {
        return self::__GetDiscountEntityList($arOrder, $arFilter, $arGroupBy, $arNavStartParams, $arSelectFields);
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
    public static function GetDiscountCatsList($arOrder = [], $arFilter = [], $arGroupBy = false, $arNavStartParams = false, $arSelectFields = [])
    {
        return self::__GetDiscountEntityList($arOrder, $arFilter, $arGroupBy, $arNavStartParams, $arSelectFields);
    }

    /**
     * @deprecated deprecated since catalog 12.0.0
     *
     * @param array      $arOrder
     * @param array      $arFilter
     * @param array|bool $arGroupBy
     * @param array|bool $arNavStartParams
     * @param array      $arSelectFields
     *
     * @return bool|CDBResult
     */
    public static function GetDiscountProductsList($arOrder = [], $arFilter = [], $arGroupBy = false, $arNavStartParams = false, $arSelectFields = [])
    {
        global $DB;

        $arFields = [
            'ID' => ['FIELD' => 'DG.ID', 'TYPE' => 'int'],
            'DISCOUNT_ID' => ['FIELD' => 'DG.DISCOUNT_ID', 'TYPE' => 'int'],
            'PRODUCT_ID' => ['FIELD' => 'DG.PRODUCT_ID', 'TYPE' => 'int'],
        ];

        $arSqls = CCatalog::PrepareSql($arFields, $arOrder, $arFilter, $arGroupBy, $arSelectFields);

        $arSqls['SELECT'] = str_replace('%%_DISTINCT_%%', '', $arSqls['SELECT']);

        if (empty($arGroupBy) && is_array($arGroupBy)) {
            $strSql = 'SELECT '.$arSqls['SELECT'].' FROM b_catalog_discount2product DG '.$arSqls['FROM'];
            if (!empty($arSqls['WHERE'])) {
                $strSql .= ' WHERE '.$arSqls['WHERE'];
            }
            if (!empty($arSqls['GROUPBY'])) {
                $strSql .= ' GROUP BY '.$arSqls['GROUPBY'];
            }

            $dbRes = $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
            if ($arRes = $dbRes->Fetch()) {
                return $arRes['CNT'];
            }

            return false;
        }

        $strSql = 'SELECT '.$arSqls['SELECT'].' FROM b_catalog_discount2product DG '.$arSqls['FROM'];
        if (!empty($arSqls['WHERE'])) {
            $strSql .= ' WHERE '.$arSqls['WHERE'];
        }
        if (!empty($arSqls['GROUPBY'])) {
            $strSql .= ' GROUP BY '.$arSqls['GROUPBY'];
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
            $strSql_tmp = "SELECT COUNT('x') as CNT FROM b_catalog_discount2product DG ".$arSqls['FROM'];
            if (!empty($arSqls['WHERE'])) {
                $strSql_tmp .= ' WHERE '.$arSqls['WHERE'];
            }
            if (!empty($arSqls['GROUPBY'])) {
                $strSql_tmp .= ' GROUP BY '.$arSqls['GROUPBY'];
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
     * @deprecated deprecated since catalog 12.0.0
     *
     * @param array      $arOrder
     * @param array      $arFilter
     * @param array|bool $arGroupBy
     * @param array|bool $arNavStartParams
     * @param array      $arSelectFields
     *
     * @return bool|CDBResult
     */
    public static function GetDiscountSectionsList($arOrder = [], $arFilter = [], $arGroupBy = false, $arNavStartParams = false, $arSelectFields = [])
    {
        global $DB;

        $arFields = [
            'ID' => ['FIELD' => 'DG.ID', 'TYPE' => 'int'],
            'DISCOUNT_ID' => ['FIELD' => 'DG.DISCOUNT_ID', 'TYPE' => 'int'],
            'SECTION_ID' => ['FIELD' => 'DG.SECTION_ID', 'TYPE' => 'int'],
        ];

        $arSqls = CCatalog::PrepareSql($arFields, $arOrder, $arFilter, $arGroupBy, $arSelectFields);

        $arSqls['SELECT'] = str_replace('%%_DISTINCT_%%', '', $arSqls['SELECT']);

        if (empty($arGroupBy) && is_array($arGroupBy)) {
            $strSql = 'SELECT '.$arSqls['SELECT'].' FROM b_catalog_discount2section DG '.$arSqls['FROM'];
            if (!empty($arSqls['WHERE'])) {
                $strSql .= ' WHERE '.$arSqls['WHERE'];
            }
            if (!empty($arSqls['GROUPBY'])) {
                $strSql .= ' GROUP BY '.$arSqls['GROUPBY'];
            }

            $dbRes = $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
            if ($arRes = $dbRes->Fetch()) {
                return $arRes['CNT'];
            }

            return false;
        }

        $strSql = 'SELECT '.$arSqls['SELECT'].' FROM b_catalog_discount2section DG '.$arSqls['FROM'];
        if (!empty($arSqls['WHERE'])) {
            $strSql .= ' WHERE '.$arSqls['WHERE'];
        }
        if (!empty($arSqls['GROUPBY'])) {
            $strSql .= ' GROUP BY '.$arSqls['GROUPBY'];
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
            $strSql_tmp = "SELECT COUNT('x') as CNT FROM b_catalog_discount2section DG ".$arSqls['FROM'];
            if (!empty($arSqls['WHERE'])) {
                $strSql_tmp .= ' WHERE '.$arSqls['WHERE'];
            }
            if (!empty($arSqls['GROUPBY'])) {
                $strSql_tmp .= ' GROUP BY '.$arSqls['GROUPBY'];
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
     * @deprecated deprecated since catalog 12.0.0
     *
     * @param array      $arOrder
     * @param array      $arFilter
     * @param array|bool $arGroupBy
     * @param array|bool $arNavStartParams
     * @param array      $arSelectFields
     *
     * @return bool|CDBResult
     */
    public static function GetDiscountIBlocksList($arOrder = [], $arFilter = [], $arGroupBy = false, $arNavStartParams = false, $arSelectFields = [])
    {
        global $DB;

        $arFields = [
            'ID' => ['FIELD' => 'DG.ID', 'TYPE' => 'int'],
            'DISCOUNT_ID' => ['FIELD' => 'DG.DISCOUNT_ID', 'TYPE' => 'int'],
            'IBLOCK_ID' => ['FIELD' => 'DG.IBLOCK_ID', 'TYPE' => 'int'],
        ];

        $arSqls = CCatalog::PrepareSql($arFields, $arOrder, $arFilter, $arGroupBy, $arSelectFields);

        $arSqls['SELECT'] = str_replace('%%_DISTINCT_%%', '', $arSqls['SELECT']);

        if (empty($arGroupBy) && is_array($arGroupBy)) {
            $strSql = 'SELECT '.$arSqls['SELECT'].' FROM b_catalog_discount2iblock DG '.$arSqls['FROM'];
            if (!empty($arSqls['WHERE'])) {
                $strSql .= ' WHERE '.$arSqls['WHERE'];
            }
            if (!empty($arSqls['GROUPBY'])) {
                $strSql .= ' GROUP BY '.$arSqls['GROUPBY'];
            }

            $dbRes = $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
            if ($arRes = $dbRes->Fetch()) {
                return $arRes['CNT'];
            }

            return false;
        }

        $strSql = 'SELECT '.$arSqls['SELECT'].' FROM b_catalog_discount2iblock DG '.$arSqls['FROM'];
        if (!empty($arSqls['WHERE'])) {
            $strSql .= ' WHERE '.$arSqls['WHERE'];
        }
        if (!empty($arSqls['GROUPBY'])) {
            $strSql .= ' GROUP BY '.$arSqls['GROUPBY'];
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
            $strSql_tmp = "SELECT COUNT('x') as CNT FROM b_catalog_discount2iblock DG ".$arSqls['FROM'];
            if (!empty($arSqls['WHERE'])) {
                $strSql_tmp .= ' WHERE '.$arSqls['WHERE'];
            }
            if (!empty($arSqls['GROUPBY'])) {
                $strSql_tmp .= ' GROUP BY '.$arSqls['GROUPBY'];
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
     * @deprecated deprecated since catalog 12.0.0
     *
     * @noinspection PhpDeprecationInspection
     */
    public static function SaveFilterOptions()
    {
        COption::SetOptionString('catalog', 'do_use_discount_product', 'Y');

        COption::SetOptionString('catalog', 'do_use_discount_section', 'Y');

        COption::SetOptionString('catalog', 'do_use_discount_iblock', 'Y');

        self::__SaveFilterForEntity(['ENTITY_ID' => 'PRICE_TYPE_ID', 'OPTION_ID' => 'do_use_discount_cat_group']);
        self::__SaveFilterForEntity(['ENTITY_ID' => 'USER_GROUP_ID', 'OPTION_ID' => 'do_use_discount_group']);
    }

    protected static function updateDiscountHandlers($discountID, $handlers, $update)
    {
        global $DB;

        $discountID = (int) $discountID;
        if ($discountID <= 0 || empty($handlers) || !is_array($handlers)) {
            return;
        }
        if (isset($handlers['MODULES'])) {
            if ($update) {
                $sqlQuery = 'delete from b_catalog_discount_module where DISCOUNT_ID = '.$discountID;
                $DB->Query($sqlQuery, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
            }
            if (!empty($handlers['MODULES'])) {
                foreach ($handlers['MODULES'] as &$oneModuleID) {
                    $fields = [
                        'DISCOUNT_ID' => $discountID,
                        'MODULE_ID' => $oneModuleID,
                    ];
                    $insert = $DB->PrepareInsert('b_catalog_discount_module', $fields);
                    $sqlQuery = 'insert into b_catalog_discount_module('.$insert[0].') values('.$insert[1].')';
                    $DB->Query($sqlQuery, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
                }
                unset($oneModuleID);
            }
        }
    }

    protected static function getDiscountHandlers($discountList)
    {
        global $DB;

        $defaultRes = [
            'MODULES' => [],
            'EXT_FILES' => [],
        ];
        $result = [];
        Main\Type\Collection::normalizeArrayValuesByInt($discountList, true);
        if (!empty($discountList)) {
            $result = array_fill_keys($discountList, $defaultRes);
            $discountRows = array_chunk($discountList, 500);
            foreach ($discountRows as &$oneRow) {
                $sqlQuery = 'select * from b_catalog_discount_module where DISCOUNT_ID IN ('.implode(', ', $oneRow).')';
                $resQuery = $DB->Query($sqlQuery, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
                while ($row = $resQuery->Fetch()) {
                    $row['DISCOUNT_ID'] = (int) $row['DISCOUNT_ID'];
                    $result[$row['DISCOUNT_ID']]['MODULES'][] = $row['MODULE_ID'];
                }
                if (isset($row)) {
                    unset($row, $resQuery, $sqlQuery);
                }
            }
            unset($oneRow, $discountRows);
        }

        return $result;
    }
}
