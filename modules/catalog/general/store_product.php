<?php

use Bitrix\Catalog;
use Bitrix\Catalog\Model\Product;

IncludeModuleLangFile(__FILE__);

class store_product
{
    /**
     * @param array $arFields
     *
     * @return bool|int
     */
    public static function UpdateFromForm($arFields)
    {
        $iterator = Catalog\StoreProductTable::getList([
            'select' => ['ID'],
            'filter' => [
                '=PRODUCT_ID' => (int) $arFields['PRODUCT_ID'],
                '=STORE_ID' => (int) $arFields['STORE_ID'],
            ],
        ]);
        $row = $iterator->fetch();
        unset($iterator);
        if (!empty($row)) {
            return static::Update($row['ID'], $arFields);
        }

        return static::Add($arFields);
    }

    public static function Update($id, $arFields)
    {
        $id = (int) $id;

        foreach (GetModuleEvents('catalog', 'OnBeforeStoreProductUpdate', true) as $arEvent) {
            if (false === ExecuteModuleEventEx($arEvent, [$id, &$arFields])) {
                return false;
            }
        }

        if ($id < 0 || !static::CheckFields('UPDATE', $arFields)) {
            return false;
        }

        global $DB;

        $strUpdate = $DB->PrepareUpdate('b_catalog_store_product', $arFields);
        if ('' !== $strUpdate) {
            $strSql = 'UPDATE b_catalog_store_product SET '.$strUpdate.' WHERE ID = '.$id;
            $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
        }

        foreach (GetModuleEvents('catalog', 'OnStoreProductUpdate', true) as $arEvent) {
            ExecuteModuleEventEx($arEvent, [$id, $arFields]);
        }

        return true;
    }

    /**
     * @deprecated deprecated since catalog 17.6.0
     * @see Product::delete
     */
    public static function OnIBlockElementDelete($productId) {}

    public static function Delete($id)
    {
        global $DB;
        $id = (int) $id;
        if ($id > 0) {
            foreach (GetModuleEvents('catalog', 'OnBeforeStoreProductDelete', true) as $arEvent) {
                if (false === ExecuteModuleEventEx($arEvent, [$id])) {
                    return false;
                }
            }

            $DB->Query('DELETE FROM b_catalog_store_product WHERE ID = '.$id.' ', true);

            foreach (GetModuleEvents('catalog', 'OnStoreProductDelete', true) as $arEvent) {
                ExecuteModuleEventEx($arEvent, [$id]);
            }

            return true;
        }

        return false;
    }

    public static function addToBalanceOfStore($storeId, $productId, $amount)
    {
        $productId = (int) $productId;
        $storeId = (int) $storeId;
        $amount = (float) $amount;
        if ($productId <= 0 || $storeId <= 0) {
            return false;
        }
        $iterator = Catalog\StoreProductTable::getList([
            'select' => [
                'ID',
                'AMOUNT',
            ],
            'filter' => [
                '=PRODUCT_ID' => $productId,
                '=STORE_ID' => $storeId,
            ],
        ]);
        $row = $iterator->fetch();
        unset($iterator);
        if (!empty($row)) {
            return static::Update(
                $row['ID'],
                [
                    'AMOUNT' => (float) $row['AMOUNT'] + $amount,
                    'PRODUCT_ID' => $productId,
                    'STORE_ID' => $storeId,
                ]
            );
        }

        return static::Add([
            'PRODUCT_ID' => $productId,
            'STORE_ID' => $storeId,
            'AMOUNT' => $amount,
        ]);
    }

    protected static function CheckFields($action, &$arFields)
    {
        // @global CMain $APPLICATION
        global $APPLICATION;
        if ((('ADD' === $action) || isset($arFields['STORE_ID'])) && (int) $arFields['STORE_ID'] <= 0) {
            $APPLICATION->ThrowException(GetMessage('CP_EMPTY_STORE'));

            return false;
        }
        if ((('ADD' === $action) || isset($arFields['PRODUCT_ID'])) && (int) $arFields['PRODUCT_ID'] <= 0) {
            $APPLICATION->ThrowException(GetMessage('CP_EMPTY_PRODUCT'));

            return false;
        }
        if (!isset($arFields['AMOUNT']) || !is_numeric($arFields['AMOUNT'])) {
            $APPLICATION->ThrowException(GetMessage('CP_FALSE_AMOUNT'));

            return false;
        }

        return true;
    }
}
