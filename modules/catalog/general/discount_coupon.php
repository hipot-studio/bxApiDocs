<?php

use Bitrix\Catalog;
use Bitrix\Catalog\DiscountCouponTable;
use Bitrix\Main;
use Bitrix\Main\Application;
use Bitrix\Main\Localization\Loc;
use Bitrix\Sale\DiscountCouponsManager;

Loc::loadMessages(__FILE__);

class discount_coupon
{
    public const TYPE_ONE_TIME = 'Y';
    public const TYPE_ONE_ORDER = 'O';
    public const TYPE_NO_LIMIT = 'N';

    protected static $arOneOrderCoupons = [];
    protected static $existCouponsManager;

    /**
     * @deprecated deprecated since catalog 15.0.7
     * @see DiscountCouponTable::getCouponTypes
     *
     * @param bool $boolFull get full description
     *
     * @return array
     */
    public static function GetCoupontTypes($boolFull = false)
    {
        return DiscountCouponTable::getCouponTypes($boolFull);
    }

    public static function CheckFields($ACTION, &$arFields, $ID = 0)
    {
        global $DB, $APPLICATION, $USER;

        $ACTION = mb_strtoupper($ACTION);
        if ('UPDATE' !== $ACTION && 'ADD' !== $ACTION) {
            return false;
        }

        if (null === self::$existCouponsManager) {
            self::initCouponManager();
        }

        $clearFields = [
            'ID',
            '~ID',
            '~COUPON',
            'TIMESTAMP_X',
            'DATE_CREATE',
            '~DATE_CREATE',
            '~MODIFIED_BY',
            '~CREATED_BY',
        ];
        if ('UPDATE' === $ACTION) {
            $clearFields[] = 'CREATED_BY';
        }

        foreach ($clearFields as &$fieldName) {
            if (array_key_exists($fieldName, $arFields)) {
                unset($arFields[$fieldName]);
            }
        }
        unset($fieldName, $clearFields);

        if ((is_set($arFields, 'DISCOUNT_ID') || 'ADD' === $ACTION) && (int) $arFields['DISCOUNT_ID'] <= 0) {
            $APPLICATION->ThrowException(Loc::getMessage('KGDC_EMPTY_DISCOUNT'), 'EMPTY_DISCOUNT_ID');

            return false;
        }

        if ((is_set($arFields, 'COUPON') || 'ADD' === $ACTION) && '' === $arFields['COUPON']) {
            $APPLICATION->ThrowException(Loc::getMessage('KGDC_EMPTY_COUPON'), 'EMPTY_COUPON');

            return false;
        }
        if (is_set($arFields, 'COUPON')) {
            $currentId = ('UPDATE' === $ACTION ? $ID : 0);
            $arFields['COUPON'] = mb_substr($arFields['COUPON'], 0, 32);
            if (self::$existCouponsManager) {
                $existCoupon = DiscountCouponsManager::isExist($arFields['COUPON']);
                if (!empty($existCoupon)) {
                    if ('catalog' !== $existCoupon['MODULE'] || $currentId !== $existCoupon['ID']) {
                        $APPLICATION->ThrowException(Loc::getMessage('KGDC_DUPLICATE_COUPON'), 'DUPLICATE_COUPON');

                        return false;
                    }
                }
            } else {
                $couponIterator = DiscountCouponTable::getList([
                    'select' => ['ID', 'COUPON'],
                    'filter' => ['=COUPON' => $arFields['COUPON']],
                ]);
                if ($existCoupon = $couponIterator->fetch()) {
                    if ($currentId !== (int) $existCoupon['ID']) {
                        $APPLICATION->ThrowException(Loc::getMessage('KGDC_DUPLICATE_COUPON'), 'DUPLICATE_COUPON');

                        return false;
                    }
                }
            }
        }

        if ((is_set($arFields, 'ACTIVE') || 'ADD' === $ACTION) && 'N' !== $arFields['ACTIVE']) {
            $arFields['ACTIVE'] = 'Y';
        }
        if ((is_set($arFields, 'ONE_TIME') || 'ADD' === $ACTION) && !in_array($arFields['ONE_TIME'], DiscountCouponTable::getCouponTypes(), true)) {
            $arFields['ONE_TIME'] = self::TYPE_ONE_TIME;
        }

        if ((is_set($arFields, 'DATE_APPLY') || 'ADD' === $ACTION) && (!$DB->IsDate($arFields['DATE_APPLY'], false, SITE_ID, 'FULL'))) {
            $arFields['DATE_APPLY'] = false;
        }

        $intUserID = 0;
        $boolUserExist = CCatalog::IsUserExists();
        if ($boolUserExist) {
            $intUserID = (int) $USER->GetID();
        }
        $strDateFunction = $DB->GetNowFunction();
        $arFields['~TIMESTAMP_X'] = $strDateFunction;
        if ($boolUserExist) {
            if (!array_key_exists('MODIFIED_BY', $arFields) || (int) $arFields['MODIFIED_BY'] <= 0) {
                $arFields['MODIFIED_BY'] = $intUserID;
            }
        }
        if ('ADD' === $ACTION) {
            $arFields['~DATE_CREATE'] = $strDateFunction;
            if ($boolUserExist) {
                if (!array_key_exists('CREATED_BY', $arFields) || (int) $arFields['CREATED_BY'] <= 0) {
                    $arFields['CREATED_BY'] = $intUserID;
                }
            }
        }

        return true;
    }

    /**
     * @deprecated deprecated since catalog 17.6.7
     * @see \Bitrix\Catalog\DiscountCouponTable::deleteByDiscount()
     *
     * @param int  $ID
     * @param bool $bAffectDataFile
     *
     * @return bool
     */
    public static function DeleteByDiscountID($ID, $bAffectDataFile = true)
    {
        $ID = (int) $ID;
        if ($ID <= 0) {
            return false;
        }
        DiscountCouponTable::deleteByDiscount($ID);

        return true;
    }

    /**
     * @deprecated deprecated since catalog 15.0.4
     * @see DiscountCouponsManager::add
     *
     * @param string $coupon coupon code
     *
     * @return bool
     */
    public static function SetCoupon($coupon)
    {
        if (null === self::$existCouponsManager) {
            self::initCouponManager();
        }

        if (self::$existCouponsManager) {
            if (DiscountCouponsManager::usedByClient()) {
                return DiscountCouponsManager::add($coupon);
            }

            return false;
        }

        $coupon = trim((string) $coupon);
        if ('' === $coupon) {
            return false;
        }

        $session = Application::getInstance()->getSession();
        if (!$session->isAccessible()) {
            return false;
        }

        if (!isset($session['CATALOG_USER_COUPONS']) || !is_array($session['CATALOG_USER_COUPONS'])) {
            $session['CATALOG_USER_COUPONS'] = [];
        }

        $couponIterator = DiscountCouponTable::getList([
            'select' => ['ID', 'COUPON'],
            'filter' => ['=COUPON' => $coupon, '=ACTIVE' => 'Y'],
        ]);
        if ($existCoupon = $couponIterator->fetch()) {
            if (!in_array($existCoupon['COUPON'], $session['CATALOG_USER_COUPONS'], true)) {
                $session['CATALOG_USER_COUPONS'][] = $existCoupon['COUPON'];
            }

            return true;
        }

        return false;
    }

    /**
     * @deprecated deprecated since catalog 15.0.4
     * @see DiscountCouponsManager::get
     */
    public static function GetCoupons()
    {
        if (null === self::$existCouponsManager) {
            self::initCouponManager();
        }

        if (self::$existCouponsManager) {
            if (DiscountCouponsManager::usedByClient()) {
                return DiscountCouponsManager::get(false, ['MODULE' => 'catalog'], true);
            }

            return [];
        }

        $session = Application::getInstance()->getSession();
        if (!$session->isAccessible()) {
            return [];
        }

        if (!isset($session['CATALOG_USER_COUPONS']) || !is_array($session['CATALOG_USER_COUPONS'])) {
            $session['CATALOG_USER_COUPONS'] = [];
        }

        return $session['CATALOG_USER_COUPONS'];
    }

    /**
     * @deprecated deprecated since catalog 15.0.4
     * @see DiscountCouponsManager::delete
     *
     * @param string $strCoupon coupon code
     *
     * @return bool
     */
    public static function EraseCoupon($strCoupon)
    {
        if (null === self::$existCouponsManager) {
            self::initCouponManager();
        }
        if (self::$existCouponsManager) {
            if (DiscountCouponsManager::usedByClient()) {
                return DiscountCouponsManager::delete($strCoupon);
            }

            return false;
        }

        $strCoupon = trim((string) $strCoupon);
        if (empty($strCoupon)) {
            return false;
        }

        $session = Application::getInstance()->getSession();
        if (!$session->isAccessible()) {
            return false;
        }

        if (!isset($session['CATALOG_USER_COUPONS']) || !is_array($session['CATALOG_USER_COUPONS'])) {
            $session['CATALOG_USER_COUPONS'] = [];

            return true;
        }
        $key = array_search($strCoupon, $session['CATALOG_USER_COUPONS'], true);
        if (false !== $key) {
            unset($session['CATALOG_USER_COUPONS'][$key]);
        }

        return true;
    }

    /**
     * @deprecated deprecated since catalog 15.0.4
     * @see DiscountCouponsManager::clear
     */
    public static function ClearCoupon()
    {
        if (null === self::$existCouponsManager) {
            self::initCouponManager();
        }

        if (self::$existCouponsManager) {
            if (DiscountCouponsManager::usedByClient()) {
                DiscountCouponsManager::clear(true);
            }
        } else {
            $session = Application::getInstance()->getSession();
            if ($session->isAccessible()) {
                $session['CATALOG_USER_COUPONS'] = [];
            }
        }
    }

    /**
     * @deprecated deprecated since catalog 15.0.4
     * @see DiscountCouponsManager::add
     *
     * @param int    $intUserID user id
     * @param string $strCoupon coupon code
     *
     * @return bool
     */
    public static function SetCouponByManage($intUserID, $strCoupon)
    {
        $intUserID = (int) $intUserID;
        if ($intUserID >= 0) {
            if (null === self::$existCouponsManager) {
                self::initCouponManager();
            }
            if (self::$existCouponsManager) {
                if (DiscountCouponsManager::usedByManager() && DiscountCouponsManager::getUserId() === $intUserID) {
                    return DiscountCouponsManager::add($strCoupon);
                }

                return false;
            }

            $strCoupon = trim((string) $strCoupon);
            if (empty($strCoupon)) {
                return false;
            }

            $session = Application::getInstance()->getSession();
            if (!$session->isAccessible()) {
                return false;
            }

            if (!isset($session['CATALOG_MANAGE_COUPONS']) || !is_array($session['CATALOG_MANAGE_COUPONS'])) {
                $session['CATALOG_MANAGE_COUPONS'] = [];
            }
            if (!isset($session['CATALOG_MANAGE_COUPONS'][$intUserID]) || !is_array($session['CATALOG_MANAGE_COUPONS'][$intUserID])) {
                $session['CATALOG_MANAGE_COUPONS'][$intUserID] = [];
            }

            $couponIterator = DiscountCouponTable::getList([
                'select' => ['ID', 'COUPON'],
                'filter' => ['=COUPON' => $strCoupon, '=ACTIVE' => 'Y'],
            ]);
            if ($existCoupon = $couponIterator->fetch()) {
                if (!in_array($existCoupon['COUPON'], $session['CATALOG_MANAGE_COUPONS'][$intUserID], true)) {
                    $session['CATALOG_MANAGE_COUPONS'][$intUserID][] = $existCoupon['COUPON'];
                }

                return true;
            }
        }

        return false;
    }

    /**
     * @deprecated deprecated since catalog 15.0.4
     * @see DiscountCouponsManager::get
     *
     * @param int $intUserID user id
     *
     * @return array|bool
     */
    public static function GetCouponsByManage($intUserID)
    {
        $intUserID = (int) $intUserID;
        if ($intUserID >= 0) {
            if (null === self::$existCouponsManager) {
                self::initCouponManager();
            }
            if (self::$existCouponsManager) {
                if (DiscountCouponsManager::usedByManager() && DiscountCouponsManager::getUserId() === $intUserID) {
                    return DiscountCouponsManager::get(false, ['MODULE' => 'catalog'], true);
                }

                return false;
            }

            $session = Application::getInstance()->getSession();
            if (!$session->isAccessible()) {
                return false;
            }

            if (!isset($session['CATALOG_MANAGE_COUPONS']) || !is_array($session['CATALOG_MANAGE_COUPONS'])) {
                $session['CATALOG_MANAGE_COUPONS'] = [];
            }
            if (!isset($session['CATALOG_MANAGE_COUPONS'][$intUserID]) || !is_array($session['CATALOG_MANAGE_COUPONS'][$intUserID])) {
                $session['CATALOG_MANAGE_COUPONS'][$intUserID] = [];
            }

            return $session['CATALOG_MANAGE_COUPONS'][$intUserID];
        }

        return false;
    }

    /**
     * @deprecated deprecated since catalog 15.0.4
     * @see DiscountCouponsManager::delete
     *
     * @param int    $intUserID user id
     * @param string $strCoupon coupon code
     *
     * @return bool
     */
    public static function EraseCouponByManage($intUserID, $strCoupon)
    {
        $intUserID = (int) $intUserID;
        if ($intUserID >= 0) {
            if (null === self::$existCouponsManager) {
                self::initCouponManager();
            }
            if (self::$existCouponsManager) {
                if (DiscountCouponsManager::usedByManager() && DiscountCouponsManager::getUserId() === $intUserID) {
                    return DiscountCouponsManager::delete($strCoupon);
                }

                return false;
            }

            $session = Application::getInstance()->getSession();
            if (!$session->isAccessible()) {
                return false;
            }

            $strCoupon = trim((string) $strCoupon);
            if (empty($strCoupon)) {
                return false;
            }
            if (!isset($session['CATALOG_MANAGE_COUPONS']) || !is_array($session['CATALOG_MANAGE_COUPONS'])) {
                return false;
            }
            if (!isset($session['CATALOG_MANAGE_COUPONS'][$intUserID]) || !is_array($session['CATALOG_MANAGE_COUPONS'][$intUserID])) {
                return false;
            }
            $key = array_search($strCoupon, $session['CATALOG_MANAGE_COUPONS'][$intUserID], true);
            if (false !== $key) {
                unset($session['CATALOG_MANAGE_COUPONS'][$intUserID][$key]);

                return true;
            }
        }

        return false;
    }

    /**
     * @deprecated deprecated since catalog 15.0.4
     * @see DiscountCouponsManager::clear
     *
     * @param int $intUserID user id
     *
     * @return bool
     */
    public static function ClearCouponsByManage($intUserID)
    {
        $intUserID = (int) $intUserID;
        if ($intUserID >= 0) {
            if (null === self::$existCouponsManager) {
                self::initCouponManager();
            }
            if (self::$existCouponsManager) {
                if (DiscountCouponsManager::usedByManager() && DiscountCouponsManager::getUserId() === $intUserID) {
                    return DiscountCouponsManager::clear(true);
                }

                return false;
            }

            $session = Application::getInstance()->getSession();
            if (!$session->isAccessible()) {
                return false;
            }

            if (!isset($session['CATALOG_MANAGE_COUPONS']) || !is_array($session['CATALOG_MANAGE_COUPONS'])) {
                $session['CATALOG_MANAGE_COUPONS'] = [];
            }
            $session['CATALOG_MANAGE_COUPONS'][$intUserID] = [];

            return true;
        }

        return false;
    }

    /**
     * @deprecated deprecated since catalog 15.0.4
     * @see DiscountCouponsManager
     *
     * @param int   $intUserID user id
     * @param array $arCoupons coupon code list
     * @param array $arModules modules list
     *
     * @return bool
     */
    public static function OnSetCouponList($intUserID, $arCoupons, $arModules)
    {
        global $USER;
        $boolResult = false;
        if (
            empty($arModules)
            || (is_array($arModules) && in_array('catalog', $arModules, true))
        ) {
            if (!empty($arCoupons)) {
                if (!is_array($arCoupons)) {
                    $arCoupons = [$arCoupons];
                }
                $intUserID = (int) $intUserID;

                if (null === self::$existCouponsManager) {
                    self::initCouponManager();
                }
                if (self::$existCouponsManager) {
                    if ($intUserID === DiscountCouponsManager::getUserId()) {
                        foreach ($arCoupons as &$coupon) {
                            if (DiscountCouponsManager::add($coupon)) {
                                $boolResult = true;
                            }
                        }
                        unset($coupon);

                        return $boolResult;
                    }

                    return false;
                }

                if ($intUserID > 0) {
                    $boolCurrentUser = ($USER->IsAuthorized() && $intUserID === $USER->GetID());
                    foreach ($arCoupons as &$strOneCoupon) {
                        if (self::SetCouponByManage($intUserID, $strOneCoupon)) {
                            $boolResult = true;
                        }
                        if ($boolCurrentUser) {
                            self::SetCoupon($strOneCoupon);
                        }
                    }
                    unset($strOneCoupon);
                } elseif (0 === $intUserID && !$USER->IsAuthorized()) {
                    foreach ($arCoupons as &$strOneCoupon) {
                        $couponResult = self::SetCoupon($strOneCoupon);
                        if ($couponResult) {
                            $boolResult = true;
                        }
                    }
                    unset($strOneCoupon);
                }
            }
        }

        return $boolResult;
    }

    /**
     * @deprecated deprecated since catalog 15.0.4
     * @see DiscountCouponsManager
     *
     * @param int   $intUserID user id
     * @param array $arCoupons coupon code list
     * @param array $arModules modules list
     *
     * @return bool
     */
    public static function OnClearCouponList($intUserID, $arCoupons, $arModules)
    {
        global $USER;

        $boolResult = false;
        if (
            empty($arModules)
            || (is_array($arModules) && in_array('catalog', $arModules, true))
        ) {
            if (!empty($arCoupons)) {
                if (!is_array($arCoupons)) {
                    $arCoupons = [$arCoupons];
                }
                $intUserID = (int) $intUserID;

                if (null === self::$existCouponsManager) {
                    self::initCouponManager();
                }
                if (self::$existCouponsManager) {
                    if ($intUserID === DiscountCouponsManager::getUserId()) {
                        foreach ($arCoupons as &$coupon) {
                            if (DiscountCouponsManager::delete($coupon)) {
                                $boolResult = true;
                            }
                        }
                        unset($coupon);

                        return $boolResult;
                    }

                    return false;
                }

                if ($intUserID > 0) {
                    $boolCurrentUser = ($USER->IsAuthorized() && $intUserID === $USER->GetID());
                    foreach ($arCoupons as &$strOneCoupon) {
                        if (self::EraseCouponByManage($intUserID, $strOneCoupon)) {
                            $boolResult = true;
                        }
                        if ($boolCurrentUser) {
                            self::EraseCoupon($strOneCoupon);
                        }
                    }
                    unset($strOneCoupon);
                } elseif (0 === $intUserID && !$USER->IsAuthorized()) {
                    foreach ($arCoupons as &$strOneCoupon) {
                        if (self::EraseCoupon($strOneCoupon)) {
                            $boolResult = true;
                        }
                    }
                    unset($strOneCoupon);
                }
            }
        }

        return $boolResult;
    }

    /**
     * @deprecated deprecated since catalog 15.0.4
     * @see DiscountCouponsManager
     *
     * @param int   $intUserID user id
     * @param array $arModules modules list
     *
     * @return bool
     */
    public static function OnDeleteCouponList($intUserID, $arModules)
    {
        global $USER;

        $boolResult = false;
        if (
            empty($arModules)
            || (is_array($arModules) && in_array('catalog', $arModules, true))
        ) {
            $intUserID = (int) $intUserID;
            if (null === self::$existCouponsManager) {
                self::initCouponManager();
            }
            if (self::$existCouponsManager) {
                if ($intUserID === DiscountCouponsManager::getUserId()) {
                    return DiscountCouponsManager::clear(true);
                }

                return false;
            }

            if (0 < $intUserID) {
                $boolCurrentUser = ($USER->IsAuthorized() && $intUserID === $USER->GetID());
                $boolResult = self::ClearCouponsByManage($intUserID);
                if ($boolCurrentUser) {
                    self::ClearCoupon();
                }
            } elseif (0 === $intUserID && !$USER->IsAuthorized()) {
                self::ClearCoupon();
            }
        }

        return $boolResult;
    }

    /**
     * @deprecated deprecated since catalog 15.0.4
     * @see DiscountCouponsManager::isExist
     *
     * @param string $strCoupon coupon code
     *
     * @return bool
     */
    public static function IsExistCoupon($strCoupon)
    {
        return false;
    }

    protected static function initCouponManager()
    {
        if (null === self::$existCouponsManager) {
            self::$existCouponsManager = Main\ModuleManager::isModuleInstalled('sale') && Main\Loader::includeModule('sale');
        }
    }
}
