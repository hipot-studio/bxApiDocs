<?php

use Bitrix\Catalog;

IncludeModuleLangFile(__FILE__);

class store_docs_element
{
    public static function update($id, $arFields)
    {
        $id = (int) $id;

        foreach (GetModuleEvents('catalog', 'OnBeforeCatalogStoreDocsElementUpdate', true) as $arEvent) {
            if (false === ExecuteModuleEventEx($arEvent, [$id, &$arFields])) {
                return false;
            }
        }

        if ($id < 0 || !self::CheckFields('UPDATE', $arFields)) {
            return false;
        }
        global $DB;
        $strUpdate = $DB->PrepareUpdate('b_catalog_docs_element', $arFields);
        $strSql = 'UPDATE b_catalog_docs_element SET '.$strUpdate.' WHERE ID = '.$id;
        if (!$DB->Query($strSql, true, 'File: '.__FILE__.'<br>Line: '.__LINE__)) {
            return false;
        }

        foreach (GetModuleEvents('catalog', 'OnCatalogStoreDocsElementUpdate', true) as $arEvent) {
            ExecuteModuleEventEx($arEvent, [$id, $arFields]);
        }

        return true;
    }

    public static function delete($id)
    {
        global $DB;
        $id = (int) $id;
        if ($id > 0) {
            foreach (GetModuleEvents('catalog', 'OnBeforeCatalogStoreDocsElementDelete', true) as $arEvent) {
                if (false === ExecuteModuleEventEx($arEvent, [$id])) {
                    return false;
                }
            }

            $DB->Query('DELETE FROM b_catalog_docs_barcode WHERE DOC_ELEMENT_ID = '.$id.' ', true);
            $DB->Query('DELETE FROM b_catalog_docs_element WHERE ID = '.$id.' ', true);

            foreach (GetModuleEvents('catalog', 'OnCatalogStoreDocsElementDelete', true) as $arEvent) {
                ExecuteModuleEventEx($arEvent, [$id]);
            }

            return true;
        }

        return false;
    }

    /**
     * @deprecated
     * @see Catalog\StoreDocumentElementTable::deleteByDocument
     */
    public static function OnDocumentBarcodeDelete($id): bool
    {
        $id = (int) $id;
        Catalog\StoreDocumentElementTable::deleteByDocument($id);

        foreach (GetModuleEvents('catalog', 'OnDocumentElementDelete', true) as $event) {
            ExecuteModuleEventEx($event, [$id]);
        }

        return true;
    }

    protected static function CheckFields($action, &$arFields)
    {
        if ((('ADD' === $action) || isset($arFields['DOC_ID'])) && (int) $arFields['DOC_ID'] <= 0) {
            return false;
        }
        if ((isset($arFields['ELEMENT_ID'])) && (int) $arFields['ELEMENT_ID'] <= 0) {
            return false;
        }
        if (isset($arFields['PURCHASING_PRICE'])) {
            $arFields['PURCHASING_PRICE'] = preg_replace('|\\s|', '', $arFields['PURCHASING_PRICE']);
        }

        return true;
    }
}
