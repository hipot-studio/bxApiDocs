<?php

IncludeModuleLangFile(__FILE__);

class store_barcode
{
    public static function Update($id, $arFields)
    {
        global $DB;
        $id = (int) $id;

        foreach (GetModuleEvents('catalog', 'OnBeforeCatalogStoreBarCodeUpdate', true) as $arEvent) {
            if (false === ExecuteModuleEventEx($arEvent, [$id, &$arFields])) {
                return false;
            }
        }

        if (array_key_exists('DATE_CREATE', $arFields)) {
            unset($arFields['DATE_CREATE']);
        }
        if (array_key_exists('DATE_MODIFY', $arFields)) {
            unset($arFields['DATE_MODIFY']);
        }
        if (array_key_exists('DATE_STATUS', $arFields)) {
            unset($arFields['DATE_STATUS']);
        }
        if (array_key_exists('CREATED_BY', $arFields)) {
            unset($arFields['CREATED_BY']);
        }

        $arFields['~DATE_MODIFY'] = $DB->GetNowFunction();

        if ($id <= 0 || !self::CheckFields('UPDATE', $arFields)) {
            return false;
        }
        $strUpdate = $DB->PrepareUpdate('b_catalog_store_barcode', $arFields);

        if (!empty($strUpdate)) {
            $strSql = 'UPDATE b_catalog_store_barcode SET '.$strUpdate.' WHERE ID = '.$id.' ';
            if (!$DB->Query($strSql, true, 'File: '.__FILE__.'<br>Line: '.__LINE__)) {
                return false;
            }
        }

        foreach (GetModuleEvents('catalog', 'OnCatalogStoreBarCodeUpdate', true) as $arEvent) {
            ExecuteModuleEventEx($arEvent, [$id, $arFields]);
        }

        return $id;
    }

    public static function Delete($id)
    {
        global $DB;
        $id = (int) $id;
        if ($id > 0) {
            foreach (GetModuleEvents('catalog', 'OnBeforeCatalogStoreBarCodeDelete', true) as $event) {
                ExecuteModuleEventEx($event, [$id]);
            }

            $DB->Query('DELETE FROM b_catalog_store_barcode WHERE ID = '.$id.' ', true);

            foreach (GetModuleEvents('catalog', 'OnCatalogStoreBarCodeDelete', true) as $arEvent) {
                ExecuteModuleEventEx($arEvent, [$id]);
            }

            return true;
        }

        return false;
    }

    protected static function CheckFields($action, &$arFields)
    {
        if ((('ADD' === $action) || isset($arFields['PRODUCT_ID'])) && (int) $arFields['PRODUCT_ID'] <= 0) {
            return false;
        }

        if ((('ADD' === $action) || isset($arFields['BARCODE'])) && '' === $arFields['BARCODE']) {
            return false;
        }

        return true;
    }
}
