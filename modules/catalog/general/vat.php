<?php

use Bitrix\Catalog;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM;

class vat
{
    /**
     * @deprecated deprecated since catalog 12.5.6
     */
    public static function err_mess(): string
    {
        return '<br>Module: catalog<br>Class: CCatalogVat<br>File: '.__FILE__;
    }

    /**
     * @deprecated
     */
    public static function CheckFields($ACTION, &$arFields, $ID = 0): bool
    {
        global $APPLICATION;
        $arMsg = [];
        $boolResult = true;

        $ACTION = mb_strtoupper($ACTION);
        if ('INSERT' === $ACTION) {
            $ACTION = 'ADD';
        }

        if (isset($arFields['SORT'])) {
            $arFields['C_SORT'] = $arFields['SORT'];
            unset($arFields['SORT']);
        }

        if (array_key_exists('ID', $arFields)) {
            unset($arFields['ID']);
        }

        if ('ADD' === $ACTION) {
            if (!isset($arFields['NAME'])) {
                $boolResult = false;
                $arMsg[] = ['id' => 'NAME', 'text' => Loc::getMessage('CVAT_ERROR_BAD_NAME')];
            }
            if (!isset($arFields['RATE'])) {
                $boolResult = false;
                $arMsg[] = ['id' => 'RATE', 'text' => Loc::getMessage('CVAT_ERROR_BAD_RATE')];
            }
            if (!isset($arFields['C_SORT'])) {
                $arFields['C_SORT'] = 100;
            }
            if (!isset($arFields['ACTIVE'])) {
                $arFields['ACTIVE'] = 'Y';
            }
        }

        if ($boolResult) {
            if (array_key_exists('NAME', $arFields)) {
                $arFields['NAME'] = trim($arFields['NAME']);
                if ('' === $arFields['NAME']) {
                    $boolResult = false;
                    $arMsg[] = ['id' => 'NAME', 'text' => Loc::getMessage('CVAT_ERROR_BAD_NAME')];
                }
            }
            if (array_key_exists('RATE', $arFields)) {
                $arFields['RATE'] = (float) $arFields['RATE'];
                if (0 > $arFields['RATE'] || 100 < $arFields['RATE']) {
                    $boolResult = false;
                    $arMsg[] = ['id' => 'RATE', 'text' => Loc::getMessage('CVAT_ERROR_BAD_RATE')];
                }
            }
            if (array_key_exists('C_SORT', $arFields)) {
                $arFields['C_SORT'] = (int) $arFields['C_SORT'];
                if (0 >= $arFields['C_SORT']) {
                    $arFields['C_SORT'] = 100;
                }
            }
            if (array_key_exists('ACTIVE', $arFields)) {
                $arFields['ACTIVE'] = ('Y' === $arFields['ACTIVE'] ? 'Y' : 'N');
            }
        }

        if (!$boolResult) {
            $obError = new CAdminException($arMsg);
            $APPLICATION->ResetException();
            $APPLICATION->ThrowException($obError);
        }

        return $boolResult;
    }

    /**
     * @deprecated deprecated since catalog 20.0.200
     * @see \Bitrix\Catalog\VatTable::getById()
     *
     * @return CDBResult|false
     */
    public static function GetByID($ID)
    {
        return CCatalogVat::GetListEx([], ['ID' => $ID]);
    }

    /**
     * @deprecated deprecated since catalog 12.5.6
     * @see CCatalogVat::GetListEx()
     *
     * @param array $arOrder
     * @param array $arFilter
     * @param array $arFields
     *
     * @return CDBResult|false
     */
    public static function GetList($arOrder = ['SORT' => 'ASC'], $arFilter = [], $arFields = [])
    {
        if (is_array($arFilter)) {
            if (array_key_exists('NAME', $arFilter) && array_key_exists('NAME_EXACT_MATCH', $arFilter)) {
                if ('Y' === $arFilter['NAME_EXACT_MATCH']) {
                    $arFilter['=NAME'] = $arFilter['NAME'];
                    unset($arFilter['NAME']);
                }
                unset($arFilter['NAME_EXACT_MATCH']);
            }
        }

        return CCatalogVat::GetListEx($arOrder, $arFilter, false, false, $arFields);
    }

    /**
     * @deprecated deprecated since catalog 12.5.6
     * @see Catalog\Model\Vat::add
     * @see Catalog\Model\Vat::update
     *
     * @return false|int
     */
    public static function Set($arFields)
    {
        if (isset($arFields['ID']) && (int) $arFields['ID'] > 0) {
            return CCatalogVat::Update($arFields['ID'], $arFields);
        }

        return CCatalogVat::Add($arFields);
    }

    public static function GetByProductID($PRODUCT_ID) {}

    /**
     * @deprecated
     * @see Catalog\Model\Vat::add
     *
     * @return false|int
     */
    public static function Add($fields)
    {
        if (empty($fields) || !is_array($fields)) {
            return false;
        }

        self::normalizeFields($fields);

        $result = Catalog\Model\Vat::add($fields);

        $id = false;
        if (!$result->isSuccess()) {
            self::convertErrors($result);
        } else {
            $id = (int) $result->getId();
        }
        unset($result);

        return $id;
    }

    /**
     * @deprecated
     * @see Catalog\Model\Vat::update
     *
     * @return false|int
     */
    public static function Update($id, $fields)
    {
        $id = (int) $id;
        if ($id <= 0 || empty($fields) || !is_array($fields)) {
            return false;
        }

        self::normalizeFields($fields);

        $result = Catalog\Model\Vat::update($id, $fields);

        if (!$result->isSuccess()) {
            $id = false;
            self::convertErrors($result);
        }

        return $id;
    }

    /**
     * @deprecated
     * @see Catalog\Model\Vat::delete
     */
    public static function Delete($id): bool
    {
        $id = (int) $id;
        if ($id <= 0) {
            return false;
        }

        $result = Catalog\Model\Vat::delete($id);
        $success = $result->isSuccess();
        if (!$success) {
            self::convertErrors($result);
        }
        unset($result);

        return $success;
    }

    private static function normalizeFields(array &$fields)
    {
        if (!isset($fields['SORT'])) {
            if (isset($fields['C_SORT'])) {
                $fields['SORT'] = $fields['C_SORT'];
                unset($fields['C_SORT']);
            }
        }
    }

    private static function convertErrors(ORM\Data\Result $result)
    {
        global $APPLICATION;

        $oldMessages = [];
        foreach ($result->getErrorMessages() as $errorText) {
            $oldMessages[] = [
                'text' => $errorText,
            ];
        }
        unset($errorText);

        if (!empty($oldMessages)) {
            $error = new CAdminException($oldMessages);
            $APPLICATION->ThrowException($error);
            unset($error);
        }
        unset($oldMessages);
    }
}
