<?php

use Bitrix\Catalog;
use Bitrix\Catalog\MeasureRatioTable;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

class measure_ratio
{
    protected static $whiteList = ['ID', 'PRODUCT_ID', 'RATIO', 'IS_DEFAULT'];

    /**
     * @deprecated deprecated since catalog 16.0.13
     * @see MeasureRatioTable::add
     * Attention! Method \Bitrix\Catalog\MeasureRatioTable::add very strict checks the input parameters.
     *
     * Add measure ratio for product.
     *
     * @param array $arFields measure ratio
     *
     * @return bool|int
     *
     * @throws Exception
     */
    public static function add($arFields)
    {
        if (!static::checkFields('ADD', $arFields)) {
            return false;
        }

        $existRatio = MeasureRatioTable::getList([
            'select' => ['ID'],
            'filter' => ['=PRODUCT_ID' => $arFields['PRODUCT_ID'], '=RATIO' => $arFields['RATIO']],
        ])->fetch();
        if (!empty($existRatio)) {
            return (int) $existRatio['ID'];
        }

        $result = MeasureRatioTable::add($arFields);
        if ($result->isSuccess()) {
            return (int) $result->getId();
        }

        // @global CMain $APPLICATION
        global $APPLICATION;
        $errorList = $result->getErrorMessages();
        if (!empty($errorList)) {
            $APPLICATION->ThrowException(implode(', ', $errorList));
        }
        unset($errorList, $result);

        return false;
    }

    /**
     * @deprecated deprecated since catalog 16.0.13
     * @see MeasureRatioTable::update
     * Attention! Method \Bitrix\Catalog\MeasureRatioTable::update very strict checks the input parameters.
     *
     * Update measure ratio for product by id.
     *
     * @param int   $id       measure ratio id
     * @param array $arFields measure ratio
     *
     * @return bool|int
     *
     * @throws Exception
     */
    public static function update($id, $arFields)
    {
        // @global CMain $APPLICATION
        global $APPLICATION;

        $id = (int) $id;
        if ($id <= 0 || !static::checkFields('UPDATE', $arFields)) {
            return false;
        }
        if (empty($arFields)) {
            return $id;
        }

        $existRatio = MeasureRatioTable::getList([
            'select' => ['ID'],
            'filter' => ['!=ID' => $id, '=PRODUCT_ID' => $arFields['PRODUCT_ID'], '=RATIO' => $arFields['RATIO']],
        ])->fetch();
        if (!empty($existRatio)) {
            $APPLICATION->ThrowException(Loc::getMessage(
                'CATALOG_MEASURE_RATIO_RATIO_ALREADY_EXIST',
                ['#RATIO#' => $arFields['RATIO']]
            ));

            return false;
        }

        $result = MeasureRatioTable::update($id, $arFields);
        if ($result->isSuccess()) {
            return $id;
        }

        $errorList = $result->getErrorMessages();
        if (!empty($errorList)) {
            $APPLICATION->ThrowException(implode(', ', $errorList));
        }
        unset($errorList, $result);

        return false;
    }

    /**
     * @deprecated deprecated since catalog 16.0.13
     * @see MeasureRatioTable::delete
     *
     * Delete measure ratio by id.
     *
     * @param int $id measure ratio id
     *
     * @return bool
     *
     * @throws Exception
     */
    public static function delete($id)
    {
        $id = (int) $id;
        if ($id <= 0) {
            return false;
        }

        $result = MeasureRatioTable::delete($id);
        if ($result->isSuccess()) {
            return true;
        }

        // @global CMain $APPLICATION
        global $APPLICATION;
        $errorList = $result->getErrorMessages();
        if (!empty($errorList)) {
            $APPLICATION->ThrowException(implode(', ', $errorList));
        }
        unset($errorList, $result);

        return false;
    }

    protected static function checkFields($action, &$arFields)
    {
        // @global CMain $APPLICATION
        global $APPLICATION;

        $action = mb_strtoupper($action);
        if ('UPDATE' !== $action && 'ADD' !== $action) {
            $APPLICATION->ThrowException(Loc::getMessage('CATALOG_MEASURE_RATIO_BAD_ACTION'));

            return false;
        }
        $clearFields = [];
        foreach (self::$whiteList as $field) {
            if ('ID' === $field) {
                continue;
            }
            if (isset($arFields[$field])) {
                $clearFields[$field] = $arFields[$field];
            }
        }
        unset($field);

        if ('ADD' === $action) {
            if (empty($clearFields)) {
                $APPLICATION->ThrowException(Loc::getMessage('CATALOG_MEASURE_RATIO_EMPTY_CLEAR_FIELDS'));

                return false;
            }
            if (!isset($clearFields['PRODUCT_ID'])) {
                $APPLICATION->ThrowException(Loc::getMessage('CATALOG_MEASURE_RATIO_PRODUCT_ID_IS_ABSENT'));

                return false;
            }
            if (!isset($clearFields['RATIO'])) {
                $clearFields['RATIO'] = 1;
            }
            if (!isset($clearFields['IS_DEFAULT'])) {
                $row = null;
                if ((int) $clearFields['PRODUCT_ID'] > 0) {
                    $row = MeasureRatioTable::getList([
                        'select' => ['ID'],
                        'filter' => ['=PRODUCT_ID' => $clearFields['PRODUCT_ID'], '=IS_DEFAULT' => 'Y'],
                    ])->fetch();
                }
                $clearFields['IS_DEFAULT'] = (!empty($row) ? 'N' : 'Y');
                unset($row);
            }
        }
        if (isset($clearFields['PRODUCT_ID'])) {
            $clearFields['PRODUCT_ID'] = (int) $clearFields['PRODUCT_ID'];
            if ($clearFields['PRODUCT_ID'] <= 0) {
                $APPLICATION->ThrowException(Loc::getMessage('CATALOG_MEASURE_RATIO_BAD_PRODUCT_ID'));

                return false;
            }
        }
        if (isset($clearFields['RATIO'])) {
            if (is_string($clearFields['RATIO'])) {
                $clearFields['RATIO'] = str_replace(',', '.', $clearFields['RATIO']);
            }
            $clearFields['RATIO'] = (float) $clearFields['RATIO'];
            if ($clearFields['RATIO'] <= CATALOG_VALUE_EPSILON) {
                $clearFields['RATIO'] = 1;
            }
        }
        if (isset($clearFields['IS_DEFAULT'])) {
            $clearFields['IS_DEFAULT'] = ('Y' === $clearFields['IS_DEFAULT'] ? 'Y' : 'N');
        }
        $arFields = $clearFields;
        unset($clearFields);

        return true;
    }
}
