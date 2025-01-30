<?php

use Bitrix\Catalog;

IncludeModuleLangFile(__FILE__);

class store_docs_barcode
{
    public static function update($id, $arFields)
    {
        $id = (int) $id;

        foreach (GetModuleEvents('catalog', 'OnBeforeCatalogStoreDocsBarcodeUpdate', true) as $arEvent) {
            if (false === ExecuteModuleEventEx($arEvent, [$id, &$arFields])) {
                return false;
            }
        }

        if ($id < 0 || !self::checkFields('UPDATE', $arFields)) {
            return false;
        }
        global $DB;
        $strUpdate = $DB->PrepareUpdate('b_catalog_docs_barcode', $arFields);
        $strSql = 'UPDATE b_catalog_docs_barcode SET '.$strUpdate.' WHERE ID = '.$id;
        if (!$DB->Query($strSql, true, 'File: '.__FILE__.'<br>Line: '.__LINE__)) {
            return false;
        }

        foreach (GetModuleEvents('catalog', 'OnStoreDocsBarcodeUpdate', true) as $arEvent) {
            ExecuteModuleEventEx($arEvent, [$id, $arFields]);
        }

        return true;
    }

    public static function delete($id)
    {
        global $DB;
        $id = (int) $id;
        if ($id > 0) {
            foreach (GetModuleEvents('catalog', 'OnBeforeCatalogStoreDocsBarcodeDelete', true) as $arEvent) {
                if (false === ExecuteModuleEventEx($arEvent, [$id])) {
                    return false;
                }
            }

            $DB->Query('DELETE FROM b_catalog_docs_barcode WHERE ID = '.$id.' ', true);

            foreach (GetModuleEvents('catalog', 'OnCatalogStoreDocsBarcodeDelete', true) as $arEvent) {
                ExecuteModuleEventEx($arEvent, [$id]);
            }

            return true;
        }

        return false;
    }

    /**
     * @deprecated
     * @see Catalog\StoreDocumentBarcodeTable::deleteByDocument
     */
    public static function OnBeforeDocumentDelete($id): bool
    {
        $id = (int) $id;
        Catalog\StoreDocumentBarcodeTable::deleteByDocument($id);

        foreach (GetModuleEvents('catalog', 'OnDocumentBarcodeDelete', true) as $arEvent) {
            ExecuteModuleEventEx($arEvent, [$id]);
        }

        return true;
    }

    protected static function checkFields($action, &$arFields)
    {
        if ((('ADD' === $action) || is_set($arFields, 'BARCODE')) && ('' === $arFields['BARCODE'])) {
            $GLOBALS['APPLICATION']->ThrowException(GetMessage('CP_EMPTY_BARCODE'));

            return false;
        }

        return true;
    }
}
