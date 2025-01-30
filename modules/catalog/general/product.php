<?php

/** @global \CMain $APPLICATION */
use Bitrix\Catalog;
use Bitrix\Catalog\Model\Product;
use Bitrix\Catalog\ProductTable;
use Bitrix\Currency;
use Bitrix\Main;
use Bitrix\Main\Localization\Loc;
use Bitrix\Sale;
use Bitrix\Sale\Basket;
use Bitrix\Sale\BasketItem;

class product
{
    public const TYPE_PRODUCT = ProductTable::TYPE_PRODUCT;
    public const TYPE_SET = ProductTable::TYPE_SET;
    public const TYPE_SKU = ProductTable::TYPE_SKU;
    public const TYPE_OFFER = ProductTable::TYPE_OFFER;
    public const TYPE_FREE_OFFER = ProductTable::TYPE_FREE_OFFER;
    public const TYPE_EMPTY_SKU = ProductTable::TYPE_EMPTY_SKU;

    public const TIME_PERIOD_HOUR = ProductTable::PAYMENT_PERIOD_HOUR;
    public const TIME_PERIOD_DAY = ProductTable::PAYMENT_PERIOD_DAY;
    public const TIME_PERIOD_WEEK = ProductTable::PAYMENT_PERIOD_WEEK;
    public const TIME_PERIOD_MONTH = ProductTable::PAYMENT_PERIOD_MONTH;
    public const TIME_PERIOD_QUART = ProductTable::PAYMENT_PERIOD_QUART;
    public const TIME_PERIOD_SEMIYEAR = ProductTable::PAYMENT_PERIOD_SEMIYEAR;
    public const TIME_PERIOD_YEAR = ProductTable::PAYMENT_PERIOD_YEAR;
    public const TIME_PERIOD_DOUBLE_YEAR = ProductTable::PAYMENT_PERIOD_DOUBLE_YEAR;

    /** @deprecated deprecated since catalog 17.6.3 */
    protected static $arProductCache = [];

    /** @deprecated deprecated since catalog 17.0.11 */
    protected static $usedCurrency;

    /** @deprecated deprecated since catalog 17.5.1 */
    protected static $optimalPriceWithVat = true;

    /** @deprecated deprecated since catalog 17.5.1 */
    protected static $useDiscount = true;

    protected static $saleIncluded;
    protected static $useSaleDiscount;
    protected static $vatCache = [];

    private static $existPriceTypeDiscounts = false;

    /**
     * @deprecated deprecated since catalog 15.0.0
     * @see CCatalogDiscount::applyDiscountList()
     * @see CCatalogDiscount::primaryDiscountFilter()
     *
     * @param array &$arDiscount
     * @param array &$arPriceDiscount
     * @param array &$arDiscSave
     * @param array &$arParams
     */
    protected static function __PrimaryDiscountFilter(&$arDiscount, &$arPriceDiscount, &$arDiscSave, &$arParams)
    {
        if (isset($arParams['PRICE'], $arParams['CURRENCY'])) {
            $arParams['PRICE'] = (float) $arParams['PRICE'];
            $arParams['BASE_PRICE'] = $arParams['PRICE'];
            if ($arParams['PRICE'] > 0) {
                $arPriceDiscount = [];
                $arDiscSave = [];

                foreach ($arDiscount as $arOneDiscount) {
                    $changeData = ($arParams['CURRENCY'] !== $arOneDiscount['CURRENCY']);

                    /** @noinspection PhpUnusedLocalVariableInspection */
                    $dblDiscountValue = 0.0;
                    $arOneDiscount['PRIORITY'] = (int) $arOneDiscount['PRIORITY'];
                    if (CCatalogDiscount::TYPE_FIX === $arOneDiscount['VALUE_TYPE']) {
                        $dblDiscountValue = (
                            !$changeData
                            ? $arOneDiscount['VALUE']
                            : round(
                                CCurrencyRates::ConvertCurrency($arOneDiscount['VALUE'], $arOneDiscount['CURRENCY'], $arParams['CURRENCY']),
                                CATALOG_VALUE_PRECISION
                            )
                        );
                        if ($arParams['PRICE'] < $dblDiscountValue) {
                            continue;
                        }
                        $arOneDiscount['DISCOUNT_CONVERT'] = $dblDiscountValue;
                        if ($changeData) {
                            $arOneDiscount['VALUE'] = $arOneDiscount['DISCOUNT_CONVERT'];
                        }
                    } elseif (CCatalogDiscount::TYPE_SALE === $arOneDiscount['VALUE_TYPE']) {
                        $dblDiscountValue = (
                            !$changeData
                            ? $arOneDiscount['VALUE']
                            : round(
                                CCurrencyRates::ConvertCurrency($arOneDiscount['VALUE'], $arOneDiscount['CURRENCY'], $arParams['CURRENCY']),
                                CATALOG_VALUE_PRECISION
                            )
                        );
                        if ($arParams['PRICE'] <= $dblDiscountValue) {
                            continue;
                        }
                        $arOneDiscount['DISCOUNT_CONVERT'] = $dblDiscountValue;
                        if ($changeData) {
                            $arOneDiscount['VALUE'] = $arOneDiscount['DISCOUNT_CONVERT'];
                        }
                    } elseif (CCatalogDiscount::TYPE_PERCENT === $arOneDiscount['VALUE_TYPE']) {
                        if (100 < $arOneDiscount['VALUE']) {
                            continue;
                        }
                        if (CCatalogDiscount::ENTITY_ID === $arOneDiscount['TYPE'] && $arOneDiscount['MAX_DISCOUNT'] > 0) {
                            $dblDiscountValue = (
                                !$changeData
                                ? $arOneDiscount['MAX_DISCOUNT']
                                : round(
                                    CCurrencyRates::ConvertCurrency($arOneDiscount['MAX_DISCOUNT'], $arOneDiscount['CURRENCY'], $arParams['CURRENCY']),
                                    CATALOG_VALUE_PRECISION
                                )
                            );
                            $arOneDiscount['DISCOUNT_CONVERT'] = $dblDiscountValue;
                            if ($changeData) {
                                $arOneDiscount['MAX_DISCOUNT'] = $arOneDiscount['DISCOUNT_CONVERT'];
                            }
                        }
                    }
                    if ($changeData) {
                        $arOneDiscount['CURRENCY'] = $arParams['CURRENCY'];
                    }
                    if (CCatalogDiscountSave::ENTITY_ID === $arOneDiscount['TYPE']) {
                        $arDiscSave[] = $arOneDiscount;
                    } else {
                        $arPriceDiscount[$arOneDiscount['PRIORITY']][] = $arOneDiscount;
                    }
                }

                if (!empty($arPriceDiscount)) {
                    krsort($arPriceDiscount);
                }
            }
        }
    }

    /**
     * @deprecated deprecated since catalog 15.0.0
     * @see CCatalogDiscount::applyDiscountList()
     * @see CCatalogDiscount::calculatePriorityLevel()
     *
     * @param array &$arDiscounts
     * @param array &$arResultDiscount
     * @param array &$arParams
     *
     * @return bool
     */
    protected static function __CalcOnePriority(&$arDiscounts, &$arResultDiscount, &$arParams)
    {
        $boolResult = false;
        if (isset($arParams['PRICE'], $arParams['CURRENCY'])) {
            $arParams['PRICE'] = (float) $arParams['PRICE'];
            $arParams['BASE_PRICE'] = (float) $arParams['BASE_PRICE'];
            if ($arParams['PRICE'] > 0) {
                $dblCurrentPrice = $arParams['PRICE'];
                do {
                    $dblMinPrice = -1;
                    $strMinKey = -1;
                    $boolApply = false;
                    foreach ($arDiscounts as $strDiscountKey => $arOneDiscount) {
                        $boolDelete = false;
                        $dblPriceTmp = -1;

                        switch ($arOneDiscount['VALUE_TYPE']) {
                            case CCatalogDiscount::TYPE_PERCENT:
                                $dblTempo = round(
                                    (
                                        CCatalogDiscount::getUseBasePrice()
                                        ? $arParams['BASE_PRICE']
                                        : $dblCurrentPrice
                                    ) * $arOneDiscount['VALUE'] / 100,
                                    CATALOG_VALUE_PRECISION
                                );
                                if (isset($arOneDiscount['DISCOUNT_CONVERT'])) {
                                    if ($dblTempo > $arOneDiscount['DISCOUNT_CONVERT']) {
                                        $dblTempo = $arOneDiscount['DISCOUNT_CONVERT'];
                                    }
                                }
                                $dblPriceTmp = $dblCurrentPrice - $dblTempo;

                                break;

                            case CCatalogDiscount::TYPE_FIX:
                                if ($arOneDiscount['DISCOUNT_CONVERT'] > $dblCurrentPrice) {
                                    $boolDelete = true;
                                } else {
                                    $dblPriceTmp = $dblCurrentPrice - $arOneDiscount['DISCOUNT_CONVERT'];
                                }

                                break;

                            case CCatalogDiscount::TYPE_SALE:
                                if (!($arOneDiscount['DISCOUNT_CONVERT'] < $dblCurrentPrice)) {
                                    $boolDelete = true;
                                } else {
                                    $dblPriceTmp = $arOneDiscount['DISCOUNT_CONVERT'];
                                }

                                break;
                        }
                        if ($boolDelete) {
                            unset($arDiscounts[$strDiscountKey]);
                        } else {
                            if (-1 === $dblMinPrice || $dblMinPrice > $dblPriceTmp) {
                                $dblMinPrice = $dblPriceTmp;
                                $strMinKey = $strDiscountKey;
                                $boolApply = true;
                            }
                        }
                    }
                    if ($boolApply) {
                        $dblCurrentPrice = $dblMinPrice;
                        $arResultDiscount[] = $arDiscounts[$strMinKey];
                        if ('Y' === $arDiscounts[$strMinKey]['LAST_DISCOUNT']) {
                            $arDiscounts = [];
                            $arParams['LAST_DISCOUNT'] = 'Y';
                        }
                        unset($arDiscounts[$strMinKey]);
                    }
                } while (!empty($arDiscounts));
                if ($boolApply) {
                    $arParams['PRICE'] = $dblCurrentPrice;
                }
                $boolResult = true;
            }
        }

        return $boolResult;
    }

    /**
     * @deprecated deprecated since catalog 15.0.0
     * @see CCatalogDiscount::applyDiscountList()
     * @see CCatalogDiscount::calculateDiscSave()
     *
     * @param array &$arDiscSave
     * @param array &$arResultDiscount
     * @param array &$arParams
     *
     * @return bool
     */
    protected static function __CalcDiscSave(&$arDiscSave, &$arResultDiscount, &$arParams)
    {
        $boolResult = false;
        if (isset($arParams['PRICE'], $arParams['CURRENCY'])) {
            $arParams['PRICE'] = (float) $arParams['PRICE'];
            if (0 < $arParams['PRICE']) {
                $dblCurrentPrice = $arParams['PRICE'];
                $dblMinPrice = -1;
                $strMinKey = -1;
                $boolApply = false;
                foreach ($arDiscSave as $strDiscountKey => $arOneDiscount) {
                    $dblPriceTmp = -1;
                    $boolDelete = false;

                    switch ($arOneDiscount['VALUE_TYPE']) {
                        case CCatalogDiscountSave::TYPE_PERCENT:
                            $dblPriceTmp = round($dblCurrentPrice * (1 - $arOneDiscount['VALUE'] / 100.0), CATALOG_VALUE_PRECISION);

                            break;

                        case CCatalogDiscountSave::TYPE_FIX:
                            if ($arOneDiscount['DISCOUNT_CONVERT'] > $dblCurrentPrice) {
                                $boolDelete = true;
                            } else {
                                $dblPriceTmp = $dblCurrentPrice - $arOneDiscount['DISCOUNT_CONVERT'];
                            }

                            break;
                    }
                    if (!$boolDelete) {
                        if (-1 === $dblMinPrice || $dblMinPrice > $dblPriceTmp) {
                            $dblMinPrice = $dblPriceTmp;
                            $strMinKey = $strDiscountKey;
                            $boolApply = true;
                        }
                    }
                }
                if ($boolApply) {
                    $arParams['PRICE'] = $dblMinPrice;
                    $arResultDiscount[] = $arDiscSave[$strMinKey];
                }
                $boolResult = true;
            }
        }

        return $boolResult;
    }

    /**
     * @deprecated deprecated since catalog 17.0.11
     * @see \Bitrix\Catalog\Product\Price\Calculation::setConfig()
     *
     * @param string $currency
     */
    public static function setUsedCurrency($currency)
    {
        Catalog\Product\Price\Calculation::setConfig(['CURRENCY' => $currency]);
    }

    /**
     * @deprecated deprecated since catalog 17.0.11
     * @see \Bitrix\Catalog\Product\Price\Calculation::getConfig()
     *
     * @return null|string
     */
    public static function getUsedCurrency()
    {
        $config = Catalog\Product\Price\Calculation::getConfig();

        return $config['CURRENCY'];
    }

    /**
     * @deprecated deprecated since catalog 17.0.11
     * @see \Bitrix\Catalog\Product\Price\Calculation::setConfig()
     */
    public static function clearUsedCurrency()
    {
        Catalog\Product\Price\Calculation::setConfig(['CURRENCY' => null]);
    }

    /**
     * @deprecated deprecated since catalog 17.5.1
     * @see \Bitrix\Catalog\Product\Price\Calculation::setConfig()
     *
     * @param bool $mode
     */
    public static function setPriceVatIncludeMode($mode)
    {
        Catalog\Product\Price\Calculation::setConfig(['RESULT_WITH_VAT' => $mode]);
    }

    /**
     * @deprecated deprecated since catalog 17.5.1
     * @see \Bitrix\Catalog\Product\Price\Calculation::setConfig()
     *
     * @return bool
     */
    public static function getPriceVatIncludeMode()
    {
        return Catalog\Product\Price\Calculation::isIncludingVat();
    }

    /**
     * @deprecated deprecated since catalog 17.5.1
     * @see \Bitrix\Catalog\Product\Price\Calculation::setConfig()
     *
     * @param bool $use
     */
    public static function setUseDiscount($use)
    {
        Catalog\Product\Price\Calculation::setConfig(['USE_DISCOUNTS' => $use]);
    }

    /**
     * @deprecated deprecated since catalog 17.5.1
     * @see \Bitrix\Catalog\Product\Price\Calculation::getConfig()
     *
     * @return bool
     */
    public static function getUseDiscount()
    {
        return Catalog\Product\Price\Calculation::isAllowedUseDiscounts();
    }

    /**
     * @deprecated deprecated since catalog 17.6.3
     */
    public static function ClearCache()
    {
        self::$vatCache = [];
    }

    /**
     * @param array $product
     *
     * @return bool
     */
    public static function isAvailable($product)
    {
        $result = true;
        if (!empty($product) && is_array($product)) {
            if (isset($product['QUANTITY'], $product['QUANTITY_TRACE'], $product['CAN_BUY_ZERO'])) {
                $result = !((float) $product['QUANTITY'] <= 0 && 'Y' === $product['QUANTITY_TRACE'] && 'N' === $product['CAN_BUY_ZERO']);
            }
        }

        return $result;
    }

    /**
     * @deprecated deprecated since catalog 15.5.2
     * @see \Bitrix\Catalog\Model\Product::getCacheItem()
     *
     * @param int $intID
     *
     * @return bool
     */
    public static function IsExistProduct($intID)
    {
        $data = self::getCacheItem($intID, true);

        return !empty($data);
    }

    public static function CheckFields($ACTION, &$arFields, $ID = 0)
    {
        global $APPLICATION;

        $arMsg = [];
        $boolResult = true;

        $ACTION = mb_strtoupper($ACTION);
        $ID = (int) $ID;
        if ('ADD' === $ACTION && (!is_set($arFields, 'ID') || (int) $arFields['ID'] <= 0)) {
            $arMsg[] = ['id' => 'ID', 'text' => Loc::getMessage('KGP_EMPTY_ID')];
            $boolResult = false;
        }
        if ('ADD' !== $ACTION && $ID <= 0) {
            $arMsg[] = ['id' => 'ID', 'text' => Loc::getMessage('KGP_EMPTY_ID')];
            $boolResult = false;
        }

        $clearFields = [
            'NEGATIVE_AMOUNT_TRACE',
            '~NEGATIVE_AMOUNT_TRACE',
            '~TYPE',
            '~AVAILABLE',
        ];
        if ('UPDATE' === $ACTION) {
            $clearFields[] = 'ID';
            $clearFields[] = '~ID';
        }
        if ('ADD' === $ACTION) {
            $clearFields[] = 'BUNDLE';
            $clearFields[] = '~BUNDLE';
        }

        foreach ($clearFields as $fieldName) {
            if (array_key_exists($fieldName, $arFields)) {
                unset($arFields[$fieldName]);
            }
        }
        unset($fieldName, $clearFields);

        if ('ADD' === $ACTION) {
            if (!array_key_exists('SUBSCRIBE', $arFields)) {
                $arFields['SUBSCRIBE'] = '';
            }
            if (!isset($arFields['TYPE'])) {
                $arFields['TYPE'] = ProductTable::TYPE_PRODUCT;
            }
            $arFields['BUNDLE'] = ProductTable::STATUS_NO;
        }

        if (is_set($arFields, 'ID') || 'ADD' === $ACTION) {
            $arFields['ID'] = (int) $arFields['ID'];
        }
        if (is_set($arFields, 'QUANTITY') || 'ADD' === $ACTION) {
            $arFields['QUANTITY'] = (float) $arFields['QUANTITY'];
        }
        if (is_set($arFields, 'QUANTITY_RESERVED') || 'ADD' === $ACTION) {
            $arFields['QUANTITY_RESERVED'] = (float) $arFields['QUANTITY_RESERVED'];
        }
        if (is_set($arFields, 'OLD_QUANTITY')) {
            $arFields['OLD_QUANTITY'] = (float) $arFields['OLD_QUANTITY'];
        }
        if (is_set($arFields, 'WEIGHT') || 'ADD' === $ACTION) {
            $arFields['WEIGHT'] = (float) $arFields['WEIGHT'];
        }
        if (is_set($arFields, 'WIDTH') || 'ADD' === $ACTION) {
            $arFields['WIDTH'] = (float) $arFields['WIDTH'];
        }
        if (is_set($arFields, 'LENGTH') || 'ADD' === $ACTION) {
            $arFields['LENGTH'] = (float) $arFields['LENGTH'];
        }
        if (is_set($arFields, 'HEIGHT') || 'ADD' === $ACTION) {
            $arFields['HEIGHT'] = (float) $arFields['HEIGHT'];
        }

        if (is_set($arFields, 'VAT_ID') || 'ADD' === $ACTION) {
            $arFields['VAT_ID'] = (int) $arFields['VAT_ID'];
        }
        if ((is_set($arFields, 'VAT_INCLUDED') || 'ADD' === $ACTION) && ('Y' !== $arFields['VAT_INCLUDED'])) {
            $arFields['VAT_INCLUDED'] = 'N';
        }

        if ((is_set($arFields, 'QUANTITY_TRACE') || 'ADD' === $ACTION) && ('Y' !== $arFields['QUANTITY_TRACE'] && 'N' !== $arFields['QUANTITY_TRACE'])) {
            $arFields['QUANTITY_TRACE'] = 'D';
        }
        if ((is_set($arFields, 'CAN_BUY_ZERO') || 'ADD' === $ACTION) && ('Y' !== $arFields['CAN_BUY_ZERO'] && 'N' !== $arFields['CAN_BUY_ZERO'])) {
            $arFields['CAN_BUY_ZERO'] = 'D';
        }
        if (isset($arFields['CAN_BUY_ZERO'])) {
            $arFields['NEGATIVE_AMOUNT_TRACE'] = $arFields['CAN_BUY_ZERO'];
        }

        if ((is_set($arFields, 'PRICE_TYPE') || 'ADD' === $ACTION) && ('R' !== $arFields['PRICE_TYPE']) && ('T' !== $arFields['PRICE_TYPE'])) {
            $arFields['PRICE_TYPE'] = 'S';
        }

        if ((is_set($arFields, 'RECUR_SCHEME_TYPE') || 'ADD' === $ACTION) && ('' === $arFields['RECUR_SCHEME_TYPE'] || !in_array($arFields['RECUR_SCHEME_TYPE'], ProductTable::getPaymentPeriods(false), true))) {
            $arFields['RECUR_SCHEME_TYPE'] = self::TIME_PERIOD_DAY;
        }

        if ((is_set($arFields, 'RECUR_SCHEME_LENGTH') || 'ADD' === $ACTION) && ((int) $arFields['RECUR_SCHEME_LENGTH'] <= 0)) {
            $arFields['RECUR_SCHEME_LENGTH'] = 0;
        }

        if ((is_set($arFields, 'TRIAL_PRICE_ID') || 'ADD' === $ACTION) && ((int) $arFields['TRIAL_PRICE_ID'] <= 0)) {
            $arFields['TRIAL_PRICE_ID'] = false;
        }

        if ((is_set($arFields, 'WITHOUT_ORDER') || 'ADD' === $ACTION) && ('Y' !== $arFields['WITHOUT_ORDER'])) {
            $arFields['WITHOUT_ORDER'] = 'N';
        }

        if ((is_set($arFields, 'SELECT_BEST_PRICE') || 'ADD' === $ACTION) && ('N' !== $arFields['SELECT_BEST_PRICE'])) {
            $arFields['SELECT_BEST_PRICE'] = 'Y';
        }

        $existPurchasingPrice = array_key_exists('PURCHASING_PRICE', $arFields);
        $existPurchasingCurrency = array_key_exists('PURCHASING_CURRENCY', $arFields);
        if ('ADD' === $ACTION) {
            $purchasingPrice = false;
            $purchasingCurrency = false;

            if ($existPurchasingPrice) {
                $purchasingPrice = static::checkPriceValue($arFields['PURCHASING_PRICE']);
                if (false !== $purchasingPrice) {
                    $purchasingCurrency = static::checkPriceCurrency($arFields['PURCHASING_CURRENCY']);
                    if (false === $purchasingCurrency) {
                        $arMsg[] = ['id' => 'PURCHASING_CURRENCY', 'text' => Loc::getMessage('BT_MOD_CATALOG_PROD_ERR_COST_CURRENCY')];
                        $boolResult = false;
                    }
                }
            }

            $arFields['PURCHASING_PRICE'] = $purchasingPrice;
            $arFields['PURCHASING_CURRENCY'] = $purchasingCurrency;
            unset($purchasingCurrency, $purchasingPrice);
        } else {
            if ($existPurchasingPrice || $existPurchasingCurrency) {
                if ($existPurchasingPrice) {
                    $arFields['PURCHASING_PRICE'] = static::checkPriceValue($arFields['PURCHASING_PRICE']);
                    if (false === $arFields['PURCHASING_PRICE']) {
                        $arFields['PURCHASING_CURRENCY'] = false;
                    } else {
                        if ($existPurchasingCurrency) {
                            $purchasingCurrency = static::checkPriceCurrency($arFields['PURCHASING_CURRENCY']);
                            if (false === $purchasingCurrency) {
                                $arMsg[] = ['id' => 'PURCHASING_CURRENCY', 'text' => Loc::getMessage('BT_MOD_CATALOG_PROD_ERR_COST_CURRENCY')];
                                $boolResult = false;
                            } else {
                                $arFields['PURCHASING_CURRENCY'] = $purchasingCurrency;
                            }
                            unset($purchasingCurrency);
                        }
                    }
                } elseif ($existPurchasingCurrency) {
                    $purchasingCurrency = static::checkPriceCurrency($arFields['PURCHASING_CURRENCY']);
                    if (false === $purchasingCurrency) {
                        $arMsg[] = ['id' => 'PURCHASING_CURRENCY', 'text' => Loc::getMessage('BT_MOD_CATALOG_PROD_ERR_COST_CURRENCY')];
                        $boolResult = false;
                    } else {
                        $arFields['PURCHASING_CURRENCY'] = $purchasingCurrency;
                    }
                    unset($purchasingCurrency);
                }
            }
        }
        unset($existPurchasingCurrency, $existPurchasingPrice);

        if ((is_set($arFields, 'BARCODE_MULTI') || 'ADD' === $ACTION) && 'Y' !== $arFields['BARCODE_MULTI']) {
            $arFields['BARCODE_MULTI'] = 'N';
        }
        if (array_key_exists('SUBSCRIBE', $arFields)) {
            if ('Y' !== $arFields['SUBSCRIBE'] && 'N' !== $arFields['SUBSCRIBE']) {
                $arFields['SUBSCRIBE'] = 'D';
            }
        }
        if (array_key_exists('BUNDLE', $arFields)) {
            $arFields['BUNDLE'] = (ProductTable::STATUS_YES === $arFields['BUNDLE'] ? ProductTable::STATUS_YES : ProductTable::STATUS_NO);
        }

        if ($boolResult) {
            $availableFieldsList = [
                'QUANTITY',
                'QUANTITY_TRACE',
                'CAN_BUY_ZERO',
            ];
            $needCalculateAvailable = false;
            $copyFields = $arFields;
            if (isset($copyFields['QUANTITY_TRACE']) && 'D' === $copyFields['QUANTITY_TRACE']) {
                $copyFields['QUANTITY_TRACE'] = Main\Config\Option::get('catalog', 'default_quantity_trace');
            }
            if (isset($copyFields['CAN_BUY_ZERO']) && 'D' === $copyFields['CAN_BUY_ZERO']) {
                $copyFields['CAN_BUY_ZERO'] = Main\Config\Option::get('catalog', 'default_can_buy_zero');
            }

            if (!isset($arFields['AVAILABLE'])) {
                if (
                    !isset($arFields['TYPE'])
                    || ProductTable::TYPE_PRODUCT === $arFields['TYPE']
                    || ProductTable::TYPE_OFFER === $arFields['TYPE']
                    || ProductTable::TYPE_FREE_OFFER === $arFields['TYPE']
                ) {
                    if (
                        'ADD' === $ACTION
                        && (
                            ProductTable::TYPE_PRODUCT === $arFields['TYPE']
                            || ProductTable::TYPE_OFFER === $arFields['TYPE']
                        )
                    ) {
                        $needCalculateAvailable = true;
                    } elseif ('UPDATE' === $ACTION) {
                        $needFields = [];
                        foreach ($availableFieldsList as $availableField) {
                            if (isset($arFields[$availableField])) {
                                $needCalculateAvailable = true;
                            } else {
                                $needFields[] = $availableField;
                            }
                        }
                        unset($availableField);
                        if ($needCalculateAvailable && !empty($needFields)) {
                            $productIterator = ProductTable::getList([
                                'select' => $needFields,
                                'filter' => ['=ID' => $ID],
                            ]);
                            $product = $productIterator->fetch();
                            unset($productIterator);
                            if (!empty($product) && is_array($product)) {
                                foreach ($availableFieldsList as $availableField) {
                                    if (isset($copyFields[$availableField])) {
                                        continue;
                                    }
                                    $copyFields[$availableField] = $product[$availableField];
                                }
                                unset($availableField);
                            }
                            unset($product);
                        }
                        unset($needFields);
                    }
                } elseif (ProductTable::TYPE_SKU === $arFields['TYPE']) {
                    $offerList = CCatalogSku::getOffersList([$ID], 0, ['ACTIVE' => 'Y'], ['ID']);
                    if (!empty($offerList[$ID])) {
                        $skuAvailable = false;
                        $offerIterator = ProductTable::getList([
                            'select' => ['ID', 'QUANTITY', 'QUANTITY_TRACE', 'CAN_BUY_ZERO'],
                            'filter' => ['@ID' => array_keys($offerList[$ID])],
                        ]);
                        while ($offer = $offerIterator->fetch()) {
                            if (ProductTable::STATUS_YES === ProductTable::calculateAvailable($offer)) {
                                $skuAvailable = true;
                            }
                        }
                        unset($offer, $offerIterator);
                        if ($skuAvailable) {
                            $arFields['AVAILABLE'] = 'Y';
                            $arFields['QUANTITY'] = '0';
                            $arFields['QUANTITY_TRACE'] = 'N';
                            $arFields['CAN_BUY'] = 'Y';
                        } else {
                            $arFields['AVAILABLE'] = 'N';
                            $arFields['QUANTITY'] = '0';
                            $arFields['QUANTITY_TRACE'] = 'Y';
                            $arFields['CAN_BUY'] = 'N';
                        }
                    } else {
                        $arFields['AVAILABLE'] = 'N';
                    }
                    unset($offerList);
                }
            }
            if ($needCalculateAvailable) {
                $arFields['AVAILABLE'] = ProductTable::calculateAvailable($copyFields);
            }
            unset($copyFields);
        }

        if (!$boolResult) {
            $obError = new CAdminException($arMsg);
            $APPLICATION->ThrowException($obError);
        }

        return $boolResult;
    }

    /**
     * @deprecated deprecated since catalog 17.6.0
     * @see Product::add
     *
     * @param array $fields
     * @param bool  $checkExist
     *
     * @return bool
     */
    public static function Add($fields, $checkExist = true)
    {
        $existProduct = false;
        $checkExist = (false !== $checkExist);

        if (empty($fields['ID'])) {
            return false;
        }
        $fields['ID'] = (int) $fields['ID'];
        if ($fields['ID'] <= 0) {
            return false;
        }

        if ($checkExist) {
            $data = self::getCacheItem($fields['ID'], true);
            if (!empty($data)) {
                $existProduct = !empty($data['ID']);
            }
            unset($data);
        }

        self::normalizeFields($fields);

        if ($existProduct) {
            $result = self::update($fields['ID'], $fields);
        } else {
            $result = self::add($fields);
        }
        $success = $result->isSuccess();
        if (!$success) {
            self::convertErrors($result);
        }
        unset($result);

        return $success;
    }

    /**
     * @deprecated deprecated since catalog 17.6.0
     * @see Product::update
     *
     * @param int   $id
     * @param array $fields
     *
     * @return bool
     */
    public static function Update($id, $fields)
    {
        $id = (int) $id;
        if ($id <= 0) {
            return false;
        }
        if (!is_array($fields)) {
            return false;
        }

        self::normalizeFields($fields);

        $result = self::update($id, $fields);
        $success = $result->isSuccess();
        if (!$success) {
            self::convertErrors($result);
        }
        unset($result);

        return $success;
    }

    /**
     * @deprecated deprecated since catalog 17.6.0
     * @see Product::delete
     *
     * @param int $id
     *
     * @return bool
     */
    public static function Delete($id)
    {
        $id = (int) $id;
        if ($id <= 0) {
            return false;
        }

        $result = self::delete($id);

        return $result->isSuccess();
    }

    public static function ParseQueryBuildField($field)
    {
        $field = (string) $field;
        if ('' === $field) {
            return false;
        }
        $field = mb_strtoupper($field);
        if (0 !== strncmp($field, 'CATALOG_', 8)) {
            return false;
        }

        $iNum = 0;
        $field = mb_substr($field, 8);
        $p = mb_strrpos($field, '_');
        if (false !== $p && $p > 0) {
            $iNum = (int) mb_substr($field, $p + 1);
            if ($iNum > 0) {
                $field = mb_substr($field, 0, $p);
            }
        }

        return [
            'FIELD' => $field,
            'NUM' => $iNum,
        ];
    }

    /**
     * @deprecated deprecated since catalog 17.6.2
     * @see Product::getList
     *
     * @param int $ID
     *
     * @return array|false
     */
    public static function GetByID($ID)
    {
        $ID = (int) $ID;
        if ($ID <= 0) {
            return false;
        }

        $iterator = self::getList([
            'select' => [
                'ID', 'QUANTITY', 'QUANTITY_RESERVED', 'QUANTITY_TRACE', 'QUANTITY_TRACE_ORIG', 'WEIGHT', 'WIDTH', 'LENGTH', 'HEIGHT', 'MEASURE',
                'VAT_ID', 'VAT_INCLUDED', 'CAN_BUY_ZERO', 'CAN_BUY_ZERO_ORIG', 'NEGATIVE_AMOUNT_TRACE', 'NEGATIVE_AMOUNT_TRACE_ORIG',
                'PRICE_TYPE', 'RECUR_SCHEME_TYPE', 'RECUR_SCHEME_LENGTH', 'TRIAL_PRICE_ID', 'WITHOUT_ORDER', 'SELECT_BEST_PRICE',
                'TMP_ID', 'PURCHASING_PRICE', 'PURCHASING_CURRENCY', 'BARCODE_MULTI', 'SUBSCRIBE', 'SUBSCRIBE_ORIG',
                'TYPE', 'BUNDLE', 'AVAILABLE', 'TIMESTAMP_X',
            ],
            'filter' => ['=ID' => $ID],
        ]);
        $result = $iterator->fetch();
        unset($iterator);
        if (empty($result)) {
            return false;
        }
        if (null !== $result['TIMESTAMP_X'] && $result['TIMESTAMP_X'] instanceof Main\Type\DateTime) {
            $result['TIMESTAMP_X'] = $result['TIMESTAMP_X']->toString();
        }

        return $result;
    }

    /**
     * @deprecated deprecated since catalog 17.6.0
     *
     * @param bool $boolAllValues
     *
     * @return array|bool
     */
    public static function GetByIDEx($ID, $boolAllValues = false)
    {
        $boolAllValues = (true === $boolAllValues);
        $ID = (int) $ID;
        if ($ID <= 0) {
            return false;
        }
        $arFilter = ['ID' => $ID, 'ACTIVE' => 'Y', 'ACTIVE_DATE' => 'Y'];

        $dbIBlockElement = CIBlockElement::GetList([], $arFilter);
        if ($arIBlockElement = $dbIBlockElement->GetNext()) {
            if ($arIBlock = CIBlock::GetArrayByID($arIBlockElement['IBLOCK_ID'])) {
                $arIBlockElement['IBLOCK_ID'] = $arIBlock['ID'];
                $arIBlockElement['IBLOCK_NAME'] = htmlspecialcharsbx($arIBlock['NAME']);
                $arIBlockElement['~IBLOCK_NAME'] = $arIBlock['NAME'];
                $arIBlockElement['PROPERTIES'] = false;
                $dbProps = CIBlockElement::GetProperty($arIBlock['ID'], $ID, 'sort', 'asc', ['ACTIVE' => 'Y', 'NON_EMPTY' => 'Y']);
                if ($arProp = $dbProps->Fetch()) {
                    $arAllProps = [];
                    do {
                        $strID = ('' !== $arProp['CODE'] ? $arProp['CODE'] : $arProp['ID']);
                        if (is_array($arProp['VALUE'])) {
                            foreach ($arProp['VALUE'] as &$strOneValue) {
                                $strOneValue = htmlspecialcharsbx($strOneValue);
                            }
                            if (isset($strOneValue)) {
                                unset($strOneValue);
                            }
                        } else {
                            $arProp['VALUE'] = htmlspecialcharsbx($arProp['VALUE']);
                        }

                        if (is_array($arProp['DEFAULT_VALUE'])) {
                            foreach ($arProp['DEFAULT_VALUE'] as $index => $value) {
                                if (is_string($value)) {
                                    $arProp['DEFAULT_VALUE'][$index] = htmlspecialcharsbx($value);
                                }
                            }
                        } else {
                            $arProp['DEFAULT_VALUE'] = htmlspecialcharsbx($arProp['DEFAULT_VALUE']);
                        }

                        if ($boolAllValues && 'Y' === $arProp['MULTIPLE']) {
                            if (!isset($arAllProps[$strID])) {
                                $arAllProps[$strID] = [
                                    'NAME' => htmlspecialcharsbx($arProp['NAME']),
                                    'VALUE' => [$arProp['VALUE']],
                                    'VALUE_ENUM' => [htmlspecialcharsbx($arProp['VALUE_ENUM'])],
                                    'VALUE_XML_ID' => [htmlspecialcharsbx($arProp['VALUE_XML_ID'])],
                                    'DEFAULT_VALUE' => $arProp['DEFAULT_VALUE'],
                                    'SORT' => htmlspecialcharsbx($arProp['SORT']),
                                    'MULTIPLE' => $arProp['MULTIPLE'],
                                ];
                            } else {
                                $arAllProps[$strID]['VALUE'][] = $arProp['VALUE'];
                                $arAllProps[$strID]['VALUE_ENUM'][] = htmlspecialcharsbx($arProp['VALUE_ENUM']);
                                $arAllProps[$strID]['VALUE_XML_ID'][] = htmlspecialcharsbx($arProp['VALUE_XML_ID']);
                            }
                        } else {
                            $arAllProps[$strID] = [
                                'NAME' => htmlspecialcharsbx($arProp['NAME']),
                                'VALUE' => $arProp['VALUE'],
                                'VALUE_ENUM' => htmlspecialcharsbx($arProp['VALUE_ENUM']),
                                'VALUE_XML_ID' => htmlspecialcharsbx($arProp['VALUE_XML_ID']),
                                'DEFAULT_VALUE' => $arProp['DEFAULT_VALUE'],
                                'SORT' => htmlspecialcharsbx($arProp['SORT']),
                                'MULTIPLE' => $arProp['MULTIPLE'],
                            ];
                        }
                    } while ($arProp = $dbProps->Fetch());

                    $arIBlockElement['PROPERTIES'] = $arAllProps;
                }

                // bugfix: 2007-07-31 by Sigurd
                $arIBlockElement['PRODUCT'] = CCatalogProduct::GetByID($ID);

                $dbPrices = CPrice::GetList(['SORT' => 'ASC'], ['PRODUCT_ID' => $ID]);
                if ($arPrices = $dbPrices->Fetch()) {
                    $arAllPrices = [];
                    do {
                        $arAllPrices[$arPrices['CATALOG_GROUP_ID']] = [
                            'EXTRA_ID' => (int) $arPrices['EXTRA_ID'],
                            'PRICE' => (float) $arPrices['PRICE'],
                            'CURRENCY' => htmlspecialcharsbx($arPrices['CURRENCY']),
                        ];
                    } while ($arPrices = $dbPrices->Fetch());

                    $arIBlockElement['PRICES'] = $arAllPrices;
                }

                return $arIBlockElement;
            }
        }

        return false;
    }

    /**
     * @deprecated deprecated since catalog 15.0.0
     * @see CCatalogProductProvider
     *
     * @param int       $ProductID
     * @param float|int $DeltaQuantity
     *
     * @return bool
     */
    public static function QuantityTracer($ProductID, $DeltaQuantity)
    {
        $boolClearCache = false;

        $ProductID = (int) $ProductID;
        if ($ProductID <= 0) {
            return false;
        }
        $DeltaQuantity = (float) $DeltaQuantity;
        if (0 === $DeltaQuantity) {
            return false;
        }

        $rsProducts = CCatalogProduct::GetList(
            [],
            ['ID' => $ProductID],
            false,
            false,
            ['ID', 'CAN_BUY_ZERO', 'NEGATIVE_AMOUNT_TRACE', 'QUANTITY_TRACE', 'QUANTITY', 'ELEMENT_IBLOCK_ID']
        );
        if (($arProduct = $rsProducts->Fetch())
            && ('Y' === $arProduct['QUANTITY_TRACE'])) {
            $strAllowNegativeAmount = $arProduct['NEGATIVE_AMOUNT_TRACE'];

            $arFields = [];
            $arFields['QUANTITY'] = (float) $arProduct['QUANTITY'] - $DeltaQuantity;

            if ('Y' !== $arProduct['CAN_BUY_ZERO']) {
                if (defined('BX_COMP_MANAGED_CACHE')) {
                    $boolClearCache = (0 >= $arFields['QUANTITY'] * $arProduct['QUANTITY']);
                }
            }

            if ('Y' !== $arProduct['CAN_BUY_ZERO'] || 'Y' !== $strAllowNegativeAmount) {
                if (0 >= $arFields['QUANTITY']) {
                    $arFields['QUANTITY'] = 0;
                }
            }

            $arFields['OLD_QUANTITY'] = $arProduct['QUANTITY'];
            CCatalogProduct::Update($arProduct['ID'], $arFields);

            if ($boolClearCache) {
                CIBlock::clearIblockTagCache($arProduct['ELEMENT_IBLOCK_ID']);
            }

            $arProduct['OLD_QUANTITY'] = $arFields['OLD_QUANTITY'];
            $arProduct['QUANTITY'] = $arFields['QUANTITY'];
            $arProduct['ALLOW_NEGATIVE_AMOUNT'] = $strAllowNegativeAmount;
            $arProduct['DELTA'] = $DeltaQuantity;
            foreach (GetModuleEvents('catalog', 'OnProductQuantityTrace', true) as $arEvent) {
                ExecuteModuleEventEx($arEvent, [$arProduct['ID'], $arProduct]);
            }

            return true;
        }

        return false;
    }

    /**
     * @param int       $productID
     * @param float|int $quantity
     * @param array     $arUserGroups
     *
     * @return bool|float|int
     */
    public static function GetNearestQuantityPrice($productID, $quantity = 1, $arUserGroups = [])
    {
        static $eventOnGetExists = null;
        static $eventOnResultExists = null;

        global $APPLICATION;

        if (true === $eventOnGetExists || null === $eventOnGetExists) {
            foreach (GetModuleEvents('catalog', 'OnGetNearestQuantityPrice', true) as $arEvent) {
                $eventOnGetExists = true;
                $mxResult = ExecuteModuleEventEx(
                    $arEvent,
                    [
                        $productID,
                        $quantity,
                        $arUserGroups,
                    ]
                );
                if (true !== $mxResult) {
                    return $mxResult;
                }
            }
            if (null === $eventOnGetExists) {
                $eventOnGetExists = false;
            }
        }

        // Check input params
        $productID = (int) $productID;
        if ($productID <= 0) {
            $APPLICATION->ThrowException(Loc::getMessage('BT_MOD_CATALOG_PROD_ERR_PRODUCT_ID_ABSENT'), 'NO_PRODUCT_ID');

            return false;
        }

        $quantity = (float) $quantity;
        if ($quantity <= 0) {
            $APPLICATION->ThrowException(Loc::getMessage('BT_MOD_CATALOG_PROD_ERR_QUANTITY_ABSENT'), 'NO_QUANTITY');

            return false;
        }

        if (!is_array($arUserGroups) && (int) $arUserGroups.'|' === (string) $arUserGroups.'|') {
            $arUserGroups = [(int) $arUserGroups];
        }

        if (!is_array($arUserGroups)) {
            $arUserGroups = [];
        }

        if (!in_array(2, $arUserGroups, true)) {
            $arUserGroups[] = 2;
        }

        $quantityDifference = -1;
        $nearestQuantity = -1;

        // Find nearest quantity
        $priceTypeList = self::getAllowedPriceTypes($arUserGroups);
        if (empty($priceTypeList)) {
            return false;
        }

        $iterator = Catalog\PriceTable::getList([
            'select' => ['ID', 'QUANTITY_FROM', 'QUANTITY_TO'],
            'filter' => [
                '=PRODUCT_ID' => $productID,
                '@CATALOG_GROUP_ID' => $priceTypeList,
            ],
        ]);
        while ($arPriceList = $iterator->fetch()) {
            $arPriceList['QUANTITY_FROM'] = (float) $arPriceList['QUANTITY_FROM'];
            $arPriceList['QUANTITY_TO'] = (float) $arPriceList['QUANTITY_TO'];
            if ($quantity >= $arPriceList['QUANTITY_FROM']
                && ($quantity <= $arPriceList['QUANTITY_TO'] || 0 === $arPriceList['QUANTITY_TO'])) {
                $nearestQuantity = $quantity;

                break;
            }

            if ($quantity < $arPriceList['QUANTITY_FROM']) {
                $nearestQuantity_tmp = $arPriceList['QUANTITY_FROM'];
                $quantityDifference_tmp = $arPriceList['QUANTITY_FROM'] - $quantity;
            } else {
                $nearestQuantity_tmp = $arPriceList['QUANTITY_TO'];
                $quantityDifference_tmp = $quantity - $arPriceList['QUANTITY_TO'];
            }

            if ($quantityDifference < 0 || $quantityDifference_tmp < $quantityDifference) {
                $quantityDifference = $quantityDifference_tmp;
                $nearestQuantity = $nearestQuantity_tmp;
            }
        }
        unset($arPriceList, $iterator, $priceTypeList);

        if (true === $eventOnResultExists || null === $eventOnResultExists) {
            foreach (GetModuleEvents('catalog', 'OnGetNearestQuantityPriceResult', true) as $arEvent) {
                $eventOnResultExists = true;
                if (false === ExecuteModuleEventEx($arEvent, [&$nearestQuantity])) {
                    return false;
                }
            }
            if (null === $eventOnResultExists) {
                $eventOnResultExists = false;
            }
        }

        return $nearestQuantity > 0 ? $nearestQuantity : false;
    }

    /**
     * @param int         $intProductID
     * @param float|int   $quantity
     * @param array       $arUserGroups
     * @param string      $renewal
     * @param array       $priceList
     * @param bool|string $siteID
     * @param array|bool  $arDiscountCoupons
     *
     * @return array|bool
     */
    public static function GetOptimalPrice($intProductID, $quantity = 1, $arUserGroups = [], $renewal = 'N', $priceList = [], $siteID = false, $arDiscountCoupons = false)
    {
        static $eventOnGetExists = null;
        static $eventOnResultExists = null;

        global $APPLICATION;

        if (true === $eventOnGetExists || null === $eventOnGetExists) {
            foreach (GetModuleEvents('catalog', 'OnGetOptimalPrice', true) as $arEvent) {
                $mxResult = ExecuteModuleEventEx(
                    $arEvent,
                    [
                        $intProductID,
                        $quantity,
                        $arUserGroups,
                        $renewal,
                        $priceList,
                        $siteID,
                        $arDiscountCoupons,
                    ]
                );
                if (null === $mxResult) {
                    continue;
                }
                $eventOnGetExists = true;
                if (true !== $mxResult) {
                    self::updateUserHandlerOptimalPrice(
                        $mxResult,
                        ['PRODUCT_ID' => $intProductID]
                    );

                    return $mxResult;
                }
            }
            if (null === $eventOnGetExists) {
                $eventOnGetExists = false;
            }
        }

        $intProductID = (int) $intProductID;
        if ($intProductID <= 0) {
            $APPLICATION->ThrowException(Loc::getMessage('BT_MOD_CATALOG_PROD_ERR_PRODUCT_ID_ABSENT'), 'NO_PRODUCT_ID');

            return false;
        }

        $quantity = (float) $quantity;
        if ($quantity <= 0) {
            $APPLICATION->ThrowException(Loc::getMessage('BT_MOD_CATALOG_PROD_ERR_QUANTITY_ABSENT'), 'NO_QUANTITY');

            return false;
        }

        if (!is_array($arUserGroups) && (int) $arUserGroups.'|' === (string) $arUserGroups.'|') {
            $arUserGroups = [(int) $arUserGroups];
        }

        if (!is_array($arUserGroups)) {
            $arUserGroups = [];
        }

        if (!in_array(2, $arUserGroups, true)) {
            $arUserGroups[] = 2;
        }
        Main\Type\Collection::normalizeArrayValuesByInt($arUserGroups);

        $renewal = ('Y' === $renewal ? 'Y' : 'N');

        if (false === $siteID) {
            $siteID = SITE_ID;
        }

        $resultCurrency = Catalog\Product\Price\Calculation::getCurrency();
        if (empty($resultCurrency)) {
            $APPLICATION->ThrowException(Loc::getMessage('BT_MOD_CATALOG_PROD_ERR_NO_RESULT_CURRENCY'));

            return false;
        }

        $intIBlockID = (int) CIBlockElement::GetIBlockByID($intProductID);
        if ($intIBlockID <= 0) {
            $APPLICATION->ThrowException(
                Loc::getMessage(
                    'BT_MOD_CATALOG_PROD_ERR_ELEMENT_ID_NOT_FOUND',
                    ['#ID#' => $intProductID]
                ),
                'NO_ELEMENT'
            );

            return false;
        }

        if (!isset($priceList) || !is_array($priceList)) {
            $priceList = [];
        }

        if (empty($priceList)) {
            $priceTypeList = self::getAllowedPriceTypes($arUserGroups);
            if (empty($priceTypeList)) {
                return false;
            }

            $iterator = Catalog\PriceTable::getList([
                'select' => ['ID', 'CATALOG_GROUP_ID', 'PRICE', 'CURRENCY', 'PRICE_SCALE'],
                'filter' => [
                    '=PRODUCT_ID' => $intProductID,
                    '@CATALOG_GROUP_ID' => $priceTypeList,
                    [
                        'LOGIC' => 'OR',
                        '<=QUANTITY_FROM' => $quantity,
                        '=QUANTITY_FROM' => null,
                    ],
                    [
                        'LOGIC' => 'OR',
                        '>=QUANTITY_TO' => $quantity,
                        '=QUANTITY_TO' => null,
                    ],
                ],
                'order' => ['CATALOG_GROUP_ID' => 'ASC'],
            ]);
            while ($row = $iterator->fetch()) {
                $row['ELEMENT_IBLOCK_ID'] = $intIBlockID;
                $priceList[] = $row;
            }
            unset($row, $iterator, $priceTypeList);
        } else {
            foreach (array_keys($priceList) as $priceIndex) {
                $priceList[$priceIndex]['ELEMENT_IBLOCK_ID'] = $intIBlockID;
            }
            unset($priceIndex);
        }

        if (empty($priceList)) {
            return false;
        }

        $vat = CCatalogProduct::GetVATDataByID($intProductID);
        if (!empty($vat)) {
            if ('N' === $vat['EXCLUDE_VAT']) {
                $vat['RATE'] *= 0.01;
            }
        } else {
            $vat = [
                'RATE' => null,
                'VAT_INCLUDED' => 'N',
                'EXCLUDE_VAT' => 'Y',
            ];
        }
        unset($iterator);

        $isNeedDiscounts = Catalog\Product\Price\Calculation::isAllowedUseDiscounts();
        $resultWithVat = Catalog\Product\Price\Calculation::isIncludingVat();
        if ($isNeedDiscounts) {
            if (false === $arDiscountCoupons) {
                $arDiscountCoupons = CCatalogDiscountCoupon::GetCoupons();
            }
        }

        $minimalPrice = [];

        if (null === self::$saleIncluded) {
            self::initSaleSettings();
        }
        $isNeedleToMinimizeCatalogGroup = self::isNeedleToMinimizeCatalogGroup($priceList);

        foreach ($priceList as $priceData) {
            $priceData['VAT_RATE'] = $vat['RATE'];
            $priceData['VAT_INCLUDED'] = $vat['VAT_INCLUDED'];
            $priceData['NO_VAT'] = $vat['EXCLUDE_VAT'];

            $currentPrice = (float) $priceData['PRICE'];
            if ('N' === $priceData['NO_VAT']) {
                if ('N' === $priceData['VAT_INCLUDED']) {
                    $currentPrice *= (1 + $priceData['VAT_RATE']);
                }
            }
            if ($priceData['CURRENCY'] !== $resultCurrency) {
                $currentPrice = CCurrencyRates::ConvertCurrency($currentPrice, $priceData['CURRENCY'], $resultCurrency);
            }
            $currentPrice = Catalog\Product\Price\Calculation::roundPrecision($currentPrice);

            $result = [
                'BASE_PRICE' => $currentPrice,
                'COMPARE_PRICE' => $currentPrice,
                'PRICE' => $currentPrice,
                'CURRENCY' => $resultCurrency,
                'DISCOUNT_LIST' => [],
                'RAW_PRICE' => $priceData,
            ];
            if ($isNeedDiscounts) {
                $arDiscounts = CCatalogDiscount::GetDiscount(
                    $intProductID,
                    $intIBlockID,
                    [$priceData['CATALOG_GROUP_ID']],
                    $arUserGroups,
                    $renewal,
                    $siteID,
                    $arDiscountCoupons
                );

                $discountResult = CCatalogDiscount::applyDiscountList($currentPrice, $resultCurrency, $arDiscounts);
                unset($arDiscounts);
                if (false === $discountResult) {
                    return false;
                }
                $result['PRICE'] = $discountResult['PRICE'];
                $result['COMPARE_PRICE'] = $discountResult['PRICE'];
                $result['DISCOUNT_LIST'] = $discountResult['DISCOUNT_LIST'];
                unset($discountResult);
            } elseif ($isNeedleToMinimizeCatalogGroup) {
                $calculateData = $priceData;
                $calculateData['PRICE'] = $currentPrice;
                $calculateData['CURRENCY'] = $resultCurrency;
                $possibleSalePrice = self::getPossibleSalePrice(
                    $intProductID,
                    $calculateData,
                    $quantity,
                    $siteID,
                    $arUserGroups,
                    $arDiscountCoupons
                );
                unset($calculateData);
                if (null === $possibleSalePrice) {
                    return false;
                }
                $result['COMPARE_PRICE'] = $possibleSalePrice;
                unset($possibleSalePrice);
            }

            if ('N' === $priceData['NO_VAT']) {
                if (!$resultWithVat) {
                    $result['PRICE'] /= (1 + $priceData['VAT_RATE']);
                    $result['COMPARE_PRICE'] /= (1 + $priceData['VAT_RATE']);
                    $result['BASE_PRICE'] /= (1 + $priceData['VAT_RATE']);
                }
            }

            $result['UNROUND_PRICE'] = $result['PRICE'];
            $result['UNROUND_BASE_PRICE'] = $result['BASE_PRICE'];
            if (Catalog\Product\Price\Calculation::isComponentResultMode()) {
                $result['BASE_PRICE'] = Catalog\Product\Price::roundPrice(
                    $priceData['CATALOG_GROUP_ID'],
                    $result['BASE_PRICE'],
                    $resultCurrency
                );
                $result['PRICE'] = Catalog\Product\Price::roundPrice(
                    $priceData['CATALOG_GROUP_ID'],
                    $result['PRICE'],
                    $resultCurrency
                );
                if (
                    empty($result['DISCOUNT_LIST'])
                    || Catalog\Product\Price\Calculation::compare($result['BASE_PRICE'], $result['PRICE'], '<=')
                ) {
                    $result['BASE_PRICE'] = $result['PRICE'];
                }
                $result['COMPARE_PRICE'] = $result['PRICE'];
            }

            if (empty($minimalPrice) || $minimalPrice['COMPARE_PRICE'] > $result['COMPARE_PRICE']) {
                $minimalPrice = $result;
            } elseif (
                $minimalPrice['COMPARE_PRICE'] === $result['COMPARE_PRICE']
                && $minimalPrice['RAW_PRICE']['PRICE_SCALE'] > $result['RAW_PRICE']['PRICE_SCALE']
            ) {
                $minimalPrice = $result;
            }

            unset($currentPrice, $result);
        }
        unset($priceData, $vat);

        $discountValue = ($minimalPrice['BASE_PRICE'] - $minimalPrice['PRICE']);

        if ('N' === $minimalPrice['RAW_PRICE']['NO_VAT']) {
            $vatIncluded = $resultWithVat ? 'Y' : 'N';
        } else {
            $vatIncluded = 'N';
        }
        unset($minimalPrice['RAW_PRICE']['PRICE_SCALE']);
        $arResult = [
            'PRICE' => $minimalPrice['RAW_PRICE'],
            'RESULT_PRICE' => [
                'ID' => $minimalPrice['RAW_PRICE']['ID'],
                'PRICE_TYPE_ID' => $minimalPrice['RAW_PRICE']['CATALOG_GROUP_ID'],
                'BASE_PRICE' => $minimalPrice['BASE_PRICE'],
                'DISCOUNT_PRICE' => $minimalPrice['PRICE'],
                'CURRENCY' => $resultCurrency,
                'DISCOUNT' => $discountValue,
                'PERCENT' => (
                    $minimalPrice['BASE_PRICE'] > 0 && $discountValue > 0
                    ? round((100 * $discountValue) / $minimalPrice['BASE_PRICE'], 0)
                    : 0
                ),
                'VAT_RATE' => $minimalPrice['RAW_PRICE']['VAT_RATE'],
                'VAT_INCLUDED' => $vatIncluded,
                'NO_VAT' => $minimalPrice['RAW_PRICE']['NO_VAT'],
                'UNROUND_BASE_PRICE' => $minimalPrice['UNROUND_BASE_PRICE'],
                'UNROUND_DISCOUNT_PRICE' => $minimalPrice['UNROUND_PRICE'],
            ],
            'DISCOUNT_PRICE' => $minimalPrice['PRICE'],
            'DISCOUNT' => [],
            'DISCOUNT_LIST' => [],
            'PRODUCT_ID' => $intProductID,
        ];
        if (!empty($minimalPrice['DISCOUNT_LIST'])) {
            reset($minimalPrice['DISCOUNT_LIST']);
            $arResult['DISCOUNT'] = current($minimalPrice['DISCOUNT_LIST']);
            $arResult['DISCOUNT_LIST'] = $minimalPrice['DISCOUNT_LIST'];
        }
        unset($minimalPrice);

        if (true === $eventOnResultExists || null === $eventOnResultExists) {
            foreach (GetModuleEvents('catalog', 'OnGetOptimalPriceResult', true) as $arEvent) {
                $eventOnResultExists = true;
                if (false === ExecuteModuleEventEx($arEvent, [&$arResult])) {
                    return false;
                }
            }
            if (null === $eventOnResultExists) {
                $eventOnResultExists = false;
            }
        }

        return $arResult;
    }

    public static function GetOptimalPriceList(array $products, $arUserGroups = [], $renewal = 'N', $priceList = [], $siteID = false, $needCoupons = true)
    {
        $needCoupons = (true === $needCoupons);

        $resultList = [];

        $iblockListId = [];
        $productIblockGetIdList = [];
        $ignoreList = [];

        $useDiscount = !CCatalogDiscount::isUsedSaleDiscountOnly();

        foreach ($products as $productId => $productData) {
            Catalog\Product\Price\Calculation::setConfig(
                [
                    'USE_DISCOUNTS' => (isset($productData['BUNDLE_CHILD']) && true === $productData['BUNDLE_CHILD'] ? false : $useDiscount),
                ]
            );

            foreach (GetModuleEvents('catalog', 'OnGetOptimalPrice', true) as $arEvent) {
                if (!empty($productData['QUANTITY_LIST'])) {
                    foreach ($productData['QUANTITY_LIST'] as $basketCode => $quantity) {
                        // TODO: remove this hack after refactoring new provider
                        if ($quantity <= 0) {
                            continue;
                        }
                        $mxResult = ExecuteModuleEventEx(
                            $arEvent,
                            [
                                $productId,
                                $quantity,
                                $arUserGroups,
                                $renewal,
                                $priceList,
                                $siteID,
                                $needCoupons ? false : [],
                            ]
                        );
                        if (null === $mxResult) {
                            continue 2;
                        }
                        if (true !== $mxResult) {
                            self::updateUserHandlerOptimalPrice(
                                $mxResult,
                                ['PRODUCT_ID' => $productId]
                            );
                            $resultList[$productId][$productData['BASKET_CODE']] = $mxResult;
                            $ignoreList[$productId.'|'.$quantity] = true;

                            continue 3;
                        }
                    }
                }
            }

            if (!empty($productData['QUANTITY_LIST'])) {
                foreach ($productData['QUANTITY_LIST'] as $basketCode => $quantity) {
                    $resultList[$productId][$basketCode] = false;
                }
            } else {
                $resultList[$productId][$productData['BASKET_CODE']] = false;
            }

            if (!isset($iblockListId[$productId]) && isset($productData['IBLOCK_ID']) && $productData['IBLOCK_ID'] > 0) {
                $iblockListId[$productId] = $productData['IBLOCK_ID'];
            }

            if (!isset($iblockListId[$productId])) {
                $productIblockGetIdList[] = $productId;
            }
        }

        global $APPLICATION;

        if (!is_array($arUserGroups) && (int) $arUserGroups.'|' === (string) $arUserGroups.'|') {
            $arUserGroups = [(int) $arUserGroups];
        }

        if (!is_array($arUserGroups)) {
            $arUserGroups = [];
        }

        if (!in_array(2, $arUserGroups, true)) {
            $arUserGroups[] = 2;
        }
        Main\Type\Collection::normalizeArrayValuesByInt($arUserGroups);

        $renewal = ('Y' === $renewal ? 'Y' : 'N');

        if (false === $siteID) {
            $siteID = SITE_ID;
        }

        $resultCurrency = Catalog\Product\Price\Calculation::getCurrency();
        if (empty($resultCurrency)) {
            $APPLICATION->ThrowException(Loc::getMessage('BT_MOD_CATALOG_PROD_ERR_NO_RESULT_CURRENCY'));

            return false;
        }

        if (!empty($productIblockGetIdList)) {
            $iblockIdList = CIBlockElement::GetIBlockByIDList($productIblockGetIdList);
            if (!empty($iblockIdList) && is_array($iblockIdList)) {
                $iblockListId = $iblockIdList + $iblockListId;
            }
        }

        if (!isset($priceList) || !is_array($priceList)) {
            $priceList = [];
        }

        if (empty($priceList)) {
            $priceTypeList = self::getAllowedPriceTypes($arUserGroups);
            if (empty($priceTypeList)) {
                if (!empty($resultList)) {
                    return $resultList;
                }

                return false;
            }

            $iterator = Catalog\PriceTable::getList([
                'select' => [
                    'ID', 'PRODUCT_ID', 'CATALOG_GROUP_ID',
                    'PRICE', 'CURRENCY', 'QUANTITY_FROM', 'QUANTITY_TO', 'PRICE_SCALE',
                ],
                'filter' => [
                    '=PRODUCT_ID' => array_keys($products),
                    '@CATALOG_GROUP_ID' => $priceTypeList,
                ],
            ]);
            while ($row = $iterator->fetch()) {
                $row['ELEMENT_IBLOCK_ID'] = $iblockListId[$row['PRODUCT_ID']];

                if (isset($products[$row['PRODUCT_ID']])) {
                    $productData = $products[$row['PRODUCT_ID']];
                    if (!empty($productData['QUANTITY_LIST'])) {
                        foreach ($productData['QUANTITY_LIST'] as $basketCode => $quantity) {
                            if (isset($ignoreList[$row['PRODUCT_ID'].'|'.$quantity])) {
                                continue 2;
                            }
                        }
                    }

                    $quantityList = [];
                    if (!empty($productData['QUANTITY'])) {
                        $quantityList = [$productData['QUANTITY']];
                    }

                    if (!empty($productData['QUANTITY_LIST'])) {
                        $quantityList = $productData['QUANTITY_LIST'];
                    }

                    foreach ($quantityList as $basketCode => $quantity) {
                        $checkQuantity = abs((float) $quantity);
                        if (($row['QUANTITY_FROM'] <= $checkQuantity || empty($row['QUANTITY_FROM']))
                            && ($row['QUANTITY_TO'] >= $checkQuantity || empty($row['QUANTITY_TO']))) {
                            $row['QUANTITY'] = (float) $quantity;
                            $row['BASKET_CODE'] = $basketCode;
                            $priceList[] = $row;
                        }
                    }
                }
            }
            unset($row, $iterator, $cacheKey);
        } else {
            foreach ($priceList as $priceIndex => $priceData) {
                $priceList[$priceIndex]['ELEMENT_IBLOCK_ID'] = $iblockListId[$priceData['PRODUCT_ID']];
            }
            unset($priceIndex);
        }

        if (empty($priceList)) {
            if (!empty($resultList)) {
                return $resultList;
            }

            return false;
        }

        Main\Type\Collection::sortByColumn($priceList, ['BASKET_CODE' => SORT_ASC, 'CATALOG_GROUP_ID' => SORT_ASC]);

        $vatList = CCatalogProduct::GetVATDataByIDList(array_keys($products));
        if (!empty($vatList)) {
            foreach ($vatList as $productId => $vatValue) {
                if (false === $vatValue) {
                    $vatList[$productId] = [
                        'RATE' => null,
                        'VAT_INCLUDED' => 'N',
                        'EXCLUDE_VAT' => 'Y',
                    ];
                } else {
                    if ('N' === $vatValue['EXCLUDE_VAT']) {
                        $vatList[$productId]['RATE'] = $vatValue['RATE'] * 0.01;
                    }
                }
            }
        }

        $isNeedDiscounts = Catalog\Product\Price\Calculation::isAllowedUseDiscounts();
        $resultWithVat = Catalog\Product\Price\Calculation::isIncludingVat();

        $discountList = [];

        if (null === self::$saleIncluded) {
            self::initSaleSettings();
        }
        $isNeedleToMinimizeCatalogGroup = self::isNeedleToMinimizeCatalogGroup($priceList);

        $lastProductId = false;
        $lastBasketCode = false;
        $ignoreProductIdList = [];
        $coupons = [];
        $minimalPrice = [];

        foreach ($priceList as $priceData) {
            $productId = $priceData['PRODUCT_ID'];
            $basketCode = $priceData['BASKET_CODE'];

            if (in_array($productId, $ignoreProductIdList, true)) {
                continue;
            }

            if ($lastBasketCode !== $basketCode) {
                if (false !== $lastBasketCode) {
                    foreach (GetModuleEvents('catalog', 'OnGetOptimalPriceResult', true) as $arEvent) {
                        if (false === ExecuteModuleEventEx($arEvent, [&$resultList[$lastProductId][$lastBasketCode]])) {
                            continue;
                        }
                    }

                    $productHash = [
                        'MODULE' => 'catalog',
                        'PRODUCT_ID' => $lastProductId,
                        'BASKET_ID' => $lastBasketCode,
                    ];
                    if (!empty($resultList[$lastProductId][$lastBasketCode]['DISCOUNT_LIST'])) {
                        $applyCoupons = [];
                        foreach ($resultList[$lastProductId][$lastBasketCode]['DISCOUNT_LIST'] as $discount) {
                            if (!empty($discount['COUPON'])) {
                                $applyCoupons[] = $discount['COUPON'];
                            }
                        }
                        if (!empty($applyCoupons)) {
                            $resultApply = Sale\DiscountCouponsManager::setApplyByProduct($productHash, $applyCoupons);
                        }
                    }
                }

                if ($isNeedDiscounts && $needCoupons) {
                    $coupons = static::getCoupons($productId, $basketCode);
                }

                $lastBasketCode = $basketCode;
                $lastProductId = $productId;

                Catalog\Product\Price\Calculation::setConfig(
                    [
                        'USE_DISCOUNTS' => (isset($products[$productId]['BUNDLE_CHILD']) && true === $products[$productId]['BUNDLE_CHILD'] ? false : $useDiscount),
                    ]
                );
                $isNeedDiscounts = Catalog\Product\Price\Calculation::isAllowedUseDiscounts();
            }

            $vat = $vatList[$priceData['PRODUCT_ID']];

            $priceData['VAT_RATE'] = $vat['RATE'];
            $priceData['VAT_INCLUDED'] = $vat['VAT_INCLUDED'];
            $priceData['NO_VAT'] = $vat['EXCLUDE_VAT'];

            $currentPrice = (float) $priceData['PRICE'];
            if ('N' === $priceData['NO_VAT']) {
                if ('N' === $priceData['VAT_INCLUDED']) {
                    $currentPrice *= (1 + $priceData['VAT_RATE']);
                }
            }

            if ($priceData['CURRENCY'] !== $resultCurrency) {
                $currentPrice = CCurrencyRates::ConvertCurrency($currentPrice, $priceData['CURRENCY'], $resultCurrency);
            }
            $currentPrice = Catalog\Product\Price\Calculation::roundPrecision($currentPrice);

            $result = [
                'BASE_PRICE' => $currentPrice,
                'COMPARE_PRICE' => $currentPrice,
                'PRICE' => $currentPrice,
                'CURRENCY' => $resultCurrency,
                'DISCOUNT_LIST' => [],
                'RAW_PRICE' => $priceData,
            ];

            if ($isNeedDiscounts) {
                $discountList[$priceData['PRODUCT_ID']] = CCatalogDiscount::GetDiscount(
                    $productId,
                    $iblockListId[$priceData['PRODUCT_ID']],
                    [$priceData['CATALOG_GROUP_ID']],
                    $arUserGroups,
                    $renewal,
                    $siteID,
                    $coupons
                );

                $discountResult = CCatalogDiscount::applyDiscountList($currentPrice, $resultCurrency, $discountList[$priceData['PRODUCT_ID']]);
                if (false === $discountResult) {
                    $ignoreProductIdList[] = $productId;
                    $resultList[$productId][$basketCode] = false;

                    continue;
                }

                $result['PRICE'] = $discountResult['PRICE'];
                $result['COMPARE_PRICE'] = $discountResult['PRICE'];
                $result['DISCOUNT_LIST'] = $discountResult['DISCOUNT_LIST'];
                unset($discountResult);
            } elseif ($isNeedleToMinimizeCatalogGroup) {
                if (!isset($products[$productId]['QUANTITY_LIST'][$basketCode])) {
                    continue;
                }

                $calculateData = $priceData;
                $calculateData['PRICE'] = $currentPrice;
                $calculateData['CURRENCY'] = $resultCurrency;
                $possibleSalePrice = self::getPossibleSalePrice(
                    $productId,
                    $calculateData,
                    $products[$productId]['QUANTITY_LIST'][$basketCode],
                    $siteID,
                    $arUserGroups,
                    $needCoupons ? false : []
                );
                unset($calculateData);
                if (null === $possibleSalePrice) {
                    continue;
                }
                $result['COMPARE_PRICE'] = $possibleSalePrice;
                unset($possibleSalePrice);
            }

            if ('N' === $priceData['NO_VAT']) {
                if (!$resultWithVat) {
                    $result['PRICE'] /= (1 + $priceData['VAT_RATE']);
                    $result['COMPARE_PRICE'] /= (1 + $priceData['VAT_RATE']);
                    $result['BASE_PRICE'] /= (1 + $priceData['VAT_RATE']);
                }
            }

            $result['UNROUND_PRICE'] = $result['PRICE'];
            $result['UNROUND_BASE_PRICE'] = $result['BASE_PRICE'];
            if (Catalog\Product\Price\Calculation::isComponentResultMode()) {
                $result['BASE_PRICE'] = Catalog\Product\Price::roundPrice(
                    $priceData['CATALOG_GROUP_ID'],
                    $result['BASE_PRICE'],
                    $resultCurrency
                );
                $result['PRICE'] = Catalog\Product\Price::roundPrice(
                    $priceData['CATALOG_GROUP_ID'],
                    $result['PRICE'],
                    $resultCurrency
                );
                if (
                    empty($result['DISCOUNT_LIST'])
                    || Catalog\Product\Price\Calculation::compare($result['BASE_PRICE'], $result['PRICE'], '<=')
                ) {
                    $result['BASE_PRICE'] = $result['PRICE'];
                }
                $result['COMPARE_PRICE'] = $result['PRICE'];
            }

            if (
                empty($minimalPrice[$basketCode])
                || $minimalPrice[$basketCode]['COMPARE_PRICE'] > $result['COMPARE_PRICE']
            ) {
                $minimalPrice[$basketCode] = $result;
            } elseif (
                $minimalPrice[$basketCode]['COMPARE_PRICE'] === $result['COMPARE_PRICE']
                && $minimalPrice[$basketCode]['RAW_PRICE']['PRICE_SCALE'] > $result['RAW_PRICE']['PRICE_SCALE']
            ) {
                $minimalPrice[$basketCode] = $result;
            }

            unset($currentPrice, $result);

            $discountValue = ($minimalPrice[$basketCode]['BASE_PRICE'] - $minimalPrice[$basketCode]['PRICE']);

            if ('N' === $minimalPrice[$basketCode]['RAW_PRICE']['NO_VAT']) {
                $vatIncluded = $resultWithVat ? 'Y' : 'N';
            } else {
                $vatIncluded = 'N';
            }
            unset($minimalPrice[$basketCode]['RAW_PRICE']['PRICE_SCALE']);
            $productResult = [
                'PRICE' => $minimalPrice[$basketCode]['RAW_PRICE'],
                'RESULT_PRICE' => [
                    'ID' => $minimalPrice[$basketCode]['RAW_PRICE']['ID'],
                    'PRICE_TYPE_ID' => $minimalPrice[$basketCode]['RAW_PRICE']['CATALOG_GROUP_ID'],
                    'BASE_PRICE' => $minimalPrice[$basketCode]['BASE_PRICE'],
                    'DISCOUNT_PRICE' => $minimalPrice[$basketCode]['PRICE'],
                    'CURRENCY' => $resultCurrency,
                    'DISCOUNT' => $discountValue,
                    'PERCENT' => (
                        $minimalPrice[$basketCode]['BASE_PRICE'] > 0 && $discountValue > 0
                        ? round((100 * $discountValue) / $minimalPrice[$basketCode]['BASE_PRICE'], 0)
                        : 0
                    ),
                    'VAT_RATE' => $minimalPrice[$basketCode]['RAW_PRICE']['VAT_RATE'],
                    'VAT_INCLUDED' => $vatIncluded,
                    'NO_VAT' => $minimalPrice[$basketCode]['RAW_PRICE']['NO_VAT'],
                    'UNROUND_BASE_PRICE' => $minimalPrice[$basketCode]['UNROUND_BASE_PRICE'],
                    'UNROUND_DISCOUNT_PRICE' => $minimalPrice[$basketCode]['UNROUND_PRICE'],
                ],
                'DISCOUNT_PRICE' => $minimalPrice[$basketCode]['PRICE'],
                'DISCOUNT' => [],
                'DISCOUNT_LIST' => [],
                'PRODUCT_ID' => $productId,
            ];

            if (!empty($minimalPrice[$basketCode]['DISCOUNT_LIST'])) {
                reset($minimalPrice[$basketCode]['DISCOUNT_LIST']);
                $productResult['DISCOUNT'] = current($minimalPrice[$basketCode]['DISCOUNT_LIST']);
                $productResult['DISCOUNT_LIST'] = $minimalPrice[$basketCode]['DISCOUNT_LIST'];
            }

            $resultList[$productId][$priceData['BASKET_CODE']] = $productResult;
        }
        unset($minimalPrice, $priceData, $vat);

        if (false !== $lastBasketCode) {
            foreach (GetModuleEvents('catalog', 'OnGetOptimalPriceResult', true) as $arEvent) {
                if (false === ExecuteModuleEventEx($arEvent, [&$resultList[$lastProductId][$lastBasketCode]])) {
                    break;
                }
            }

            $productHash = [
                'MODULE' => 'catalog',
                'PRODUCT_ID' => $lastProductId,
                'BASKET_ID' => $lastBasketCode,
            ];
            if (!empty($resultList[$lastProductId][$lastBasketCode]['DISCOUNT_LIST'])) {
                $applyCoupons = [];
                foreach ($resultList[$lastProductId][$lastBasketCode]['DISCOUNT_LIST'] as $discount) {
                    if (!empty($discount['COUPON'])) {
                        $applyCoupons[] = $discount['COUPON'];
                    }
                }
                if (!empty($applyCoupons)) {
                    Sale\DiscountCouponsManager::setApplyByProduct($productHash, $applyCoupons);
                }
            }
        }

        return $resultList;
    }

    /**
     * @param float  $price
     * @param string $currency
     * @param array  $discounts
     *
     * @return bool|float
     */
    public static function CountPriceWithDiscount($price, $currency, $discounts)
    {
        static $eventOnGetExists = null;
        static $eventOnResultExists = null;

        if (true === $eventOnGetExists || null === $eventOnGetExists) {
            foreach (GetModuleEvents('catalog', 'OnCountPriceWithDiscount', true) as $arEvent) {
                $eventOnGetExists = true;
                $mxResult = ExecuteModuleEventEx($arEvent, [$price, $currency, $discounts]);
                if (true !== $mxResult) {
                    return $mxResult;
                }
            }
            if (null === $eventOnGetExists) {
                $eventOnGetExists = false;
            }
        }

        $currency = CCurrency::checkCurrencyID($currency);
        if (false === $currency) {
            return false;
        }

        $price = (float) $price;
        if ($price <= 0) {
            return $price;
        }

        $currentMinPrice = $price;
        if (!empty($discounts) && is_array($discounts)) {
            $result = CCatalogDiscount::applyDiscountList($price, $currency, $discounts);
            if (false === $result) {
                return false;
            }

            $currentMinPrice = $result['PRICE'];
        }

        if (true === $eventOnResultExists || null === $eventOnResultExists) {
            foreach (GetModuleEvents('catalog', 'OnCountPriceWithDiscountResult', true) as $arEvent) {
                $eventOnResultExists = true;
                if (false === ExecuteModuleEventEx($arEvent, [&$currentMinPrice])) {
                    return false;
                }
            }
            if (null === $eventOnResultExists) {
                $eventOnResultExists = false;
            }
        }

        return $currentMinPrice;
    }

    public static function GetProductSections($ID)
    {
        // @global CStackCacheManager $stackCacheManager
        global $stackCacheManager;

        $ID = (int) $ID;
        if ($ID <= 0) {
            return false;
        }

        $cacheTime = CATALOG_CACHE_DEFAULT_TIME;
        if (defined('CATALOG_CACHE_TIME')) {
            $cacheTime = (int) CATALOG_CACHE_TIME;
        }

        $arProductSections = [];

        $dbElementSections = CIBlockElement::GetElementGroups($ID, false, ['ID', 'ADDITIONAL_PROPERTY_ID']);
        while ($arElementSections = $dbElementSections->Fetch()) {
            if ((int) $arElementSections['ADDITIONAL_PROPERTY_ID'] > 0) {
                continue;
            }
            $arSectionsTmp = [];

            $strCacheKey = 'p'.$arElementSections['ID'];

            $stackCacheManager->SetLength('catalog_group_parents', 50);
            $stackCacheManager->SetTTL('catalog_group_parents', $cacheTime);
            if ($stackCacheManager->Exist('catalog_group_parents', $strCacheKey)) {
                $arSectionsTmp = $stackCacheManager->Get('catalog_group_parents', $strCacheKey);
            } else {
                $dbSection = CIBlockSection::GetList(
                    [],
                    ['ID' => $arElementSections['ID']],
                    false,
                    [
                        'ID',
                        'IBLOCK_ID',
                        'LEFT_MARGIN',
                        'RIGHT_MARGIN',
                    ]
                );
                if ($arSection = $dbSection->Fetch()) {
                    $dbSectionTree = CIBlockSection::GetList(
                        ['LEFT_MARGIN' => 'DESC'],
                        [
                            'IBLOCK_ID' => $arSection['IBLOCK_ID'],
                            'ACTIVE' => 'Y',
                            'GLOBAL_ACTIVE' => 'Y',
                            'IBLOCK_ACTIVE' => 'Y',
                            '<=LEFT_BORDER' => $arSection['LEFT_MARGIN'],
                            '>=RIGHT_BORDER' => $arSection['RIGHT_MARGIN'],
                        ],
                        false,
                        ['ID']
                    );
                    while ($arSectionTree = $dbSectionTree->Fetch()) {
                        $arSectionTree['ID'] = (int) $arSectionTree['ID'];
                        $arSectionsTmp[] = $arSectionTree['ID'];
                    }
                    unset($arSectionTree, $dbSectionTree);
                }
                unset($arSection, $dbSection);

                $stackCacheManager->Set('catalog_group_parents', $strCacheKey, $arSectionsTmp);
            }

            $arProductSections = array_merge($arProductSections, $arSectionsTmp);
        }
        unset($arElementSections, $dbElementSections);

        $arProductSections = array_unique($arProductSections);

        return $arProductSections;
    }

    public static function OnIBlockElementDelete($ProductID)
    {
        $result = self::delete($ProductID);

        return $result->isSuccess();
    }

    /**
     * @deprecated deprecated since catalog 17.6.3
     *
     * @param array $arFields
     */
    public static function OnAfterIBlockElementUpdate($arFields) {}

    public static function CheckProducts($arItemIDs)
    {
        if (!is_array($arItemIDs)) {
            $arItemIDs = [$arItemIDs];
        }
        Main\Type\Collection::normalizeArrayValuesByInt($arItemIDs);
        if (empty($arItemIDs)) {
            return false;
        }
        $arProductList = [];
        $rsProducts = CCatalogProduct::GetList(
            [],
            ['@ID' => $arItemIDs],
            false,
            false,
            ['ID']
        );
        while ($arProduct = $rsProducts->Fetch()) {
            $arProduct['ID'] = (int) $arProduct['ID'];
            $arProductList[$arProduct['ID']] = true;
        }
        if (empty($arProductList)) {
            return false;
        }
        $boolFlag = true;
        foreach ($arItemIDs as &$intItemID) {
            if (!isset($arProductList[$intItemID])) {
                $boolFlag = false;

                break;
            }
        }
        unset($intItemID);

        return $boolFlag;
    }

    /**
     * @deprecated deprecated since catalog 18.7.0
     * @see \CProductQueryBuilder::makeQuery()
     *
     * @param array $order
     * @param array $filter
     * @param array $select
     *
     * @return array
     */
    public static function GetQueryBuildArrays($order, $filter, $select)
    {
        $result = [
            'SELECT' => '',
            'FROM' => '',
            'WHERE' => '',
            'ORDER' => [],
        ];

        $getListParameters = [];
        if (!empty($select) && is_array($select)) {
            $getListParameters['select'] = $select;
        }
        if (!empty($filter) && is_array($filter)) {
            $getListParameters['filter'] = $filter;
        }
        if (!empty($order) && is_array($order)) {
            $getListParameters['order'] = $order;
        }

        $query = CProductQueryBuilder::makeQuery($getListParameters);
        if (!empty($query)) {
            if (!empty($query['select'])) {
                $result['SELECT'] = ', '.implode(', ', $query['select']).' ';
            }
            if (!empty($query['join'])) {
                $result['FROM'] = ' '.implode(' ', $query['join']).' ';
            }
            if (!empty($query['filter'])) {
                $result['WHERE'] = ' and '.implode(' and ', $query['filter']);
            }
            if (!empty($query['order'])) {
                $result['ORDER'] = $query['order'];
            }
        }
        unset($query);

        return $result;
    }

    /**
     * Return payment period list.
     *
     * @deprecated deprected since catalog 17.0.0
     * @see ProductTable::getPaymentPeriods
     *
     * @param bool $boolFull with description
     *
     * @return array
     */
    public static function GetTimePeriodTypes($boolFull = false)
    {
        return ProductTable::getPaymentPeriods($boolFull);
    }

    protected static function getQueryBuildCurrencyScale($filter, $priceTypeId)
    {
        $result = [];
        if (!isset($filter['CATALOG_CURRENCY_SCALE_'.$priceTypeId])) {
            return $result;
        }
        $currencyId = Currency\CurrencyManager::checkCurrencyID($filter['CATALOG_CURRENCY_SCALE_'.$priceTypeId]);
        if (false === $currencyId) {
            return $result;
        }

        $currency = CCurrency::GetByID($currencyId);
        if (empty($currency)) {
            return $result;
        }

        $result['CURRENCY'] = $currency['CURRENCY'];
        $result['BASE_RATE'] = $currency['CURRENT_BASE_RATE'];

        return $result;
    }

    protected static function getQueryBuildPriceScaled($prices, $scale)
    {
        $result = [];
        $scale = (float) $scale;
        if (!is_array($prices)) {
            $prices = [$prices];
        }
        if (empty($prices) || $scale <= 0) {
            return $result;
        }
        foreach ($prices as &$value) {
            $result[] = (float) $value * $scale;
        }
        unset($value);

        return $result;
    }

    protected static function initSaleSettings()
    {
        if (null === self::$saleIncluded) {
            self::$saleIncluded = Main\Loader::includeModule('sale');
        }
        if (self::$saleIncluded) {
            self::$useSaleDiscount = 'Y' === (string) Main\Config\Option::get('sale', 'use_sale_discount_only');
            if (self::$useSaleDiscount) {
                // TODO: replace runtime to reference after sale 17.5.2 will be stable
                $row = Sale\Internals\DiscountEntitiesTable::getList([
                    'select' => ['ID'],
                    'filter' => [
                        '=MODULE_ID' => 'catalog',
                        '=ENTITY' => 'PRICE',
                        '=FIELD_ENTITY' => 'CATALOG_GROUP_ID',
                        '=FIELD_TABLE' => 'CATALOG_GROUP_ID',
                        '=ACTIVE_DISCOUNT.ACTIVE' => 'Y',
                    ],
                    'runtime' => [
                        new Main\Entity\ReferenceField(
                            'ACTIVE_DISCOUNT',
                            'Bitrix\Sale\Internals\Discount',
                            ['=this.DISCOUNT_ID' => 'ref.ID'],
                            ['join_type' => 'LEFT']
                        ),
                    ],
                    'limit' => 1,
                ])->fetch();
                self::$existPriceTypeDiscounts = !empty($row);
                unset($row);
            }
        }
    }

    /**
     * @return array|bool
     */
    private static function getCoupons($productId, $basketCode)
    {
        $productHash = [
            'MODULE' => 'catalog',
            'PRODUCT_ID' => $productId,
            'BASKET_ID' => $basketCode,
        ];
        $coupons = Sale\DiscountCouponsManager::getForApply(['MODULE_ID' => 'catalog'], $productHash);
        if (!empty($coupons)) {
            $coupons = array_keys($coupons);
        }

        return $coupons;
    }

    /**
     * Update result user handlers for event OnGetOptimalPrice.
     *
     * @param array &$userResult Optimal price array
     * @param array $params      getOptimalPrice parameters
     */
    private static function updateUserHandlerOptimalPrice(&$userResult, array $params)
    {
        global $APPLICATION;

        if (empty($userResult) || !is_array($userResult)) {
            $userResult = false;

            return;
        }
        if (
            (empty($userResult['PRICE']) || !is_array($userResult['PRICE']))
            && (empty($userResult['RESULT_PRICE']) || !is_array($userResult['RESULT_PRICE']))
        ) {
            $userResult = false;

            return;
        }

        $resultCurrency = Catalog\Product\Price\Calculation::getCurrency();
        if (empty($resultCurrency)) {
            $APPLICATION->ThrowException(Loc::getMessage('BT_MOD_CATALOG_PROD_ERR_NO_RESULT_CURRENCY'));
            $userResult = false;

            return;
        }

        if (!isset($userResult['PRODUCT_ID'])) {
            $userResult['PRODUCT_ID'] = $params['PRODUCT_ID'];
        }

        $oldDiscountExist = !empty($userResult['DISCOUNT']) && is_array($userResult['DISCOUNT']);
        if ($oldDiscountExist) {
            if (empty($userResult['DISCOUNT']['MODULE_ID'])) {
                $userResult['DISCOUNT']['MODULE_ID'] = 'catalog';
            }
            if ($userResult['DISCOUNT']['CURRENCY'] !== $resultCurrency) {
                Catalog\DiscountTable::convertCurrency($userResult['DISCOUNT'], $resultCurrency);
            }
        }

        if (!isset($userResult['DISCOUNT_LIST']) || !is_array($userResult['DISCOUNT_LIST'])) {
            $userResult['DISCOUNT_LIST'] = [];
            if ($oldDiscountExist) {
                $userResult['DISCOUNT_LIST'][] = $userResult['DISCOUNT'];
            }
        }
        unset($oldDiscountExist);

        foreach ($userResult['DISCOUNT_LIST'] as &$discount) {
            if (empty($discount['MODULE_ID'])) {
                $discount['MODULE_ID'] = 'catalog';
            }
            if ($discount['CURRENCY'] !== $resultCurrency) {
                Catalog\DiscountTable::convertCurrency($discount, $resultCurrency);
            }
        }
        unset($discount);

        if (isset($userResult['PRICE']) && is_array($userResult['PRICE'])) {
            if (!isset($userResult['PRICE']['VAT_RATE'])) {
                $vat = CCatalogProduct::GetVATDataByID($userResult['PRODUCT_ID']);
                if (!empty($vat)) {
                    $vat['RATE'] = (float) $vat['RATE'] * 0.01;
                } else {
                    $vat = ['RATE' => 0.0, 'VAT_INCLUDED' => 'Y'];
                }
                $userResult['PRICE']['VAT_RATE'] = $vat['RATE'];
                $userResult['PRICE']['VAT_INCLUDED'] = $vat['VAT_INCLUDED'];
                unset($vat);
            }
        }
        if (empty($userResult['RESULT_PRICE']) || !is_array($userResult['RESULT_PRICE'])) {
            $userResult['RESULT_PRICE'] = CCatalogDiscount::calculateDiscountList(
                $userResult['PRICE'],
                $resultCurrency,
                $userResult['DISCOUNT_LIST'],
                Catalog\Product\Price\Calculation::isIncludingVat()
            );
        }

        if (!isset($userResult['RESULT_PRICE']['CURRENCY'])) {
            $userResult['RESULT_PRICE']['CURRENCY'] = $resultCurrency;
        }

        if (!isset($userResult['RESULT_PRICE']['PRICE_TYPE_ID'])) {
            if (isset($userResult['PRICE']['CATALOG_GROUP_ID'])) {
                $userResult['RESULT_PRICE']['PRICE_TYPE_ID'] = $userResult['PRICE']['CATALOG_GROUP_ID'];
            }
        }

        if (!isset($userResult['RESULT_PRICE']['ID'])) {
            if (isset($userResult['PRICE']['ID'])) {
                $userResult['RESULT_PRICE']['ID'] = $userResult['PRICE']['ID'];
            }
        }

        $componentResultMode = Catalog\Product\Price\Calculation::isComponentResultMode();

        if (!isset($userResult['RESULT_PRICE']['UNROUND_DISCOUNT_PRICE'])) {
            $userResult['RESULT_PRICE']['UNROUND_DISCOUNT_PRICE'] = $userResult['RESULT_PRICE']['DISCOUNT_PRICE'];
            if ($componentResultMode) {
                $userResult['RESULT_PRICE']['DISCOUNT_PRICE'] = Catalog\Product\Price::roundPrice(
                    $userResult['RESULT_PRICE']['PRICE_TYPE_ID'],
                    $userResult['RESULT_PRICE']['DISCOUNT_PRICE'],
                    $userResult['RESULT_PRICE']['CURRENCY']
                );
            }
        }

        if (!isset($userResult['RESULT_PRICE']['UNROUND_BASE_PRICE'])) {
            $userResult['RESULT_PRICE']['UNROUND_BASE_PRICE'] = $userResult['RESULT_PRICE']['BASE_PRICE'];
            if ($componentResultMode) {
                $userResult['RESULT_PRICE']['BASE_PRICE'] = Catalog\Product\Price::roundPrice(
                    $userResult['RESULT_PRICE']['PRICE_TYPE_ID'],
                    $userResult['RESULT_PRICE']['BASE_PRICE'],
                    $userResult['RESULT_PRICE']['CURRENCY']
                );
            }
        }

        if ($componentResultMode) {
            if (
                empty($userResult['DISCOUNT_LIST'])
                || Catalog\Product\Price\Calculation::compare(
                    $userResult['RESULT_PRICE']['BASE_PRICE'],
                    $userResult['RESULT_PRICE']['DISCOUNT_PRICE'],
                    '<='
                )) {
                $userResult['RESULT_PRICE']['BASE_PRICE'] = $userResult['RESULT_PRICE']['DISCOUNT_PRICE'];
            }
        }

        $discountValue = $userResult['RESULT_PRICE']['BASE_PRICE'] - $userResult['RESULT_PRICE']['DISCOUNT_PRICE'];
        $userResult['RESULT_PRICE']['DISCOUNT'] = $discountValue;
        $userResult['RESULT_PRICE']['PERCENT'] = (
            $userResult['RESULT_PRICE']['BASE_PRICE'] > 0 && $discountValue > 0
            ? round((100 * $discountValue) / $userResult['RESULT_PRICE']['BASE_PRICE'], 0)
            : 0
        );
        unset($discountValue);

        if (!isset($userResult['RESULT_PRICE']['VAT_RATE'])) {
            if (isset($userResult['PRICE']['VAT_RATE'])) {
                $userResult['RESULT_PRICE']['VAT_RATE'] = $userResult['PRICE']['VAT_RATE'];
                $userResult['RESULT_PRICE']['VAT_INCLUDED'] = $userResult['PRICE']['VAT_INCLUDED'];
            } else {
                $vat = CCatalogProduct::GetVATDataByID($userResult['PRODUCT_ID']);
                if (!empty($vat)) {
                    $vat['RATE'] = (float) $vat['RATE'] * 0.01;
                } else {
                    $vat = ['RATE' => 0.0, 'VAT_INCLUDED' => 'Y'];
                }
                $userResult['RESULT_PRICE']['VAT_RATE'] = $vat['RATE'];
                $userResult['RESULT_PRICE']['VAT_INCLUDED'] = $vat['VAT_INCLUDED'];
                unset($vat);
            }
        }

        $userResult['DISCOUNT_PRICE'] = $userResult['RESULT_PRICE']['DISCOUNT_PRICE'];
    }

    private static function isNeedleToMinimizeCatalogGroup(array $priceList)
    {
        if (null === self::$saleIncluded) {
            self::initSaleSettings();
        }

        if (
            !self::$saleIncluded
            || !self::$useSaleDiscount
            || count($priceList) < 2
        ) {
            return false;
        }

        return self::$existPriceTypeDiscounts;
    }

    private static function getPossibleSalePrice($intProductID, array $priceData, $quantity, $siteID, array $userGroups, $coupons)
    {
        $possibleSalePrice = null;

        $registry = Sale\Registry::getInstance(Sale\Registry::REGISTRY_TYPE_ORDER);

        if (empty($priceData)) {
            return $possibleSalePrice;
        }

        $isCompatibilityUsed = Sale\Compatible\DiscountCompatibility::isUsed();
        Sale\Compatible\DiscountCompatibility::stopUsageCompatible();

        $freezeCoupons = (empty($coupons) && is_array($coupons));

        if ($freezeCoupons) {
            Sale\DiscountCouponsManager::freezeCouponStorage();
        }

        /** @var Basket $basket */
        static $basket = null,
        /** @var BasketItem $basketItem */
        $basketItem = null;

        if (null !== $basket) {
            if ($basket->getSiteId() !== $siteID) {
                $basket = null;
                $basketItem = null;
            }
        }
        if (null === $basket) {
            /** @var Basket $basketClassName */
            $basketClassName = $registry->getBasketClassName();

            $basket = $basketClassName::create($siteID);
            $basketItem = $basket->createItem('catalog', $intProductID);
        }

        $fields = [
            'PRODUCT_ID' => $intProductID,
            'QUANTITY' => $quantity,
            'LID' => $siteID,
            'PRODUCT_PRICE_ID' => $priceData['ID'],
            'PRICE' => $priceData['PRICE'],
            'BASE_PRICE' => $priceData['PRICE'],
            'DISCOUNT_PRICE' => 0,
            'CURRENCY' => $priceData['CURRENCY'],
            'CAN_BUY' => 'Y',
            'DELAY' => 'N',
            'PRICE_TYPE_ID' => (int) $priceData['CATALOG_GROUP_ID'],
        ];

        $basketItem->setFieldsNoDemand($fields);

        $discount = Sale\Discount::buildFromBasket($basket, new Sale\Discount\Context\UserGroup($userGroups));

        $discount->setExecuteModuleFilter(['all', 'catalog']);
        $discount->calculate();

        $calcResults = $discount->getApplyResult(true);
        if ($calcResults && !empty($calcResults['PRICES']['BASKET'])) {
            $possibleSalePrice = reset($calcResults['PRICES']['BASKET']);
            $possibleSalePrice = $possibleSalePrice['PRICE'];
        }

        if ($freezeCoupons) {
            Sale\DiscountCouponsManager::unFreezeCouponStorage();
        }
        $discount->setExecuteModuleFilter(['all', 'sale', 'catalog']);

        if (true === $isCompatibilityUsed) {
            Sale\Compatible\DiscountCompatibility::revertUsageCompatible();
        }

        return $possibleSalePrice;
    }

    private static function checkPriceValue($price)
    {
        $result = false;

        if (null !== $price && false !== $price) {
            if (is_string($price)) {
                $price = str_replace(',', '.', $price);
                if ('' !== $price && is_numeric($price)) {
                    $price = (float) $price;
                    if (is_finite($price)) {
                        $result = $price;
                    }
                }
            } elseif (
                is_int($price)
                || (is_float($price) && is_finite($price))
            ) {
                $result = $price;
            }
        }

        return $result;
    }

    private static function checkPriceCurrency($currency)
    {
        $result = false;
        if (null !== $currency && false !== $currency && '' !== $currency) {
            $result = $currency;
        }

        return $result;
    }

    /**
     * @return array
     */
    private static function getAllowedPriceTypes(array $userGroups)
    {
        static $priceTypeCache = [];

        Main\Type\Collection::normalizeArrayValuesByInt($userGroups, true);
        if (empty($userGroups)) {
            return [];
        }

        $cacheKey = 'U'.implode('_', $userGroups);
        if (!isset($priceTypeCache[$cacheKey])) {
            $priceTypeCache[$cacheKey] = [];
            $priceIterator = Catalog\GroupAccessTable::getList([
                'select' => ['CATALOG_GROUP_ID'],
                'filter' => ['@GROUP_ID' => $userGroups, '=ACCESS' => Catalog\GroupAccessTable::ACCESS_BUY],
                'order' => ['CATALOG_GROUP_ID' => 'ASC'],
            ]);
            while ($priceType = $priceIterator->fetch()) {
                $priceTypeId = (int) $priceType['CATALOG_GROUP_ID'];
                $priceTypeCache[$cacheKey][$priceTypeId] = $priceTypeId;
                unset($priceTypeId);
            }
            unset($priceType, $priceIterator);
        }

        return $priceTypeCache[$cacheKey];
    }

    private static function convertErrors(Main\Entity\Result $result)
    {
        global $APPLICATION;

        $oldMessages = [];
        foreach ($result->getErrorMessages() as $errorText) {
            $oldMessages[] = ['text' => $errorText];
        }
        unset($errorText);

        if (!empty($oldMessages)) {
            $error = new CAdminException($oldMessages);
            $APPLICATION->ThrowException($error);
            unset($error);
        }
        unset($oldMessages);
    }

    private static function normalizeFields(array &$fields)
    {
        if (isset($fields['QUANTITY']) && is_string($fields['QUANTITY']) && '' === $fields['QUANTITY']) {
            $fields['QUANTITY'] = 0;
        }
        if (isset($fields['QUANTITY_RESERVED']) && is_string($fields['QUANTITY_RESERVED']) && '' === $fields['QUANTITY_RESERVED']) {
            $fields['QUANTITY_RESERVED'] = 0;
        }
        if (isset($fields['WEIGHT']) && is_string($fields['WEIGHT']) && '' === $fields['WEIGHT']) {
            $fields['WEIGHT'] = 0;
        }
    }
}
