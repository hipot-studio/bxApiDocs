<?php

use Bitrix\Catalog;
use Bitrix\Main;
use Bitrix\Sale\DiscountCouponsManager;

require_once $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/catalog/general/discount_coupon.php';

class discount_coupon extends CAllCatalogDiscountCoupon
{
    /**
     * @deprecated deprecated since catalog 12.5.6
     * @see CCatalogDiscountCoupon::CouponOneOrderDisable()
     *
     * @param mixed $arCoupons
     */
    public static function __CouponOneOrderDisable($arCoupons)
    {
        global $DB;
        if (!is_array($arCoupons)) {
            $arCoupons = [(int) $arCoupons];
        }
        CatalogClearArray($arCoupons, false);
        if (empty($arCoupons)) {
            return;
        }
        $strSql = "UPDATE b_catalog_discount_coupon SET ACTIVE='N' WHERE ID IN (".implode(', ', $arCoupons).") AND ONE_TIME='".self::TYPE_ONE_ORDER."'";
        $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
    }

    public static function Add($arFields, $bAffectDataFile = true)
    {
        static $eventOnBeforeAddExists = null;
        static $eventOnAddExists = null;
        global $DB;

        if (true === $eventOnBeforeAddExists || null === $eventOnBeforeAddExists) {
            foreach (GetModuleEvents('catalog', 'OnBeforeCouponAdd', true) as $arEvent) {
                $eventOnBeforeAddExists = true;
                if (false === ExecuteModuleEventEx($arEvent, [&$arFields, &$bAffectDataFile])) {
                    return false;
                }
            }
            if (null === $eventOnBeforeAddExists) {
                $eventOnBeforeAddExists = false;
            }
        }

        $bAffectDataFile = false;

        if (!CCatalogDiscountCoupon::CheckFields('ADD', $arFields, 0)) {
            return false;
        }

        $arInsert = $DB->PrepareInsert('b_catalog_discount_coupon', $arFields);

        $strSql = 'INSERT INTO b_catalog_discount_coupon('.$arInsert[0].') VALUES('.$arInsert[1].')';
        $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);

        $ID = (int) $DB->LastID();

        if ($ID > 0) {
            Catalog\DiscountTable::setUseCoupons($arFields['DISCOUNT_ID'], 'Y');
        }

        if (true === $eventOnAddExists || null === $eventOnAddExists) {
            foreach (GetModuleEvents('catalog', 'OnCouponAdd', true) as $arEvent) {
                $eventOnAddExists = true;
                ExecuteModuleEventEx($arEvent, [$ID, $arFields]);
            }
            if (null === $eventOnAddExists) {
                $eventOnAddExists = false;
            }
        }

        return $ID;
    }

    public static function Update($ID, $arFields)
    {
        static $eventOnBeforeUpdateExists = null;
        static $eventOnUpdateExists = null;
        global $DB;

        $ID = (int) $ID;
        if ($ID <= 0) {
            return false;
        }

        if (true === $eventOnBeforeUpdateExists || null === $eventOnBeforeUpdateExists) {
            foreach (GetModuleEvents('catalog', 'OnBeforeCouponUpdate', true) as $arEvent) {
                $eventOnBeforeUpdateExists = true;
                if (false === ExecuteModuleEventEx($arEvent, [$ID, &$arFields])) {
                    return false;
                }
            }
            if (null === $eventOnBeforeUpdateExists) {
                $eventOnBeforeUpdateExists = false;
            }
        }

        if (!CCatalogDiscountCoupon::CheckFields('UPDATE', $arFields, $ID)) {
            return false;
        }

        $discountIds = [];
        $strUpdate = $DB->PrepareUpdate('b_catalog_discount_coupon', $arFields);
        if (!empty($strUpdate)) {
            if (isset($arFields['DISCOUNT_ID'])) {
                $iterator = Catalog\DiscountCouponTable::getList([
                    'select' => ['DISCOUNT_ID', 'ID'],
                    'filter' => ['=ID' => $ID],
                ]);
                $row = $iterator->fetch();
                unset($iterator);
                if (!empty($row)) {
                    $row['DISCOUNT_ID'] = (int) $row['DISCOUNT_ID'];
                    if ($row['DISCOUNT_ID'] !== $arFields['DISCOUNT_ID']) {
                        $discountIds[] = $arFields['DISCOUNT_ID'];
                        $discountIds[] = $row['DISCOUNT_ID'];
                    }
                }
                unset($row);
            }

            $strSql = 'UPDATE b_catalog_discount_coupon SET '.$strUpdate.' WHERE ID = '.$ID;
            $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);

            if (!empty($discountIds)) {
                $withoutCoupons = array_fill_keys($discountIds, true);
                $withCoupons = [];
                $couponIterator = Catalog\DiscountCouponTable::getList([
                    'select' => ['DISCOUNT_ID', new Main\Entity\ExpressionField('CNT', 'COUNT(*)')],
                    'filter' => ['@DISCOUNT_ID' => $discountIds],
                    'group' => ['DISCOUNT_ID'],
                ]);
                while ($coupon = $couponIterator->fetch()) {
                    $coupon['CNT'] = (int) $coupon['CNT'];
                    if ($coupon['CNT'] > 0) {
                        $coupon['DISCOUNT_ID'] = (int) $coupon['DISCOUNT_ID'];
                        unset($withoutCoupons[$coupon['DISCOUNT_ID']]);
                        $withCoupons[$coupon['DISCOUNT_ID']] = true;
                    }
                }
                unset($coupon, $couponIterator);
                if (!empty($withoutCoupons)) {
                    $withoutCoupons = array_keys($withoutCoupons);
                    Catalog\DiscountTable::setUseCoupons($withoutCoupons, 'N');
                }
                if (!empty($withCoupons)) {
                    $withCoupons = array_keys($withCoupons);
                    Catalog\DiscountTable::setUseCoupons($withCoupons, 'Y');
                }
                unset($withCoupons, $withoutCoupons);
            }
        }
        unset($discountIds);

        if (true === $eventOnUpdateExists || null === $eventOnUpdateExists) {
            foreach (GetModuleEvents('catalog', 'OnCouponUpdate', true) as $arEvent) {
                $eventOnUpdateExists = true;
                ExecuteModuleEventEx($arEvent, [$ID, $arFields]);
            }
            if (null === $eventOnUpdateExists) {
                $eventOnUpdateExists = false;
            }
        }

        return $ID;
    }

    public static function Delete($ID, $bAffectDataFile = true)
    {
        static $eventOnBeforeDeleteExists = null;
        static $eventOnDeleteExists = null;
        global $DB;

        $ID = (int) $ID;
        if ($ID <= 0) {
            return false;
        }

        if (true === $eventOnBeforeDeleteExists || null === $eventOnBeforeDeleteExists) {
            foreach (GetModuleEvents('catalog', 'OnBeforeCouponDelete', true) as $arEvent) {
                $eventOnBeforeDeleteExists = true;
                if (false === ExecuteModuleEventEx($arEvent, [$ID, &$bAffectDataFile])) {
                    return false;
                }
            }
            if (null === $eventOnBeforeDeleteExists) {
                $eventOnBeforeDeleteExists = false;
            }
        }

        $bAffectDataFile = false;

        $iterator = Catalog\DiscountCouponTable::getList([
            'select' => ['DISCOUNT_ID', 'ID'],
            'filter' => ['=ID' => $ID],
        ]);
        $row = $iterator->fetch();
        unset($iterator);

        $DB->Query('DELETE FROM b_catalog_discount_coupon WHERE ID = '.$ID, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);

        if (!empty($row)) {
            $row['DISCOUNT_ID'] = (int) $row['DISCOUNT_ID'];
            $iterator = Catalog\DiscountCouponTable::getList([
                'select' => ['DISCOUNT_ID'],
                'filter' => ['=DISCOUNT_ID' => $row['DISCOUNT_ID']],
                'limit' => 1,
            ]);
            $existRow = $iterator->fetch();
            unset($iterator);
            Catalog\DiscountTable::setUseCoupons(
                $row['DISCOUNT_ID'],
                !empty($existRow) ? 'Y' : 'N'
            );
            unset($existRow);
        }
        unset($row);

        if (true === $eventOnDeleteExists || null === $eventOnDeleteExists) {
            foreach (GetModuleEvents('catalog', 'OnCouponDelete', true) as $arEvent) {
                $eventOnDeleteExists = true;
                ExecuteModuleEventEx($arEvent, [$ID]);
            }
            if (null === $eventOnDeleteExists) {
                $eventOnDeleteExists = false;
            }
        }

        return true;
    }

    public static function GetByID($ID)
    {
        global $DB;

        $ID = (int) $ID;
        if ($ID <= 0) {
            return false;
        }

        $strSql =
            'SELECT CD.ID, CD.DISCOUNT_ID, CD.ACTIVE, CD.COUPON, CD.ONE_TIME, '.
            $DB->DateToCharFunction('CD.DATE_APPLY', 'FULL').' as DATE_APPLY, '.
            $DB->DateToCharFunction('CD.TIMESTAMP_X', 'FULL').' as TIMESTAMP_X, '.
            'CD.CREATED_BY, CD.MODIFIED_BY, '.$DB->DateToCharFunction('CD.DATE_CREATE', 'FULL').' as DATE_CREATE, '.
            'CD.DESCRIPTION FROM b_catalog_discount_coupon CD WHERE CD.ID = '.$ID;

        $db_res = $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
        if ($res = $db_res->Fetch()) {
            return $res;
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
            'DISCOUNT_ID' => ['FIELD' => 'CD.DISCOUNT_ID', 'TYPE' => 'string'],
            'ACTIVE' => ['FIELD' => 'CD.ACTIVE', 'TYPE' => 'char'],
            'ONE_TIME' => ['FIELD' => 'CD.ONE_TIME', 'TYPE' => 'char'],
            'COUPON' => ['FIELD' => 'CD.COUPON', 'TYPE' => 'string'],
            'DATE_APPLY' => ['FIELD' => 'CD.DATE_APPLY', 'TYPE' => 'datetime'],
            'DISCOUNT_NAME' => ['FIELD' => 'CDD.NAME', 'TYPE' => 'string', 'FROM' => 'LEFT JOIN b_catalog_discount CDD ON (CD.DISCOUNT_ID = CDD.ID)'],
            'DESCRIPTION' => ['FIELD' => 'CD.DESCRIPTION', 'TYPE' => 'string'],
            'TIMESTAMP_X' => ['FIELD' => 'CD.TIMESTAMP_X', 'TYPE' => 'datetime'],
            'MODIFIED_BY' => ['FIELD' => 'CD.MODIFIED_BY', 'TYPE' => 'int'],
            'DATE_CREATE' => ['FIELD' => 'CD.DATE_CREATE', 'TYPE' => 'datetime'],
            'CREATED_BY' => ['FIELD' => 'CD.CREATED_BY', 'TYPE' => 'int'],
        ];

        $arSqls = CCatalog::PrepareSql($arFields, $arOrder, $arFilter, $arGroupBy, $arSelectFields);

        $arSqls['SELECT'] = str_replace('%%_DISTINCT_%%', '', $arSqls['SELECT']);

        if (empty($arGroupBy) && is_array($arGroupBy)) {
            $strSql = 'SELECT '.$arSqls['SELECT'].' FROM b_catalog_discount_coupon CD '.$arSqls['FROM'];
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

        $strSql = 'SELECT '.$arSqls['SELECT'].' FROM b_catalog_discount_coupon CD '.$arSqls['FROM'];
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
            $strSql_tmp = "SELECT COUNT('x') as CNT FROM b_catalog_discount_coupon CD ".$arSqls['FROM'];
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
     * @deprecated deprecated since catalog 15.0.4
     * @see DiscountCouponsManager
     *
     * @param mixed $intUserID
     * @param mixed $strCoupon
     */
    public static function CouponApply($intUserID, $strCoupon)
    {
        if (null === self::$existCouponsManager) {
            self::initCouponManager();
        }
        if (self::$existCouponsManager) {
            $couponList = (is_array($strCoupon) ? $strCoupon : [$strCoupon]);

            return DiscountCouponsManager::setApplyByProduct(['MODULE' => 'catalog'], $couponList, true);
        }

        global $DB;

        $mxResult = false;

        $intUserID = (int) $intUserID;
        if ($intUserID < 0) {
            $intUserID = 0;
        }

        $arCouponList = [];
        $arCheck = (is_array($strCoupon) ? $strCoupon : [$strCoupon]);
        foreach ($arCheck as &$strOneCheck) {
            $strOneCheck = (string) $strOneCheck;
            if ('' !== $strOneCheck) {
                $arCouponList[] = $strOneCheck;
            }
        }
        if (isset($strOneCheck)) {
            unset($strOneCheck);
        }

        if (empty($arCouponList)) {
            return $mxResult;
        }

        $strDateFunction = $DB->GetNowFunction();
        $boolFlag = false;
        $couponIterator = Catalog\DiscountCouponTable::getList([
            'select' => ['ID', 'TYPE', 'COUPON'],
            'filter' => ['=COUPON' => $arCouponList, '=ACTIVE' => 'Y'],
        ]);
        while ($arCoupon = $couponIterator->fetch()) {
            $arCoupon['ID'] = (int) $arCoupon['ID'];
            $arFields = [
                '~DATE_APPLY' => $strDateFunction,
            ];

            if (Catalog\DiscountCouponTable::TYPE_ONE_ROW === $arCoupon['TYPE']) {
                $arFields['ACTIVE'] = 'N';
                if (0 < $intUserID) {
                    CCatalogDiscountCoupon::EraseCouponByManage($intUserID, $arCoupon['COUPON']);
                } else {
                    CCatalogDiscountCoupon::EraseCoupon($arCoupon['COUPON']);
                }
            } elseif (Catalog\DiscountCouponTable::TYPE_ONE_ORDER === $arCoupon['TYPE']) {
                $boolFlag = true;
                if (!isset(self::$arOneOrderCoupons[$arCoupon['ID']])) {
                    self::$arOneOrderCoupons[$arCoupon['ID']] = [
                        'COUPON' => $arCoupon['COUPON'],
                        'USER_ID' => $intUserID,
                    ];
                }
            }

            $strUpdate = $DB->PrepareUpdate('b_catalog_discount_coupon', $arFields);
            if (!empty($strUpdate)) {
                $strSql = 'UPDATE b_catalog_discount_coupon SET '.$strUpdate.' WHERE ID = '.$arCoupon['ID'];
                $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
                $mxResult = true;
            }
        }
        unset($arCoupon, $couponIterator);
        if ($boolFlag) {
            AddEventHandler('sale', 'OnBasketOrder', ['CCatalogDiscountCoupon', 'CouponOneOrderDisable']);
            AddEventHandler('sale', 'OnDoBasketOrder', ['CCatalogDiscountCoupon', 'CouponOneOrderDisable']);
        }

        return $mxResult;
    }

    /**
     * @deprecated deprecated since catalog 15.0.4
     * @see DiscountCouponsManager::saveApplied
     *
     * @param mixed $intOrderID
     */
    public static function CouponOneOrderDisable($intOrderID = 0)
    {
        if (null === self::$existCouponsManager) {
            self::initCouponManager();
        }
        if (self::$existCouponsManager) {
            return;
        }

        global $DB;
        if (!empty(self::$arOneOrderCoupons)) {
            $arCouponID = array_keys(self::$arOneOrderCoupons);
            foreach (self::$arOneOrderCoupons as &$arCoupon) {
                $arCoupon['USER_ID'] = (int) $arCoupon['USER_ID'];
                if (0 < $arCoupon['USER_ID']) {
                    CCatalogDiscountCoupon::EraseCouponByManage($arCoupon['USER_ID'], $arCoupon['COUPON']);
                } else {
                    CCatalogDiscountCoupon::EraseCoupon($arCoupon['COUPON']);
                }
            }
            if (isset($arCoupon)) {
                unset($arCoupon);
            }
            CatalogClearArray($arCouponID, false);
            if (!empty($arCouponID)) {
                $strSql = "UPDATE b_catalog_discount_coupon SET ACTIVE='N' WHERE ID IN (".implode(', ', $arCouponID).") AND ONE_TIME='".self::TYPE_ONE_ORDER."' AND ACTIVE='Y'";
                $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
            }
            self::$arOneOrderCoupons = [];
        }
    }

    /**
     * @deprecated deprecated since catalog 15.0.4
     * @see DiscountCouponsManager::isExist
     *
     * @param mixed $strCoupon
     */
    public static function IsExistCoupon($strCoupon)
    {
        if (null === self::$existCouponsManager) {
            self::initCouponManager();
        }
        if (self::$existCouponsManager) {
            $result = DiscountCouponsManager::isExist($strCoupon);
            if (!empty($result)) {
                return true;
            }

            return false;
        }

        global $DB;

        if ('' === $strCoupon) {
            return false;
        }

        $strSql = "select ID, COUPON from b_catalog_discount_coupon where COUPON='".$DB->ForSql($strCoupon)."' limit 1";
        $rsCoupons = $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
        if ($arCoupon = $rsCoupons->Fetch()) {
            return true;
        }

        return false;
    }
}
