<?php

use Bitrix\Catalog;
use Bitrix\Iblock;
use Bitrix\Main;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\UserTable;

Loc::loadMessages(__FILE__);

define('BT_COND_LOGIC_EQ', 0);						// = (equal)
define('BT_COND_LOGIC_NOT_EQ', 1);					// != (not equal)
define('BT_COND_LOGIC_GR', 2);						// > (great)
define('BT_COND_LOGIC_LS', 3);						// < (less)
define('BT_COND_LOGIC_EGR', 4);						// => (great or equal)
define('BT_COND_LOGIC_ELS', 5);						// =< (less or equal)
define('BT_COND_LOGIC_CONT', 6);					// contain
define('BT_COND_LOGIC_NOT_CONT', 7);				// not contain

define('BT_COND_MODE_DEFAULT', 0);					// full mode
define('BT_COND_MODE_PARSE', 1);					// parsing mode
define('BT_COND_MODE_GENERATE', 2);					// generate mode
define('BT_COND_MODE_SQL', 3);						// generate getlist mode
define('BT_COND_MODE_SEARCH', 4);					// info mode

define('BT_COND_BUILD_CATALOG', 0);					// catalog conditions
define('BT_COND_BUILD_SALE', 1);					// sale conditions
define('BT_COND_BUILD_SALE_ACTIONS', 2);			// sale actions conditions

class CGlobalCondCtrl
{
    public static $arInitParams = false;
    public static $boolInit = false;

    public static function GetClassName()
    {
        return static::class;
    }

    public static function GetControlDescr()
    {
        $strClassName = static::class;

        return [
            'ID' => static::GetControlID(),
            'GetControlShow' => [$strClassName, 'GetControlShow'],
            'GetConditionShow' => [$strClassName, 'GetConditionShow'],
            'IsGroup' => [$strClassName, 'IsGroup'],
            'Parse' => [$strClassName, 'Parse'],
            'Generate' => [$strClassName, 'Generate'],
            'ApplyValues' => [$strClassName, 'ApplyValues'],
            'InitParams' => [$strClassName, 'InitParams'],
        ];
    }

    public static function GetControlShow($arParams)
    {
        return [];
    }

    public static function GetConditionShow($arParams)
    {
        return '';
    }

    public static function IsGroup($strControlID = false)
    {
        return 'N';
    }

    public static function Parse($arOneCondition)
    {
        return '';
    }

    public static function Generate($arOneCondition, $arParams, $arControl, $arSubs = false)
    {
        return '';
    }

    public static function ApplyValues($arOneCondition, $arControl)
    {
        return [];
    }

    public static function InitParams($arParams)
    {
        if (!empty($arParams) && is_array($arParams)) {
            static::$arInitParams = $arParams;
            static::$boolInit = true;
        }
    }

    /**
     * @return array|string
     */
    public static function GetControlID()
    {
        return '';
    }

    public static function GetShowIn($arControls)
    {
        if (!is_array($arControls)) {
            $arControls = [$arControls];
        }

        return array_values(array_unique($arControls));
    }

    /**
     * @param bool|string $strControlID
     *
     * @return array|bool
     */
    public static function GetControls($strControlID = false)
    {
        return false;
    }

    public static function GetAtoms()
    {
        return [];
    }

    public static function GetAtomsEx($strControlID = false, $boolEx = false)
    {
        return [];
    }

    public static function GetJSControl($arControl, $arParams = [])
    {
        return [];
    }

    public static function OnBuildConditionAtomList() {}

    /**
     * @param array|bool $arOperators
     *
     * @return array
     */
    public static function GetLogic($arOperators = false)
    {
        $arOperatorsList = [
            BT_COND_LOGIC_EQ => [
                'ID' => BT_COND_LOGIC_EQ,
                'OP' => [
                    'Y' => 'in_array(#VALUE#, #FIELD#)',
                    'N' => '#FIELD# == #VALUE#',
                ],
                'PARENT' => ' || ',
                'MULTI_SEP' => ' || ',
                'VALUE' => 'Equal',
                'LABEL' => Loc::getMessage('BT_COND_LOGIC_EQ_LABEL'),
            ],
            BT_COND_LOGIC_NOT_EQ => [
                'ID' => BT_COND_LOGIC_NOT_EQ,
                'OP' => [
                    'Y' => '!in_array(#VALUE#, #FIELD#)',
                    'N' => '#FIELD# != #VALUE#',
                ],
                'PARENT' => ' && ',
                'MULTI_SEP' => ' && ',
                'VALUE' => 'Not',
                'LABEL' => Loc::getMessage('BT_COND_LOGIC_NOT_EQ_LABEL'),
            ],
            BT_COND_LOGIC_GR => [
                'ID' => BT_COND_LOGIC_GR,
                'OP' => [
                    'N' => '#FIELD# > #VALUE#',
                    'Y' => 'CGlobalCondCtrl::LogicGreat(#FIELD#, #VALUE#)',
                ],
                'VALUE' => 'Great',
                'LABEL' => Loc::getMessage('BT_COND_LOGIC_GR_LABEL'),
            ],
            BT_COND_LOGIC_LS => [
                'ID' => BT_COND_LOGIC_LS,
                'OP' => [
                    'N' => '#FIELD# < #VALUE#',
                    'Y' => 'CGlobalCondCtrl::LogicLess(#FIELD#, #VALUE#)',
                ],
                'VALUE' => 'Less',
                'LABEL' => Loc::getMessage('BT_COND_LOGIC_LS_LABEL'),
            ],
            BT_COND_LOGIC_EGR => [
                'ID' => BT_COND_LOGIC_EGR,
                'OP' => [
                    'N' => '#FIELD# >= #VALUE#',
                    'Y' => 'CGlobalCondCtrl::LogicEqualGreat(#FIELD#, #VALUE#)',
                ],
                'VALUE' => 'EqGr',
                'LABEL' => Loc::getMessage('BT_COND_LOGIC_EGR_LABEL'),
            ],
            BT_COND_LOGIC_ELS => [
                'ID' => BT_COND_LOGIC_ELS,
                'OP' => [
                    'N' => '#FIELD# <= #VALUE#',
                    'Y' => 'CGlobalCondCtrl::LogicEqualLess(#FIELD#, #VALUE#)',
                ],
                'VALUE' => 'EqLs',
                'LABEL' => Loc::getMessage('BT_COND_LOGIC_ELS_LABEL'),
            ],
            BT_COND_LOGIC_CONT => [
                'ID' => BT_COND_LOGIC_CONT,
                'OP' => [
                    'N' => 'false !== mb_strpos(#FIELD#, #VALUE#)',
                    'Y' => 'CGlobalCondCtrl::LogicContain(#FIELD#, #VALUE#)',
                ],
                'PARENT' => ' || ',
                'MULTI_SEP' => ' || ',
                'VALUE' => 'Contain',
                'LABEL' => Loc::getMessage('BT_COND_LOGIC_CONT_LABEL'),
            ],
            BT_COND_LOGIC_NOT_CONT => [
                'ID' => BT_COND_LOGIC_NOT_CONT,
                'OP' => [
                    'N' => 'false === mb_strpos(#FIELD#, #VALUE#)',
                    'Y' => 'CGlobalCondCtrl::LogicNotContain(#FIELD#, #VALUE#)',
                ],
                'PARENT' => ' && ',
                'MULTI_SEP' => ' && ',
                'VALUE' => 'NotCont',
                'LABEL' => Loc::getMessage('BT_COND_LOGIC_NOT_CONT_LABEL'),
            ],
        ];

        $boolSearch = false;
        $arSearch = [];
        if (!empty($arOperators) && is_array($arOperators)) {
            foreach ($arOperators as &$intOneOp) {
                if (isset($arOperatorsList[$intOneOp])) {
                    $boolSearch = true;
                    $arSearch[$intOneOp] = $arOperatorsList[$intOneOp];
                }
            }
            unset($intOneOp);
        }

        return $boolSearch ? $arSearch : $arOperatorsList;
    }

    /**
     * @param array|bool $arOperators
     * @param array|bool $arLabels
     *
     * @return array
     */
    public static function GetLogicEx($arOperators = false, $arLabels = false)
    {
        $arOperatorsList = static::GetLogic($arOperators);
        if (!empty($arLabels) && is_array($arLabels)) {
            foreach ($arOperatorsList as &$arOneOperator) {
                if (isset($arLabels[$arOneOperator['ID']])) {
                    $arOneOperator['LABEL'] = $arLabels[$arOneOperator['ID']];
                }
            }
            if (isset($arOneOperator)) {
                unset($arOneOperator);
            }
        }

        return $arOperatorsList;
    }

    public static function GetLogicAtom($arLogic)
    {
        if (!empty($arLogic) && is_array($arLogic)) {
            $arValues = [];
            foreach ($arLogic as &$arOneLogic) {
                $arValues[$arOneLogic['VALUE']] = $arOneLogic['LABEL'];
            }
            if (isset($arOneLogic)) {
                unset($arOneLogic);
            }
            $arResult = [
                'id' => 'logic',
                'name' => 'logic',
                'type' => 'select',
                'values' => $arValues,
                'defaultText' => current($arValues),
                'defaultValue' => key($arValues),
            ];

            return $arResult;
        }

        return false;
    }

    public static function GetValueAtom($arValue)
    {
        if (empty($arValue) || !isset($arValue['type'])) {
            $arResult = [
                'type' => 'input',
            ];
        } else {
            $arResult = $arValue;
        }
        $arResult['id'] = 'value';
        $arResult['name'] = 'value';

        return $arResult;
    }

    public static function CheckLogic($strValue, $arLogic, $boolShow = false)
    {
        $boolShow = (true === $boolShow);
        if (empty($arLogic) || !is_array($arLogic)) {
            return false;
        }
        $strResult = '';
        foreach ($arLogic as &$arOneLogic) {
            if ($strValue === $arOneLogic['VALUE']) {
                $strResult = $arOneLogic['VALUE'];

                break;
            }
        }
        if (isset($arOneLogic)) {
            unset($arOneLogic);
        }
        if ('' === $strResult) {
            if ($boolShow) {
                $arOneLogic = current($arLogic);
                $strResult = $arOneLogic['VALUE'];
            }
        }

        return '' === $strResult ? false : $strResult;
    }

    public static function SearchLogic($strValue, $arLogic)
    {
        $mxResult = false;
        if (empty($arLogic) || !is_array($arLogic)) {
            return $mxResult;
        }
        foreach ($arLogic as &$arOneLogic) {
            if ($strValue === $arOneLogic['VALUE']) {
                $mxResult = $arOneLogic;

                break;
            }
        }
        if (isset($arOneLogic)) {
            unset($arOneLogic);
        }

        return $mxResult;
    }

    public static function Check($arOneCondition, $arParams, $arControl, $boolShow)
    {
        $boolShow = (true === $boolShow);
        $boolError = false;
        $boolFatalError = false;
        $arMsg = [];

        $arValues = [
            'logic' => '',
            'value' => '',
        ];
        $arLabels = [];

        static $intTimeOffset = false;
        if (false === $intTimeOffset) {
            $intTimeOffset = CTimeZone::GetOffset();
        }

        if ($boolShow) {
            if (!isset($arOneCondition['logic'])) {
                $arOneCondition['logic'] = '';
                $boolError = true;
            }
            if (!isset($arOneCondition['value'])) {
                $arOneCondition['value'] = '';
                $boolError = true;
            }
            $strLogic = static::CheckLogic($arOneCondition['logic'], $arControl['LOGIC'], $boolShow);
            if (false === $strLogic) {
                $boolError = true;
                $boolFatalError = true;
                $arMsg[] = Loc::getMessage('BT_MOD_COND_ERR_CHECK_DATA_LOGIC_ABSENT');
            } else {
                $arValues['logic'] = $strLogic;
            }

            $boolValueError = static::ClearValue($arOneCondition['value']);
            if (!$boolValueError) {
                $boolMulti = is_array($arOneCondition['value']);

                switch ($arControl['FIELD_TYPE']) {
                    case 'int':
                        if ($boolMulti) {
                            foreach ($arOneCondition['value'] as &$intOneValue) {
                                $intOneValue = (int) $intOneValue;
                            }
                            unset($intOneValue);
                        } else {
                            $arOneCondition['value'] = (int) $arOneCondition['value'];
                        }

                        break;

                    case 'double':
                        if ($boolMulti) {
                            foreach ($arOneCondition['value'] as &$dblOneValue) {
                                $dblOneValue = (float) $dblOneValue;
                            }
                            unset($dblOneValue);
                        } else {
                            $arOneCondition['value'] = (float) $arOneCondition['value'];
                        }

                        break;

                    case 'char':
                        if ($boolMulti) {
                            foreach ($arOneCondition['value'] as &$strOneValue) {
                                $strOneValue = mb_substr($strOneValue, 0, 1);
                            }
                            unset($strOneValue);
                        } else {
                            $arOneCondition['value'] = mb_substr($arOneCondition['value'], 0, 1);
                        }

                        break;

                    case 'string':
                        $intMaxLen = (int) (isset($arControl['FIELD_LENGTH']) ? $arControl['FIELD_LENGTH'] : 255);
                        if ($intMaxLen <= 0) {
                            $intMaxLen = 255;
                        }
                        if ($boolMulti) {
                            foreach ($arOneCondition['value'] as &$strOneValue) {
                                $strOneValue = mb_substr($strOneValue, 0, $intMaxLen);
                            }
                            unset($strOneValue);
                        } else {
                            $arOneCondition['value'] = mb_substr($arOneCondition['value'], 0, $intMaxLen);
                        }

                        break;

                    case 'text':
                        break;

                    case 'date':
                    case 'datetime':
                        if ('date' === $arControl['FIELD_TYPE']) {
                            $strFormat = 'SHORT';
                            $intOffset = 0;
                        } else {
                            $strFormat = 'FULL';
                            $intOffset = $intTimeOffset;
                        }
                        $boolValueError = static::ConvertInt2DateTime($arOneCondition['value'], $strFormat, $intOffset);

                        break;

                    default:
                        $boolValueError = true;

                        break;
                }
                if (!$boolValueError) {
                    if ($boolMulti) {
                        $arOneCondition['value'] = array_values(array_unique($arOneCondition['value']));
                    }
                }
            }

            if (!$boolValueError) {
                if (isset($arControl['PHP_VALUE']) && !empty($arControl['PHP_VALUE']['VALIDATE'])) {
                    $arValidate = static::Validate($arOneCondition, $arParams, $arControl, $boolShow);
                    if (false === $arValidate) {
                        $boolValueError = true;
                    } else {
                        if (isset($arValidate['err_cond']) && 'Y' === $arValidate['err_cond']) {
                            $boolValueError = true;
                            if (isset($arValidate['err_cond_mess']) && !empty($arValidate['err_cond_mess'])) {
                                $arMsg = array_merge($arMsg, $arValidate['err_cond_mess']);
                            }
                        } else {
                            $arValues['value'] = $arValidate['values'];
                            if (isset($arValidate['labels'])) {
                                $arLabels['value'] = $arValidate['labels'];
                            }
                        }
                    }
                } else {
                    $arValues['value'] = $arOneCondition['value'];
                }
            }

            if ($boolValueError) {
                $boolError = $boolValueError;
            }
        } else {
            if (!isset($arOneCondition['logic']) || !isset($arOneCondition['value'])) {
                $boolError = true;
            } else {
                $strLogic = static::CheckLogic($arOneCondition['logic'], $arControl['LOGIC'], $boolShow);
                if (!$strLogic) {
                    $boolError = true;
                } else {
                    $arValues['logic'] = $arOneCondition['logic'];
                }
            }

            if (!$boolError) {
                $boolError = static::ClearValue($arOneCondition['value']);
            }

            if (!$boolError) {
                $boolMulti = is_array($arOneCondition['value']);

                switch ($arControl['FIELD_TYPE']) {
                    case 'int':
                        if ($boolMulti) {
                            foreach ($arOneCondition['value'] as &$intOneValue) {
                                $intOneValue = (int) $intOneValue;
                            }
                            unset($intOneValue);
                        } else {
                            $arOneCondition['value'] = (int) $arOneCondition['value'];
                        }

                        break;

                    case 'double':
                        if ($boolMulti) {
                            foreach ($arOneCondition['value'] as &$dblOneValue) {
                                $dblOneValue = (float) $dblOneValue;
                            }
                            unset($dblOneValue);
                        } else {
                            $arOneCondition['value'] = (float) $arOneCondition['value'];
                        }

                        break;

                    case 'char':
                        if ($boolMulti) {
                            foreach ($arOneCondition['value'] as &$strOneValue) {
                                $strOneValue = mb_substr($strOneValue, 0, 1);
                            }
                            unset($strOneValue);
                        } else {
                            $arOneCondition['value'] = mb_substr($arOneCondition['value'], 0, 1);
                        }

                        break;

                    case 'string':
                        $intMaxLen = (int) (isset($arControl['FIELD_LENGTH']) ? $arControl['FIELD_LENGTH'] : 255);
                        if ($intMaxLen <= 0) {
                            $intMaxLen = 255;
                        }
                        if ($boolMulti) {
                            foreach ($arOneCondition['value'] as &$strOneValue) {
                                $strOneValue = mb_substr($strOneValue, 0, $intMaxLen);
                            }
                            unset($strOneValue);
                        } else {
                            $arOneCondition['value'] = mb_substr($arOneCondition['value'], 0, $intMaxLen);
                        }

                        break;

                    case 'text':
                        break;

                    case 'date':
                    case 'datetime':
                        if ('date' === $arControl['FIELD_TYPE']) {
                            $strFormat = 'SHORT';
                            $intOffset = 0;
                        } else {
                            $strFormat = 'FULL';
                            $intOffset = $intTimeOffset;
                        }
                        $boolError = static::ConvertDateTime2Int($arOneCondition['value'], $strFormat, $intOffset);

                        break;

                    default:
                        $boolError = true;

                        break;
                }
                if ($boolMulti) {
                    if (!$boolError) {
                        $arOneCondition['value'] = array_values(array_unique($arOneCondition['value']));
                    }
                }
            }

            if (!$boolError) {
                if (isset($arControl['PHP_VALUE']) && !empty($arControl['PHP_VALUE']['VALIDATE'])) {
                    $arValidate = static::Validate($arOneCondition, $arParams, $arControl, $boolShow);
                    if (false === $arValidate) {
                        $boolError = true;
                    } else {
                        $arValues['value'] = $arValidate['values'];
                        if (isset($arValidate['labels'])) {
                            $arLabels['value'] = $arValidate['labels'];
                        }
                    }
                } else {
                    $arValues['value'] = $arOneCondition['value'];
                }
            }
        }

        if ($boolShow) {
            $arResult = [
                'id' => $arParams['COND_NUM'],
                'controlId' => $arControl['ID'],
                'values' => $arValues,
            ];
            if (!empty($arLabels)) {
                $arResult['labels'] = $arLabels;
            }
            if ($boolError) {
                $arResult['err_cond'] = 'Y';
                if ($boolFatalError) {
                    $arResult['fatal_err_cond'] = 'Y';
                }
                if (!empty($arMsg)) {
                    $arResult['err_cond_mess'] = implode('. ', $arMsg);
                }
            }

            return $arResult;
        }

        $arResult = $arValues;

        return !$boolError ? $arResult : false;
    }

    public static function Validate($arOneCondition, $arParams, $arControl, $boolShow)
    {
        static $userNameFormat = null;

        $boolShow = (true === $boolShow);
        $boolError = false;
        $arMsg = [];

        $arResult = [
            'values' => '',
        ];

        if (!(isset($arControl['PHP_VALUE'], $arControl['PHP_VALUE']['VALIDATE']) && !empty($arControl['PHP_VALUE']['VALIDATE']))) {
            $boolError = true;
        }

        if (!$boolError) {
            if ($boolShow) {
                // validate for show
                $boolMulti = is_array($arOneCondition['value']);

                switch ($arControl['PHP_VALUE']['VALIDATE']) {
                    case 'element':
                        $rsItems = CIBlockElement::GetList(
                            [],
                            ['ID' => $arOneCondition['value']],
                            false,
                            false,
                            ['ID', 'NAME']
                        );
                        if ($boolMulti) {
                            $arCheckResult = [];
                            while ($arItem = $rsItems->Fetch()) {
                                $arCheckResult[(int) $arItem['ID']] = $arItem['NAME'];
                            }
                            if (!empty($arCheckResult)) {
                                $arResult['values'] = array_keys($arCheckResult);
                                $arResult['labels'] = array_values($arCheckResult);
                            } else {
                                $boolError = true;
                                $arMsg[] = Loc::getMessage('BT_MOD_COND_ERR_CHECK_DATA_ELEMENT_ABSENT_MULTI');
                            }
                        } else {
                            if ($arItem = $rsItems->Fetch()) {
                                $arResult['values'] = (int) $arItem['ID'];
                                $arResult['labels'] = $arItem['NAME'];
                            } else {
                                $boolError = true;
                                $arMsg[] = Loc::getMessage('BT_MOD_COND_ERR_CHECK_DATA_ELEMENT_ABSENT');
                            }
                        }

                        break;

                    case 'section':
                        $rsSections = CIBlockSection::GetList(
                            [],
                            ['ID' => $arOneCondition['value']],
                            false,
                            ['ID', 'NAME']
                        );
                        if ($boolMulti) {
                            $arCheckResult = [];
                            while ($arSection = $rsSections->Fetch()) {
                                $arCheckResult[(int) $arSection['ID']] = $arSection['NAME'];
                            }
                            if (!empty($arCheckResult)) {
                                $arResult['values'] = array_keys($arCheckResult);
                                $arResult['labels'] = array_values($arCheckResult);
                            } else {
                                $boolError = true;
                                $arMsg[] = Loc::getMessage('BT_MOD_COND_ERR_CHECK_DATA_SECTION_ABSENT_MULTI');
                            }
                        } else {
                            if ($arSection = $rsSections->Fetch()) {
                                $arResult['values'] = (int) $arSection['ID'];
                                $arResult['labels'] = $arSection['NAME'];
                            } else {
                                $boolError = true;
                                $arMsg[] = Loc::getMessage('BT_MOD_COND_ERR_CHECK_DATA_SECTION_ABSENT');
                            }
                        }

                        break;

                    case 'iblock':
                        if ($boolMulti) {
                            $arCheckResult = [];
                            foreach ($arOneCondition['value'] as &$intIBlockID) {
                                $strName = CIBlock::GetArrayByID($intIBlockID, 'NAME');
                                if (false !== $strName && null !== $strName) {
                                    $arCheckResult[$intIBlockID] = $strName;
                                }
                            }
                            if (isset($intIBlockID)) {
                                unset($intIBlockID);
                            }
                            if (!empty($arCheckResult)) {
                                $arResult['values'] = array_keys($arCheckResult);
                                $arResult['labels'] = array_values($arCheckResult);
                            } else {
                                $boolError = true;
                                $arMsg[] = Loc::getMessage('BT_MOD_COND_ERR_CHECK_DATA_IBLOCK_ABSENT_MULTI');
                            }
                        } else {
                            $strName = CIBlock::GetArrayByID($arOneCondition['value'], 'NAME');
                            if (false !== $strName && null !== $strName) {
                                $arResult['values'] = $arOneCondition['value'];
                                $arResult['labels'] = $strName;
                            } else {
                                $boolError = true;
                                $arMsg[] = Loc::getMessage('BT_MOD_COND_ERR_CHECK_DATA_IBLOCK_ABSENT');
                            }
                        }

                        break;

                    case 'enumValue':
                        $iterator = Iblock\PropertyEnumerationTable::getList([
                            'select' => ['ID', 'VALUE'],
                            'filter' => ['@ID' => $arOneCondition['value']],
                        ]);
                        if ($boolMulti) {
                            $checkResult = [];
                            while ($row = $iterator->fetch()) {
                                $checkResult[$row['ID']] = $row['VALUE'];
                            }
                            unset($row);
                            if (!empty($checkResult)) {
                                $arResult['values'] = array_keys($checkResult);
                                $arResult['labels'] = array_values($checkResult);
                            } else {
                                $boolError = true;
                                $arMsg[] = Loc::getMessage('BT_MOD_COND_ERR_CHECK_DATA_ENUM_VALUE_ABSENT_MULTI');
                            }
                            unset($checkResult);
                        } else {
                            $row = $iterator->fetch();
                            if (!empty($row)) {
                                $arResult['values'] = $row['ID'];
                                $arResult['labels'] = $row['VALUE'];
                            } else {
                                $boolError = true;
                                $arMsg[] = Loc::getMessage('BT_MOD_COND_ERR_CHECK_DATA_ENUM_VALUE_ABSENT');
                            }
                        }
                        unset($iterator);

                        break;

                    case 'user':
                        if (null === $userNameFormat) {
                            $userNameFormat = CSite::GetNameFormat(true);
                        }
                        if ($boolMulti) {
                            $arCheckResult = [];
                            $userIterator = UserTable::getList([
                                'select' => ['ID', 'LOGIN', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'EMAIL'],
                                'filter' => ['ID' => $arOneCondition['value']],
                            ]);
                            while ($user = $userIterator->fetch()) {
                                $user['ID'] = (int) $user['ID'];
                                $arCheckResult[$user['ID']] = CUser::FormatName($userNameFormat, $user);
                            }
                            if (!empty($arCheckResult)) {
                                $arResult['values'] = array_keys($arCheckResult);
                                $arResult['labels'] = array_values($arCheckResult);
                            } else {
                                $boolError = true;
                                $arMsg[] = Loc::getMessage('BT_MOD_COND_ERR_CHECK_DATA_USER_ABSENT_MULTI');
                            }
                        } else {
                            $userIterator = UserTable::getList([
                                'select' => ['ID', 'LOGIN', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'EMAIL'],
                                'filter' => ['ID' => $arOneCondition['value']],
                            ]);
                            if ($user = $userIterator->fetch()) {
                                $arResult['values'] = (int) $user['ID'];
                                $arResult['labels'] = CUser::FormatName($userNameFormat, $user);
                            } else {
                                $boolError = true;
                                $arMsg[] = Loc::getMessage('BT_MOD_COND_ERR_CHECK_DATA_USER_ABSENT');
                            }
                        }

                        break;

                    case 'list':
                        if (isset($arControl['JS_VALUE'], $arControl['JS_VALUE']['values']) && !empty($arControl['JS_VALUE']['values'])) {
                            if ($boolMulti) {
                                $arCheckResult = [];
                                foreach ($arOneCondition['value'] as &$strValue) {
                                    if (isset($arControl['JS_VALUE']['values'][$strValue])) {
                                        $arCheckResult[] = $strValue;
                                    }
                                }
                                if (isset($strValue)) {
                                    unset($strValue);
                                }
                                if (!empty($arCheckResult)) {
                                    $arResult['values'] = $arCheckResult;
                                } else {
                                    $boolError = true;
                                    $arMsg[] = Loc::getMessage('BT_MOD_COND_ERR_CHECK_DATA_LIST_ABSENT_MULTI');
                                }
                            } else {
                                if (isset($arControl['JS_VALUE']['values'][$arOneCondition['value']])) {
                                    $arResult['values'] = $arOneCondition['value'];
                                } else {
                                    $boolError = true;
                                    $arMsg[] = Loc::getMessage('BT_MOD_COND_ERR_CHECK_DATA_LIST_ABSENT');
                                }
                            }
                        } else {
                            $boolError = true;
                        }

                        break;
                }
            } else {
                // validate for save
                $boolMulti = is_array($arOneCondition['value']);

                switch ($arControl['PHP_VALUE']['VALIDATE']) {
                    case 'element':
                        $rsItems = CIBlockElement::GetList([], ['ID' => $arOneCondition['value']], false, false, ['ID']);
                        if ($boolMulti) {
                            $arCheckResult = [];
                            while ($arItem = $rsItems->Fetch()) {
                                $arCheckResult[] = (int) $arItem['ID'];
                            }
                            if (!empty($arCheckResult)) {
                                $arResult['values'] = $arCheckResult;
                            } else {
                                $boolError = true;
                            }
                        } else {
                            if ($arItem = $rsItems->Fetch()) {
                                $arResult['values'] = (int) $arItem['ID'];
                            } else {
                                $boolError = true;
                            }
                        }

                        break;

                    case 'section':
                        $rsSections = CIBlockSection::GetList([], ['ID' => $arOneCondition['value']], false, ['ID']);
                        if ($boolMulti) {
                            $arCheckResult = [];
                            while ($arSection = $rsSections->Fetch()) {
                                $arCheckResult[] = (int) $arSection['ID'];
                            }
                            if (!empty($arCheckResult)) {
                                $arResult['values'] = $arCheckResult;
                            } else {
                                $boolError = true;
                            }
                        } else {
                            if ($arSection = $rsSections->Fetch()) {
                                $arResult['values'] = (int) $arSection['ID'];
                            } else {
                                $boolError = true;
                            }
                        }

                        break;

                    case 'iblock':
                        if ($boolMulti) {
                            $arCheckResult = [];
                            foreach ($arOneCondition['value'] as &$intIBlockID) {
                                $strName = CIBlock::GetArrayByID($intIBlockID, 'NAME');
                                if (false !== $strName && null !== $strName) {
                                    $arCheckResult[] = $intIBlockID;
                                }
                            }
                            if (isset($intIBlockID)) {
                                unset($intIBlockID);
                            }
                            if (!empty($arCheckResult)) {
                                $arResult['values'] = $arCheckResult;
                            } else {
                                $boolError = true;
                            }
                        } else {
                            $strName = CIBlock::GetArrayByID($arOneCondition['value'], 'NAME');
                            if (false !== $strName && null !== $strName) {
                                $arResult['values'] = $arOneCondition['value'];
                            } else {
                                $boolError = true;
                            }
                        }

                        break;

                    case 'enumValue':
                        $iterator = Iblock\PropertyEnumerationTable::getList([
                            'select' => ['ID'],
                            'filter' => ['@ID' => $arOneCondition['value']],
                        ]);
                        if ($boolMulti) {
                            $checkResult = [];
                            while ($row = $iterator->fetch()) {
                                $checkResult[] = (int) $row['ID'];
                            }
                            unset($row);
                            if (!empty($checkResult)) {
                                $arResult['values'] = $checkResult;
                            } else {
                                $boolError = true;
                            }
                            unset($checkResult);
                        } else {
                            $row = $iterator->fetch();
                            if (!empty($row)) {
                                $arResult['values'] = (int) $row['ID'];
                            } else {
                                $boolError = true;
                            }
                            unset($row);
                        }
                        unset($iterator);

                        break;

                    case 'user':
                        if ($boolMulti) {
                            $arCheckResult = [];
                            $userIterator = UserTable::getList([
                                'select' => ['ID'],
                                'filter' => ['ID' => $arOneCondition['value']],
                            ]);
                            while ($user = $userIterator->fetch()) {
                                $arCheckResult[] = (int) $user['ID'];
                            }
                            if (!empty($arCheckResult)) {
                                $arResult['values'] = $arCheckResult;
                            } else {
                                $boolError = true;
                            }
                        } else {
                            $userIterator = UserTable::getList([
                                'select' => ['ID'],
                                'filter' => ['ID' => $arOneCondition['value']],
                            ]);
                            if ($user = $userIterator->fetch()) {
                                $arResult['values'] = (int) $user['ID'];
                            } else {
                                $boolError = true;
                            }
                        }

                        break;

                    case 'list':
                        if (isset($arControl['JS_VALUE'], $arControl['JS_VALUE']['values']) && !empty($arControl['JS_VALUE']['values'])) {
                            if ($boolMulti) {
                                $arCheckResult = [];
                                foreach ($arOneCondition['value'] as &$strValue) {
                                    if (isset($arControl['JS_VALUE']['values'][$strValue])) {
                                        $arCheckResult[] = $strValue;
                                    }
                                }
                                if (isset($strValue)) {
                                    unset($strValue);
                                }
                                if (!empty($arCheckResult)) {
                                    $arResult['values'] = $arCheckResult;
                                } else {
                                    $boolError = true;
                                }
                            } else {
                                if (isset($arControl['JS_VALUE']['values'][$arOneCondition['value']])) {
                                    $arResult['values'] = $arOneCondition['value'];
                                } else {
                                    $boolError = true;
                                }
                            }
                        } else {
                            $boolError = true;
                        }

                        break;
                }
            }
        }

        if ($boolShow) {
            if ($boolError) {
                $arResult['err_cond'] = 'Y';
                $arResult['err_cond_mess'] = $arMsg;
            }

            return $arResult;
        }

        return !$boolError ? $arResult : false;
    }

    public static function CheckAtoms($arOneCondition, $arParams, $arControl, $boolShow)
    {
        $boolShow = (true === $boolShow);
        $boolError = false;
        $boolFatalError = false;
        $arMsg = [];

        $arValues = [];
        $arLabels = [];

        static $intTimeOffset = false;
        if (false === $intTimeOffset) {
            $intTimeOffset = CTimeZone::GetOffset();
        }

        if (!isset($arControl['ATOMS']) || empty($arControl['ATOMS']) || !is_array($arControl['ATOMS'])) {
            $boolFatalError = true;
            $boolError = true;
            $arMsg[] = Loc::getMessage('BT_GLOBAL_COND_ERR_ATOMS_ABSENT');
        }
        if (!$boolError) {
            $boolValidate = false;
            if ($boolShow) {
                foreach ($arControl['ATOMS'] as &$arOneAtom) {
                    $boolAtomError = false;
                    $strID = $arOneAtom['ATOM']['ID'];
                    $boolMulti = false;
                    if (!isset($arOneCondition[$strID])) {
                        $boolAtomError = true;
                    } else {
                        $boolMulti = is_array($arOneCondition[$strID]);

                        switch ($arOneAtom['ATOM']['FIELD_TYPE']) {
                            case 'int':
                                if ($boolMulti) {
                                    foreach ($arOneCondition[$strID] as &$intOneValue) {
                                        $intOneValue = (int) $intOneValue;
                                    }
                                    if (isset($intOneValue)) {
                                        unset($intOneValue);
                                    }
                                } else {
                                    $arOneCondition[$strID] = (int) $arOneCondition[$strID];
                                }

                                break;

                            case 'double':
                                if ($boolMulti) {
                                    foreach ($arOneCondition[$strID] as &$dblOneValue) {
                                        $dblOneValue = (float) $dblOneValue;
                                    }
                                    if (isset($dblOneValue)) {
                                        unset($dblOneValue);
                                    }
                                } else {
                                    $arOneCondition[$strID] = (float) $arOneCondition[$strID];
                                }

                                break;

                            case 'strdouble':
                                if ($boolMulti) {
                                    foreach ($arOneCondition[$strID] as &$dblOneValue) {
                                        if ('' !== $dblOneValue) {
                                            $dblOneValue = (float) $dblOneValue;
                                        }
                                    }
                                    if (isset($dblOneValue)) {
                                        unset($dblOneValue);
                                    }
                                } else {
                                    if ('' !== $arOneCondition[$strID]) {
                                        $arOneCondition[$strID] = (float) $arOneCondition[$strID];
                                    }
                                }

                                break;

                            case 'char':
                                if ($boolMulti) {
                                    foreach ($arOneCondition[$strID] as &$strOneValue) {
                                        $strOneValue = mb_substr($strOneValue, 0, 1);
                                    }
                                    if (isset($strOneValue)) {
                                        unset($strOneValue);
                                    }
                                } else {
                                    $arOneCondition[$strID] = mb_substr($arOneCondition[$strID], 0, 1);
                                }

                                break;

                            case 'string':
                                $intMaxLen = (int) (isset($arOneAtom['ATOM']['FIELD_LENGTH']) ? $arOneAtom['ATOM']['FIELD_LENGTH'] : 255);
                                if ($intMaxLen <= 0) {
                                    $intMaxLen = 255;
                                }
                                if ($boolMulti) {
                                    foreach ($arOneCondition[$strID] as &$strOneValue) {
                                        $strOneValue = mb_substr($strOneValue, 0, $intMaxLen);
                                    }
                                    if (isset($strOneValue)) {
                                        unset($strOneValue);
                                    }
                                } else {
                                    $arOneCondition[$strID] = mb_substr($arOneCondition[$strID], 0, $intMaxLen);
                                }

                                break;

                            case 'text':
                                break;

                            case 'date':
                            case 'datetime':
                                if ('date' === $arOneAtom['ATOM']['FIELD_TYPE']) {
                                    $strFormat = 'SHORT';
                                    $intOffset = 0;
                                } else {
                                    $strFormat = 'FULL';
                                    $intOffset = $intTimeOffset;
                                }
                                $boolAtomError = static::ConvertInt2DateTime($arOneCondition[$strID], $strFormat, $intOffset);

                                break;

                            default:
                                $boolAtomError = true;
                        }
                    }
                    if (!$boolAtomError) {
                        if ($boolMulti) {
                            $arOneCondition[$strID] = array_values(array_unique($arOneCondition[$strID]));
                        }
                        $arValues[$strID] = $arOneCondition[$strID];
                        if (isset($arOneAtom['ATOM']['VALIDATE']) && !empty($arOneAtom['ATOM']['VALIDATE'])) {
                            $boolValidate = true;
                        }
                    } else {
                        $arValues[$strID] = '';
                    }
                    if ($boolAtomError) {
                        $boolError = true;
                    }
                }
                if (isset($arOneAtom)) {
                    unset($arOneAtom);
                }

                if (!$boolError) {
                    if ($boolValidate) {
                        $arValidate = static::ValidateAtoms($arValues, $arParams, $arControl, $boolShow);
                        if (false === $arValidate) {
                            $boolError = true;
                        } else {
                            if (isset($arValidate['err_cond']) && 'Y' === $arValidate['err_cond']) {
                                $boolError = true;
                                if (isset($arValidate['err_cond_mess']) && !empty($arValidate['err_cond_mess'])) {
                                    $arMsg = array_merge($arMsg, $arValidate['err_cond_mess']);
                                }
                            } else {
                                $arValues = $arValidate['values'];
                                if (isset($arValidate['labels'])) {
                                    $arLabels = $arValidate['labels'];
                                }
                            }
                        }
                    }
                }
            } else {
                foreach ($arControl['ATOMS'] as &$arOneAtom) {
                    $boolAtomError = false;
                    $strID = $arOneAtom['ATOM']['ID'];
                    $strName = $arOneAtom['JS']['name'];
                    $boolMulti = false;
                    if (!isset($arOneCondition[$strName])) {
                        $boolAtomError = true;
                    } else {
                        $boolMulti = is_array($arOneCondition[$strName]);
                    }
                    if (!$boolAtomError) {
                        switch ($arOneAtom['ATOM']['FIELD_TYPE']) {
                            case 'int':
                                if ($boolMulti) {
                                    foreach ($arOneCondition[$strName] as &$intOneValue) {
                                        $intOneValue = (int) $intOneValue;
                                    }
                                    if (isset($intOneValue)) {
                                        unset($intOneValue);
                                    }
                                } else {
                                    $arOneCondition[$strName] = (int) $arOneCondition[$strName];
                                }

                                break;

                            case 'double':
                                if ($boolMulti) {
                                    foreach ($arOneCondition[$strName] as &$dblOneValue) {
                                        $dblOneValue = (float) $dblOneValue;
                                    }
                                    if (isset($dblOneValue)) {
                                        unset($dblOneValue);
                                    }
                                } else {
                                    $arOneCondition[$strName] = (float) $arOneCondition[$strName];
                                }

                                break;

                            case 'strdouble':
                                if ($boolMulti) {
                                    foreach ($arOneCondition[$strName] as &$dblOneValue) {
                                        if ('' !== $dblOneValue) {
                                            $dblOneValue = (float) $dblOneValue;
                                        }
                                    }
                                    if (isset($dblOneValue)) {
                                        unset($dblOneValue);
                                    }
                                } else {
                                    if ('' !== $arOneCondition[$strName]) {
                                        $arOneCondition[$strName] = (float) $arOneCondition[$strName];
                                    }
                                }

                                break;

                            case 'char':
                                if ($boolMulti) {
                                    foreach ($arOneCondition[$strName] as &$strOneValue) {
                                        $strOneValue = mb_substr($strOneValue, 0, 1);
                                    }
                                    if (isset($strOneValue)) {
                                        unset($strOneValue);
                                    }
                                } else {
                                    $arOneCondition[$strName] = mb_substr($arOneCondition[$strName], 0, 1);
                                }

                                break;

                            case 'string':
                                $intMaxLen = (int) (isset($arOneAtom['ATOM']['FIELD_LENGTH']) ? $arOneAtom['ATOM']['FIELD_LENGTH'] : 255);
                                if ($intMaxLen <= 0) {
                                    $intMaxLen = 255;
                                }
                                if ($boolMulti) {
                                    foreach ($arOneCondition[$strName] as &$strOneValue) {
                                        $strOneValue = mb_substr($strOneValue, 0, $intMaxLen);
                                    }
                                    if (isset($strOneValue)) {
                                        unset($strOneValue);
                                    }
                                } else {
                                    $arOneCondition[$strName] = mb_substr($arOneCondition[$strName], 0, $intMaxLen);
                                }

                                break;

                            case 'text':
                                break;

                            case 'date':
                            case 'datetime':
                                if ('date' === $arOneAtom['ATOM']['FIELD_TYPE']) {
                                    $strFormat = 'SHORT';
                                    $intOffset = 0;
                                } else {
                                    $strFormat = 'FULL';
                                    $intOffset = $intTimeOffset;
                                }
                                $boolAtomError = static::ConvertDateTime2Int($arOneCondition[$strName], $strFormat, $intOffset);

                                break;

                            default:
                                $boolAtomError = true;
                        }
                        if (!$boolAtomError) {
                            if ($boolMulti) {
                                $arOneCondition[$strName] = array_values(array_unique($arOneCondition[$strName]));
                            }
                            $arValues[$strID] = $arOneCondition[$strName];
                            if (isset($arOneAtom['ATOM']['VALIDATE']) && !empty($arOneAtom['ATOM']['VALIDATE'])) {
                                $boolValidate = true;
                            }
                        } else {
                            $arValues[$strID] = '';
                        }
                    }
                    if ($boolAtomError) {
                        $boolError = true;
                    }
                }
                if (isset($arOneAtom)) {
                    unset($arOneAtom);
                }

                if (!$boolError) {
                    if ($boolValidate) {
                        $arValidate = static::ValidateAtoms($arValues, $arParams, $arControl, $boolShow);
                        if (false === $arValidate) {
                            $boolError = true;
                        } else {
                            $arValues = $arValidate['values'];
                            if (isset($arValidate['labels'])) {
                                $arLabels = $arValidate['labels'];
                            }
                        }
                    }
                }
            }
        }

        if ($boolShow) {
            $arResult = [
                'id' => $arParams['COND_NUM'],
                'controlId' => $arControl['ID'],
                'values' => $arValues,
            ];
            if (!empty($arLabels)) {
                $arResult['labels'] = $arLabels;
            }
            if ($boolError) {
                $arResult['err_cond'] = 'Y';
                if ($boolFatalError) {
                    $arResult['fatal_err_cond'] = 'Y';
                }
                if (!empty($arMsg)) {
                    $arResult['err_cond_mess'] = implode('. ', $arMsg);
                }
            }

            return $arResult;
        }

        return !$boolError ? $arValues : false;
    }

    public static function ValidateAtoms($arValues, $arParams, $arControl, $boolShow)
    {
        static $userNameFormat = null;

        $boolShow = (true === $boolShow);
        $boolError = false;
        $arMsg = [];

        $arResult = [
            'values' => [],
            'labels' => [],
            'titles' => [],
        ];

        if (!isset($arControl['ATOMS']) || empty($arControl['ATOMS']) || !is_array($arControl['ATOMS'])) {
            $boolError = true;
            $arMsg[] = Loc::getMessage('BT_GLOBAL_COND_ERR_ATOMS_ABSENT');
        }
        if (!$boolError) {
            if ($boolShow) {
                foreach ($arControl['ATOMS'] as &$arOneAtom) {
                    $strID = $arOneAtom['ATOM']['ID'];
                    if (!isset($arOneAtom['ATOM']['VALIDATE']) || empty($arOneAtom['ATOM']['VALIDATE'])) {
                        $arResult['values'][$strID] = $arValues[$strID];

                        continue;
                    }

                    switch ($arOneAtom['ATOM']['VALIDATE']) {
                        case 'list':
                            if (isset($arOneAtom['JS'], $arOneAtom['JS']['values']) && !empty($arOneAtom['JS']['values'])) {
                                if (is_array($arValues[$strID])) {
                                    $arCheckResult = [];
                                    foreach ($arValues[$strID] as &$strValue) {
                                        if (isset($arOneAtom['JS']['values'][$strValue])) {
                                            $arCheckResult[] = $strValue;
                                        }
                                    }
                                    if (isset($strValue)) {
                                        unset($strValue);
                                    }
                                    if (!empty($arCheckResult)) {
                                        $arResult['values'][$strID] = $arCheckResult;
                                    } else {
                                        $boolError = true;
                                        $arMsg[] = Loc::getMessage('BT_MOD_COND_ERR_CHECK_DATA_LIST_ABSENT_MULTI');
                                    }
                                } else {
                                    if (isset($arOneAtom['JS']['values'][$arValues[$strID]])) {
                                        $arResult['values'][$strID] = $arValues[$strID];
                                    } else {
                                        $boolError = true;
                                        $arMsg[] = Loc::getMessage('BT_MOD_COND_ERR_CHECK_DATA_LIST_ABSENT');
                                    }
                                }
                            } else {
                                $boolError = true;
                            }

                            break;

                        case 'element':
                            $rsItems = CIBlockElement::GetList([], ['ID' => $arValues[$strID]], false, false, ['ID', 'NAME']);
                            if (is_array($arValues[$strID])) {
                                $arCheckResult = [];
                                while ($arItem = $rsItems->Fetch()) {
                                    $arCheckResult[(int) $arItem['ID']] = $arItem['NAME'];
                                }
                                if (!empty($arCheckResult)) {
                                    $arResult['values'][$strID] = array_keys($arCheckResult);
                                    $arResult['labels'][$strID] = array_values($arCheckResult);
                                } else {
                                    $boolError = true;
                                    $arMsg[] = Loc::getMessage('BT_MOD_COND_ERR_CHECK_DATA_ELEMENT_ABSENT_MULTI');
                                }
                            } else {
                                if ($arItem = $rsItems->Fetch()) {
                                    $arResult['values'][$strID] = (int) $arItem['ID'];
                                    $arResult['labels'][$strID] = $arItem['NAME'];
                                } else {
                                    $boolError = true;
                                    $arMsg[] = Loc::getMessage('BT_MOD_COND_ERR_CHECK_DATA_ELEMENT_ABSENT');
                                }
                            }

                            break;

                        case 'section':
                            $rsSections = CIBlockSection::GetList([], ['ID' => $arValues[$strID]], false, ['ID', 'NAME']);
                            if (is_array($arValues[$strID])) {
                                $arCheckResult = [];
                                while ($arSection = $rsSections->Fetch()) {
                                    $arCheckResult[(int) $arSection['ID']] = $arSection['NAME'];
                                }
                                if (!empty($arCheckResult)) {
                                    $arResult['values'][$strID] = array_keys($arCheckResult);
                                    $arResult['labels'][$strID] = array_values($arCheckResult);
                                } else {
                                    $boolError = true;
                                    $arMsg[] = Loc::getMessage('BT_MOD_COND_ERR_CHECK_DATA_SECTION_ABSENT_MULTI');
                                }
                            } else {
                                if ($arSection = $rsSections->Fetch()) {
                                    $arResult['values'][$strID] = (int) $arSection['ID'];
                                    $arResult['labels'][$strID] = $arSection['NAME'];
                                } else {
                                    $boolError = true;
                                    $arMsg[] = Loc::getMessage('BT_MOD_COND_ERR_CHECK_DATA_SECTION_ABSENT');
                                }
                            }

                            break;

                        case 'iblock':
                            if (is_array($arValues[$strID])) {
                                $arCheckResult = [];
                                foreach ($arValues[$strID] as &$intIBlockID) {
                                    $strName = CIBlock::GetArrayByID($intIBlockID, 'NAME');
                                    if (false !== $strName && null !== $strName) {
                                        $arCheckResult[$intIBlockID] = $strName;
                                    }
                                }
                                if (isset($intIBlockID)) {
                                    unset($intIBlockID);
                                }
                                if (!empty($arCheckResult)) {
                                    $arResult['values'][$strID] = array_keys($arCheckResult);
                                    $arResult['labels'][$strID] = array_values($arCheckResult);
                                } else {
                                    $boolError = true;
                                    $arMsg[] = Loc::getMessage('BT_MOD_COND_ERR_CHECK_DATA_IBLOCK_ABSENT_MULTI');
                                }
                            } else {
                                $strName = CIBlock::GetArrayByID($arValues[$strID], 'NAME');
                                if (false !== $strName && null !== $strName) {
                                    $arResult['values'][$strID] = $arValues[$strID];
                                    $arResult['labels'][$strID] = $strName;
                                } else {
                                    $boolError = true;
                                    $arMsg[] = Loc::getMessage('BT_MOD_COND_ERR_CHECK_DATA_IBLOCK_ABSENT');
                                }
                            }

                            break;

                        case 'user':
                            if (null === $userNameFormat) {
                                $userNameFormat = CSite::GetNameFormat(true);
                            }
                            if (is_array($arValues[$strID])) {
                                $arCheckResult = [];
                                $userIterator = UserTable::getList([
                                    'select' => ['ID', 'LOGIN', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'EMAIL'],
                                    'filter' => ['ID' => $arValues[$strID]],
                                ]);
                                while ($user = $userIterator->fetch()) {
                                    $user['ID'] = (int) $user['ID'];
                                    $arCheckResult[$user['ID']] = CUser::FormatName($userNameFormat, $user);
                                }
                                if (!empty($arCheckResult)) {
                                    $arResult['values'][$strID] = array_keys($arCheckResult);
                                    $arResult['labels'][$strID] = array_values($arCheckResult);
                                } else {
                                    $boolError = true;
                                    $arMsg[] = Loc::getMessage('BT_MOD_COND_ERR_CHECK_DATA_USER_ABSENT_MULTI');
                                }
                            } else {
                                $userIterator = UserTable::getList([
                                    'select' => ['ID', 'LOGIN', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'EMAIL'],
                                    'filter' => ['ID' => $arValues[$strID]],
                                ]);
                                if ($user = $userIterator->fetch()) {
                                    $arResult['values'] = (int) $user['ID'];
                                    $arResult['labels'] = CUser::FormatName($userNameFormat, $user);
                                } else {
                                    $boolError = true;
                                    $arMsg[] = Loc::getMessage('BT_MOD_COND_ERR_CHECK_DATA_USER_ABSENT');
                                }
                            }

                            break;
                    }
                }
                if (isset($arOneAtom)) {
                    unset($arOneAtom);
                }
            } else {
                foreach ($arControl['ATOMS'] as &$arOneAtom) {
                    $strID = $arOneAtom['ATOM']['ID'];
                    if (!isset($arOneAtom['ATOM']['VALIDATE']) || empty($arOneAtom['ATOM']['VALIDATE'])) {
                        $arResult['values'][$strID] = $arValues[$strID];

                        continue;
                    }

                    switch ($arOneAtom['ATOM']['VALIDATE']) {
                        case 'list':
                            if (isset($arOneAtom['JS'], $arOneAtom['JS']['values']) && !empty($arOneAtom['JS']['values'])) {
                                if (is_array($arValues[$strID])) {
                                    $arCheckResult = [];
                                    foreach ($arValues[$strID] as &$strValue) {
                                        if (isset($arOneAtom['JS']['values'][$strValue])) {
                                            $arCheckResult[] = $strValue;
                                        }
                                    }
                                    if (isset($strValue)) {
                                        unset($strValue);
                                    }
                                    if (!empty($arCheckResult)) {
                                        $arResult['values'][$strID] = $arCheckResult;
                                    } else {
                                        $boolError = true;
                                    }
                                } else {
                                    if (isset($arOneAtom['JS']['values'][$arValues[$strID]])) {
                                        $arResult['values'][$strID] = $arValues[$strID];
                                    } else {
                                        $boolError = true;
                                    }
                                }
                            } else {
                                $boolError = true;
                            }

                            break;

                        case 'element':
                            $rsItems = CIBlockElement::GetList([], ['ID' => $arValues[$strID]], false, false, ['ID']);
                            if (is_array($arValues[$strID])) {
                                $arCheckResult = [];
                                while ($arItem = $rsItems->Fetch()) {
                                    $arCheckResult[] = (int) $arItem['ID'];
                                }
                                if (!empty($arCheckResult)) {
                                    $arResult['values'][$strID] = $arCheckResult;
                                } else {
                                    $boolError = true;
                                }
                            } else {
                                if ($arItem = $rsItems->Fetch()) {
                                    $arResult['values'][$strID] = (int) $arItem['ID'];
                                } else {
                                    $boolError = true;
                                }
                            }

                            break;

                        case 'section':
                            $rsSections = CIBlockSection::GetList([], ['ID' => $arValues[$strID]], false, ['ID']);
                            if (is_array($arValues[$strID])) {
                                $arCheckResult = [];
                                while ($arSection = $rsSections->Fetch()) {
                                    $arCheckResult[] = (int) $arSection['ID'];
                                }
                                if (!empty($arCheckResult)) {
                                    $arResult['values'][$strID] = $arCheckResult;
                                } else {
                                    $boolError = true;
                                }
                            } else {
                                if ($arSection = $rsSections->Fetch()) {
                                    $arResult['values'][$strID] = (int) $arSection['ID'];
                                } else {
                                    $boolError = true;
                                }
                            }

                            break;

                        case 'iblock':
                            if (is_array($arValues[$strID])) {
                                $arCheckResult = [];
                                foreach ($arValues[$strID] as &$intIBlockID) {
                                    $strName = CIBlock::GetArrayByID($intIBlockID, 'NAME');
                                    if (false !== $strName && null !== $strName) {
                                        $arCheckResult[] = $intIBlockID;
                                    }
                                }
                                if (isset($intIBlockID)) {
                                    unset($intIBlockID);
                                }
                                if (!empty($arCheckResult)) {
                                    $arResult['values'][$strID] = $arCheckResult;
                                } else {
                                    $boolError = true;
                                }
                            } else {
                                $strName = CIBlock::GetArrayByID($arValues[$strID], 'NAME');
                                if (false !== $strName && null !== $strName) {
                                    $arResult['values'][$strID] = $arValues[$strID];
                                } else {
                                    $boolError = true;
                                }
                            }

                            break;

                        case 'user':
                            if (is_array($arValues[$strID])) {
                                $arCheckResult = [];
                                $userIterator = UserTable::getList([
                                    'select' => ['ID'],
                                    'filter' => ['ID' => $arValues[$strID]],
                                ]);
                                while ($user = $userIterator->fetch()) {
                                    $arCheckResult[] = (int) $user['ID'];
                                }
                                if (!empty($arCheckResult)) {
                                    $arResult['values'][$strID] = $arCheckResult;
                                } else {
                                    $boolError = true;
                                }
                            } else {
                                $userIterator = UserTable::getList([
                                    'select' => ['ID'],
                                    'filter' => ['ID' => $arValues[$strID]],
                                ]);
                                if ($user = $userIterator->fetch()) {
                                    $arCheckResult[] = (int) $user['ID'];
                                } else {
                                    $boolError = true;
                                }
                            }

                            break;
                    }
                }
                if (isset($arOneAtom)) {
                    unset($arOneAtom);
                }
            }
        }

        if ($boolShow) {
            if ($boolError) {
                $arResult['err_cond'] = 'Y';
                $arResult['err_cond_mess'] = $arMsg;
            }

            return $arResult;
        }

        return !$boolError ? $arResult : false;
    }

    public static function LogicGreat($arField, $mxValue)
    {
        $boolResult = false;
        if (!is_array($arField)) {
            $arField = [$arField];
        }
        if (!empty($arField)) {
            foreach ($arField as &$mxOneValue) {
                if (null === $mxOneValue || false === $mxOneValue || '' === $mxOneValue) {
                    continue;
                }
                if ($mxOneValue > $mxValue) {
                    $boolResult = true;

                    break;
                }
            }
            if (isset($mxOneValue)) {
                unset($mxOneValue);
            }
        }

        return $boolResult;
    }

    public static function LogicLess($arField, $mxValue)
    {
        $boolResult = false;
        if (!is_array($arField)) {
            $arField = [$arField];
        }
        if (!empty($arField)) {
            foreach ($arField as &$mxOneValue) {
                if (null === $mxOneValue || false === $mxOneValue || '' === $mxOneValue) {
                    continue;
                }
                if ($mxOneValue < $mxValue) {
                    $boolResult = true;

                    break;
                }
            }
            if (isset($mxOneValue)) {
                unset($mxOneValue);
            }
        }

        return $boolResult;
    }

    public static function LogicEqualGreat($arField, $mxValue)
    {
        $boolResult = false;
        if (!is_array($arField)) {
            $arField = [$arField];
        }
        if (!empty($arField)) {
            foreach ($arField as &$mxOneValue) {
                if (null === $mxOneValue || false === $mxOneValue || '' === $mxOneValue) {
                    continue;
                }
                if ($mxOneValue >= $mxValue) {
                    $boolResult = true;

                    break;
                }
            }
            if (isset($mxOneValue)) {
                unset($mxOneValue);
            }
        }

        return $boolResult;
    }

    public static function LogicEqualLess($arField, $mxValue)
    {
        $boolResult = false;
        if (!is_array($arField)) {
            $arField = [$arField];
        }
        if (!empty($arField)) {
            foreach ($arField as &$mxOneValue) {
                if (null === $mxOneValue || false === $mxOneValue || '' === $mxOneValue) {
                    continue;
                }
                if ($mxOneValue <= $mxValue) {
                    $boolResult = true;

                    break;
                }
            }
            if (isset($mxOneValue)) {
                unset($mxOneValue);
            }
        }

        return $boolResult;
    }

    public static function LogicContain($arField, $mxValue)
    {
        $boolResult = false;
        if (!is_array($arField)) {
            $arField = [$arField];
        }
        if (!empty($arField)) {
            foreach ($arField as &$mxOneValue) {
                if (false !== mb_strpos($mxOneValue, $mxValue)) {
                    $boolResult = true;

                    break;
                }
            }
            if (isset($mxOneValue)) {
                unset($mxOneValue);
            }
        }

        return $boolResult;
    }

    public static function LogicNotContain($arField, $mxValue)
    {
        $boolResult = true;
        if (!is_array($arField)) {
            $arField = [$arField];
        }
        if (!empty($arField)) {
            foreach ($arField as &$mxOneValue) {
                if (false !== mb_strpos($mxOneValue, $mxValue)) {
                    $boolResult = false;

                    break;
                }
            }
            if (isset($mxOneValue)) {
                unset($mxOneValue);
            }
        }

        return $boolResult;
    }

    public static function ClearValue(&$mxValues)
    {
        $boolLocalError = false;
        if (is_array($mxValues)) {
            if (!empty($mxValues)) {
                $arResult = [];
                foreach ($mxValues as &$strOneValue) {
                    $strOneValue = trim((string) $strOneValue);
                    if ('' !== $strOneValue) {
                        $arResult[] = $strOneValue;
                    }
                }
                if (isset($strOneValue)) {
                    unset($strOneValue);
                }
                $mxValues = $arResult;
                if (empty($mxValues)) {
                    $boolLocalError = true;
                }
            } else {
                $boolLocalError = true;
            }
        } else {
            $mxValues = trim((string) $mxValues);
            if ('' === $mxValues) {
                $boolLocalError = true;
            }
        }

        return $boolLocalError;
    }

    public static function ConvertInt2DateTime(&$mxValues, $strFormat, $intOffset)
    {
        global $DB;

        $boolValueError = false;
        if (is_array($mxValues)) {
            foreach ($mxValues as &$strValue) {
                if ($strValue.'!' === (int) $strValue.'!') {
                    $strValue = ConvertTimeStamp($strValue + $intOffset, $strFormat);
                }
                if (!$DB->IsDate($strValue, false, false, $strFormat)) {
                    $boolValueError = true;
                }
            }
            if (isset($strValue)) {
                unset($strValue);
            }
        } else {
            if ($mxValues.'!' === (int) $mxValues.'!') {
                $mxValues = ConvertTimeStamp($mxValues + $intOffset, $strFormat);
            }
            $boolValueError = !$DB->IsDate($mxValues, false, false, $strFormat);
        }

        return $boolValueError;
    }

    public static function ConvertDateTime2Int(&$mxValues, $strFormat, $intOffset)
    {
        global $DB;

        $boolError = false;
        if (is_array($mxValues)) {
            $boolLocalErr = false;
            $arLocal = [];
            foreach ($mxValues as &$strValue) {
                if ($strValue.'!' !== (int) $strValue.'!') {
                    if (!$DB->IsDate($strValue, false, false, $strFormat)) {
                        $boolError = true;
                        $boolLocalErr = true;

                        break;
                    }
                    $arLocal[] = MakeTimeStamp($strValue) - $intOffset;
                } else {
                    $arLocal[] = $strValue;
                }
            }
            if (isset($strValue)) {
                unset($strValue);
            }
            if (!$boolLocalErr) {
                $mxValues = $arLocal;
            }
        } else {
            if ($mxValues.'!' !== (int) $mxValues.'!') {
                if (!$DB->IsDate($mxValues, false, false, $strFormat)) {
                    $boolError = true;
                } else {
                    $mxValues = MakeTimeStamp($mxValues) - $intOffset;
                }
            }
        }

        return $boolError;
    }

    /**
     * @param false|string $controlId
     * @param bool         $extendedMode
     *
     * @return array|false
     */
    protected static function searchControlAtoms(array $atoms, $controlId, $extendedMode)
    {
        if (empty($atoms)) {
            return false;
        }

        $extendedMode = (true === $extendedMode);
        if (!$extendedMode) {
            foreach (array_keys($atoms) as $index) {
                foreach (array_keys($atoms[$index]) as $atomId) {
                    $atoms[$index][$atomId] = $atoms[$index][$atomId]['JS'];
                }
            }
            unset($atomId, $index);
        }

        if (false === $controlId) {
            return $atoms;
        }

        $controlId = (string) $controlId;

        return isset($atoms[$controlId]) ? $atoms[$controlId] : false;
    }

    protected static function searchControl(array $controls, $controlId)
    {
        if (empty($controls)) {
            return false;
        }

        if (false === $controlId) {
            return $controls;
        }

        $controlId = (string) $controlId;

        return isset($controls[$controlId]) ? $controls[$controlId] : false;
    }
}

class CGlobalCondCtrlComplex extends CGlobalCondCtrl
{
    public static function GetControlDescr()
    {
        $strClassName = static::class;

        return [
            'COMPLEX' => 'Y',
            'GetControlShow' => [$strClassName, 'GetControlShow'],
            'GetConditionShow' => [$strClassName, 'GetConditionShow'],
            'IsGroup' => [$strClassName, 'IsGroup'],
            'Parse' => [$strClassName, 'Parse'],
            'Generate' => [$strClassName, 'Generate'],
            'ApplyValues' => [$strClassName, 'ApplyValues'],
            'InitParams' => [$strClassName, 'InitParams'],
            'CONTROLS' => static::GetControls(),
        ];
    }

    public static function GetConditionShow($arParams)
    {
        if (!isset($arParams['ID'])) {
            return false;
        }
        $arControl = static::GetControls($arParams['ID']);
        if (false === $arControl) {
            return false;
        }
        if (!isset($arParams['DATA'])) {
            return false;
        }

        return static::Check($arParams['DATA'], $arParams, $arControl, true);
    }

    public static function Parse($arOneCondition)
    {
        if (!isset($arOneCondition['controlId'])) {
            return false;
        }
        $arControl = static::GetControls($arOneCondition['controlId']);
        if (false === $arControl) {
            return false;
        }

        return static::Check($arOneCondition, $arOneCondition, $arControl, false);
    }

    public static function Generate($arOneCondition, $arParams, $arControl, $arSubs = false)
    {
        $strResult = '';
        $resultValues = [];
        $arValues = false;

        if (is_string($arControl)) {
            $arControl = static::GetControls($arControl);
        }
        $boolError = !is_array($arControl);

        if (!$boolError) {
            $arValues = static::Check($arOneCondition, $arOneCondition, $arControl, false);
            $boolError = (false === $arValues);
        }
        if (!$boolError) {
            $boolError = !isset($arControl['MULTIPLE']);
        }

        if (!$boolError) {
            $arLogic = static::SearchLogic($arValues['logic'], $arControl['LOGIC']);
            if (!isset($arLogic['OP'][$arControl['MULTIPLE']]) || empty($arLogic['OP'][$arControl['MULTIPLE']])) {
                $boolError = true;
            } else {
                $strField = $arParams['FIELD'].'[\''.$arControl['FIELD'].'\']';

                switch ($arControl['FIELD_TYPE']) {
                    case 'int':
                    case 'double':
                        if (is_array($arValues['value'])) {
                            if (!isset($arLogic['MULTI_SEP'])) {
                                $boolError = true;
                            } else {
                                foreach ($arValues['value'] as &$value) {
                                    $resultValues[] = str_replace(
                                        ['#FIELD#', '#VALUE#'],
                                        [$strField, $value],
                                        $arLogic['OP'][$arControl['MULTIPLE']]
                                    );
                                }
                                unset($value);
                                $strResult = '('.implode($arLogic['MULTI_SEP'], $resultValues).')';
                                unset($resultValues);
                            }
                        } else {
                            $strResult = str_replace(
                                ['#FIELD#', '#VALUE#'],
                                [$strField, $arValues['value']],
                                $arLogic['OP'][$arControl['MULTIPLE']]
                            );
                        }

                        break;

                    case 'char':
                    case 'string':
                    case 'text':
                        if (is_array($arValues['value'])) {
                            $boolError = true;
                        } else {
                            $strResult = str_replace(
                                ['#FIELD#', '#VALUE#'],
                                [$strField, '"'.EscapePHPString($arValues['value']).'"'],
                                $arLogic['OP'][$arControl['MULTIPLE']]
                            );
                        }

                        break;

                    case 'date':
                    case 'datetime':
                        if (is_array($arValues['value'])) {
                            $boolError = true;
                        } else {
                            $strResult = str_replace(
                                ['#FIELD#', '#VALUE#'],
                                [$strField, $arValues['value']],
                                $arLogic['OP'][$arControl['MULTIPLE']]
                            );
                        }

                        break;
                }
            }
        }

        return !$boolError ? $strResult : false;
    }

    /**
     * @param bool|string $strControlID
     *
     * @return array|bool
     */
    public static function GetControls($strControlID = false)
    {
        return false;
    }
}

class CGlobalCondCtrlAtoms extends CGlobalCondCtrl
{
    /**
     * @return array|bool
     */
    public static function GetControlDescr()
    {
        $className = static::class;
        $controls = static::GetControls();
        if (empty($controls) || !is_array($controls)) {
            return false;
        }
        $result = [];
        foreach ($controls as &$oneControl) {
            unset($oneControl['ATOMS']);
            $row = $oneControl;
            $row['GetControlShow'] = [$className, 'GetControlShow'];
            $row['GetConditionShow'] = [$className, 'GetConditionShow'];
            $row['IsGroup'] = [$className, 'IsGroup'];
            $row['Parse'] = [$className, 'Parse'];
            $row['Generate'] = [$className, 'Generate'];
            $row['ApplyValues'] = [$className, 'ApplyValues'];
            $row['InitParams'] = [$className, 'InitParams'];

            $result[] = $row;
            unset($row);
        }
        unset($oneControl, $controls, $className);

        return $result;
    }

    public static function GetConditionShow($params)
    {
        if (!isset($params['ID'])) {
            return false;
        }
        $atoms = static::GetAtomsEx($params['ID'], true);
        if (empty($atoms)) {
            return false;
        }
        $control = [
            'ID' => $params['ID'],
            'ATOMS' => $atoms,
        ];
        unset($atoms);

        return static::CheckAtoms($params['DATA'], $params, $control, true);
    }

    public static function Parse($condition)
    {
        if (!isset($condition['controlId'])) {
            return false;
        }
        $atoms = static::GetAtomsEx($condition['controlId'], true);
        if (empty($atoms)) {
            return false;
        }
        $control = [
            'ID' => $condition['controlId'],
            'ATOMS' => $atoms,
        ];
        unset($atoms);

        return static::CheckAtoms($condition, $condition, $control, false);
    }

    public static function Generate($condition, $params, $control, $childrens = false)
    {
        return '';
    }

    public static function GetAtomsEx($controlId = false, $extendedMode = false)
    {
        return [];
    }

    public static function GetAtoms()
    {
        return static::GetAtomsEx(false, false);
    }

    /**
     * @return array|string
     */
    public static function GetControlID()
    {
        $atoms = static::GetAtomsEx(false, true);

        return empty($atoms) ? [] : array_keys($atoms);
    }

    /**
     * @param bool|string $strControlID
     *
     * @return array|bool
     */
    public static function GetControls($strControlID = false)
    {
        return [];
    }

    public static function GetControlShow($params)
    {
        $controls = static::GetControls();
        if (empty($controls) || !is_array($controls)) {
            return [];
        }
        $result = [];
        foreach ($controls as $controlId => $data) {
            $row = [
                'controlId' => $data['ID'],
                'group' => false,
                'label' => $data['LABEL'],
                'showIn' => static::GetShowIn($params['SHOW_IN_GROUPS']),
                'control' => [],
            ];
            if (isset($data['PREFIX'])) {
                $row['control'][] = $data['PREFIX'];
            }
            if (empty($row['control'])) {
                $row['control'] = array_values($data['ATOMS']);
            } else {
                foreach ($data['ATOMS'] as &$atom) {
                    $row['control'][] = $atom;
                }
                unset($atom);
            }

            $result[] = $row;
        }
        unset($controlId, $data, $controls);

        return $result;
    }
}

class CGlobalCondCtrlGroup extends CGlobalCondCtrl
{
    public static function GetControlDescr()
    {
        $className = static::class;

        return [
            'ID' => static::GetControlID(),
            'GROUP' => 'Y',
            'GetControlShow' => [$className, 'GetControlShow'],
            'GetConditionShow' => [$className, 'GetConditionShow'],
            'IsGroup' => [$className, 'IsGroup'],
            'Parse' => [$className, 'Parse'],
            'Generate' => [$className, 'Generate'],
            'ApplyValues' => [$className, 'ApplyValues'],
        ];
    }

    public static function GetControlShow($arParams)
    {
        return [
            'controlId' => static::GetControlID(),
            'group' => true,
            'label' => Loc::getMessage('BT_CLOBAL_COND_GROUP_LABEL'),
            'defaultText' => Loc::getMessage('BT_CLOBAL_COND_GROUP_DEF_TEXT'),
            'showIn' => static::GetShowIn($arParams['SHOW_IN_GROUPS']),
            'visual' => static::GetVisual(),
            'control' => array_values(static::GetAtoms()),
        ];
    }

    public static function GetConditionShow($arParams)
    {
        $error = false;
        $values = [];
        foreach (static::GetAtoms() as $atom) {
            if (
                !isset($arParams['DATA'][$atom['id']])
                || !is_string($arParams['DATA'][$atom['id']])
                || !isset($atom['values'][$arParams['DATA'][$atom['id']]])
            ) {
                $error = true;
            }

            $values[$atom['id']] = ($error ? '' : $arParams['DATA'][$atom['id']]);
        }
        unset($atom);

        $result = [
            'id' => $arParams['COND_NUM'],
            'controlId' => static::GetControlID(),
            'values' => $values,
        ];
        if ($error) {
            $result['err_cond'] = 'Y';
        }
        unset($values);

        return $result;
    }

    /**
     * @return array|string
     */
    public static function GetControlID()
    {
        return 'CondGroup';
    }

    public static function GetAtoms()
    {
        return [
            'All' => [
                'id' => 'All',
                'name' => 'aggregator',
                'type' => 'select',
                'values' => [
                    'AND' => Loc::getMessage('BT_CLOBAL_COND_GROUP_SELECT_ALL'),
                    'OR' => Loc::getMessage('BT_CLOBAL_COND_GROUP_SELECT_ANY'),
                ],
                'defaultText' => Loc::getMessage('BT_CLOBAL_COND_GROUP_SELECT_DEF'),
                'defaultValue' => 'AND',
                'first_option' => '...',
            ],
            'True' => [
                'id' => 'True',
                'name' => 'value',
                'type' => 'select',
                'values' => [
                    'True' => Loc::getMessage('BT_CLOBAL_COND_GROUP_SELECT_TRUE'),
                    'False' => Loc::getMessage('BT_CLOBAL_COND_GROUP_SELECT_FALSE'),
                ],
                'defaultText' => Loc::getMessage('BT_CLOBAL_COND_GROUP_SELECT_DEF'),
                'defaultValue' => 'True',
                'first_option' => '...',
            ],
        ];
    }

    public static function GetVisual()
    {
        return [
            'controls' => [
                'All',
                'True',
            ],
            'values' => [
                [
                    'All' => 'AND',
                    'True' => 'True',
                ],
                [
                    'All' => 'AND',
                    'True' => 'False',
                ],
                [
                    'All' => 'OR',
                    'True' => 'True',
                ],
                [
                    'All' => 'OR',
                    'True' => 'False',
                ],
            ],
            'logic' => [
                [
                    'style' => 'condition-logic-and',
                    'message' => Loc::getMessage('BT_CLOBAL_COND_GROUP_LOGIC_AND'),
                ],
                [
                    'style' => 'condition-logic-and',
                    'message' => Loc::getMessage('BT_CLOBAL_COND_GROUP_LOGIC_NOT_AND'),
                ],
                [
                    'style' => 'condition-logic-or',
                    'message' => Loc::getMessage('BT_CLOBAL_COND_GROUP_LOGIC_OR'),
                ],
                [
                    'style' => 'condition-logic-or',
                    'message' => Loc::getMessage('BT_CLOBAL_COND_GROUP_LOGIC_NOT_OR'),
                ],
            ],
        ];
    }

    public static function IsGroup($strControlID = false)
    {
        return 'Y';
    }

    public static function Parse($arOneCondition)
    {
        $error = false;
        $result = [];
        foreach (static::GetAtoms() as $atom) {
            if (
                !isset($arOneCondition[$atom['name']])
                || !is_string($arOneCondition[$atom['name']])
                || !isset($atom['values'][$arOneCondition[$atom['name']]])
            ) {
                $error = true;

                break;
            }
            $result[$atom['id']] = $arOneCondition[$atom['name']];
        }
        unset($atom);

        return !$error ? $result : false;
    }

    public static function Generate($arOneCondition, $arParams, $arControl, $arSubs = false)
    {
        $result = '';
        $error = false;

        foreach (static::GetAtoms() as $atom) {
            if (
                !isset($arOneCondition[$atom['id']])
                || !is_string($arOneCondition[$atom['id']])
                || !isset($atom['values'][$arOneCondition[$atom['id']]])
            ) {
                $error = true;
            }
        }
        unset($atom);

        if (!isset($arSubs) || !is_array($arSubs)) {
            $error = true;
        } elseif (empty($arSubs)) {
            return '(1 == 1)';
        }

        if (!$error) {
            if ('AND' === $arOneCondition['All']) {
                $prefix = '';
                $logic = ' && ';
                $itemPrefix = ('True' === $arOneCondition['True'] ? '' : '!');
            } else {
                $itemPrefix = '';
                if ('True' === $arOneCondition['True']) {
                    $prefix = '';
                    $logic = ' || ';
                } else {
                    $prefix = '!';
                    $logic = ' && ';
                }
            }

            $commandLine = $itemPrefix.implode($logic.$itemPrefix, $arSubs);
            if ('' !== $prefix) {
                $commandLine = $prefix.'('.$commandLine.')';
            }
            if ('' !== $commandLine) {
                $commandLine = '('.$commandLine.')';
            }
            $result = $commandLine;
            unset($commandLine);
        }

        return $result;
    }

    public static function ApplyValues($arOneCondition, $arControl)
    {
        return isset($arOneCondition['True']) && 'True' === $arOneCondition['True'];
    }
}

class CCatalogCondCtrl extends CGlobalCondCtrl {}

class CCatalogCondCtrlComplex extends CGlobalCondCtrlComplex {}

class CCatalogCondCtrlGroup extends CGlobalCondCtrlGroup
{
    public static function GetControlDescr()
    {
        $description = parent::GetControlDescr();
        $description['SORT'] = 100;

        return $description;
    }
}

class CCatalogCondCtrlIBlockFields extends CCatalogCondCtrlComplex
{
    public static function GetControlDescr()
    {
        $description = parent::GetControlDescr();
        $description['SORT'] = 200;

        return $description;
    }

    /**
     * @return array|string
     */
    public static function GetControlID()
    {
        return [
            'CondIBElement',
            'CondIBIBlock',
            'CondIBSection',
            'CondIBCode',
            'CondIBXmlID',
            'CondIBName',
            'CondIBDateActiveFrom',
            'CondIBDateActiveTo',
            'CondIBSort',
            'CondIBPreviewText',
            'CondIBDetailText',
            'CondIBDateCreate',
            'CondIBCreatedBy',
            'CondIBTimestampX',
            'CondIBModifiedBy',
            'CondIBTags',
            'CondCatQuantity',
            'CondCatWeight',
            'CondCatVatID',
            'CondCatVatIncluded',
        ];
    }

    public static function GetControlShow($arParams)
    {
        $arControls = static::GetControls();
        $arResult = [
            'controlgroup' => true,
            'group' => false,
            'label' => Loc::getMessage('BT_MOD_CATALOG_COND_CMP_IBLOCK_CONTROLGROUP_LABEL'),
            'showIn' => static::GetShowIn($arParams['SHOW_IN_GROUPS']),
            'children' => [],
        ];
        foreach ($arControls as $arOneControl) {
            $arResult['children'][] = [
                'controlId' => $arOneControl['ID'],
                'group' => false,
                'label' => $arOneControl['LABEL'],
                'showIn' => static::GetShowIn($arParams['SHOW_IN_GROUPS']),
                'control' => [
                    [
                        'id' => 'prefix',
                        'type' => 'prefix',
                        'text' => $arOneControl['PREFIX'],
                    ],
                    static::GetLogicAtom($arOneControl['LOGIC']),
                    static::GetValueAtom($arOneControl['JS_VALUE']),
                ],
            ];
        }
        unset($arOneControl);

        return $arResult;
    }

    /**
     * @param bool|string $strControlID
     *
     * @return array|bool
     */
    public static function GetControls($strControlID = false)
    {
        $vatList = [];
        $vatIterator = Catalog\VatTable::getList([
            'select' => ['ID', 'NAME', 'SORT'],
            'order' => ['SORT' => 'ASC'],
        ]);
        while ($vat = $vatIterator->fetch()) {
            $vat['ID'] = (int) $vat['ID'];
            $vatList[$vat['ID']] = $vat['NAME'];
        }
        unset($vat, $vatIterator);

        $arControlList = [
            'CondIBElement' => [
                'ID' => 'CondIBElement',
                'FIELD' => 'ID',
                'FIELD_TYPE' => 'int',
                'LABEL' => Loc::getMessage('BT_MOD_CATALOG_COND_CMP_IBLOCK_ELEMENT_ID_LABEL'),
                'PREFIX' => Loc::getMessage('BT_MOD_CATALOG_COND_CMP_IBLOCK_ELEMENT_ID_PREFIX'),
                'LOGIC' => static::GetLogic([BT_COND_LOGIC_EQ, BT_COND_LOGIC_NOT_EQ]),
                'JS_VALUE' => [
                    'type' => 'multiDialog',
                    'popup_url' => self::getAdminSection().'cat_product_search_dialog.php',
                    'popup_params' => [
                        'lang' => LANGUAGE_ID,
                        'caller' => 'discount_rules',
                        'allow_select_parent' => 'Y',
                    ],
                    'param_id' => 'n',
                    'show_value' => 'Y',
                ],
                'PHP_VALUE' => [
                    'VALIDATE' => 'element',
                ],
            ],
            'CondIBIBlock' => [
                'ID' => 'CondIBIBlock',
                'FIELD' => 'IBLOCK_ID',
                'FIELD_TYPE' => 'int',
                'LABEL' => Loc::getMessage('BT_MOD_CATALOG_COND_CMP_IBLOCK_IBLOCK_ID_LABEL'),
                'PREFIX' => Loc::getMessage('BT_MOD_CATALOG_COND_CMP_IBLOCK_IBLOCK_ID_PREFIX'),
                'LOGIC' => static::GetLogic([BT_COND_LOGIC_EQ, BT_COND_LOGIC_NOT_EQ]),
                'JS_VALUE' => [
                    'type' => 'popup',
                    'popup_url' => self::getAdminSection().'cat_iblock_search.php',
                    'popup_params' => [
                        'lang' => LANGUAGE_ID,
                        'discount' => 'Y',
                    ],
                    'param_id' => 'n',
                    'show_value' => 'Y',
                ],
                'PHP_VALUE' => [
                    'VALIDATE' => 'iblock',
                ],
            ],
            'CondIBSection' => [
                'ID' => 'CondIBSection',
                'PARENT' => false,
                'FIELD' => 'SECTION_ID',
                'FIELD_TYPE' => 'int',
                'LABEL' => Loc::getMessage('BT_MOD_CATALOG_COND_CMP_IBLOCK_SECTION_ID_LABEL'),
                'PREFIX' => Loc::getMessage('BT_MOD_CATALOG_COND_CMP_IBLOCK_SECTION_ID_PREFIX'),
                'LOGIC' => static::GetLogic([BT_COND_LOGIC_EQ, BT_COND_LOGIC_NOT_EQ]),
                'JS_VALUE' => [
                    'type' => 'popup',
                    'popup_url' => self::getAdminSection().'iblock_section_search.php',
                    'popup_params' => [
                        'lang' => LANGUAGE_ID,
                        'discount' => 'Y',
                        'simplename' => 'Y',
                    ],
                    'param_id' => 'n',
                    'show_value' => 'Y',
                ],
                'PHP_VALUE' => [
                    'VALIDATE' => 'section',
                ],
            ],
            'CondIBCode' => [
                'ID' => 'CondIBCode',
                'FIELD' => 'CODE',
                'FIELD_TYPE' => 'string',
                'FIELD_LENGTH' => 255,
                'LABEL' => Loc::getMessage('BT_MOD_CATALOG_COND_CMP_IBLOCK_CODE_LABEL'),
                'PREFIX' => Loc::getMessage('BT_MOD_CATALOG_COND_CMP_IBLOCK_CODE_PREFIX'),
                'LOGIC' => static::GetLogic([BT_COND_LOGIC_EQ, BT_COND_LOGIC_NOT_EQ, BT_COND_LOGIC_CONT, BT_COND_LOGIC_NOT_CONT]),
                'JS_VALUE' => [
                    'type' => 'input',
                ],
                'PHP_VALUE' => '',
            ],
            'CondIBXmlID' => [
                'ID' => 'CondIBXmlID',
                'FIELD' => 'XML_ID',
                'FIELD_TYPE' => 'string',
                'FIELD_LENGTH' => 255,
                'LABEL' => Loc::getMessage('BT_MOD_CATALOG_COND_CMP_IBLOCK_XML_ID_LABEL'),
                'PREFIX' => Loc::getMessage('BT_MOD_CATALOG_COND_CMP_IBLOCK_XML_ID_PREFIX'),
                'LOGIC' => static::GetLogic([BT_COND_LOGIC_EQ, BT_COND_LOGIC_NOT_EQ, BT_COND_LOGIC_CONT, BT_COND_LOGIC_NOT_CONT]),
                'JS_VALUE' => [
                    'type' => 'input',
                ],
                'PHP_VALUE' => '',
            ],
            'CondIBName' => [
                'ID' => 'CondIBName',
                'FIELD' => 'NAME',
                'FIELD_TYPE' => 'string',
                'FIELD_LENGTH' => 255,
                'LABEL' => Loc::getMessage('BT_MOD_CATALOG_COND_CMP_IBLOCK_NAME_LABEL'),
                'PREFIX' => Loc::getMessage('BT_MOD_CATALOG_COND_CMP_IBLOCK_NAME_PREFIX'),
                'LOGIC' => static::GetLogic([BT_COND_LOGIC_EQ, BT_COND_LOGIC_NOT_EQ, BT_COND_LOGIC_CONT, BT_COND_LOGIC_NOT_CONT]),
                'JS_VALUE' => [
                    'type' => 'input',
                ],
                'PHP_VALUE' => '',
            ],
            'CondIBDateActiveFrom' => [
                'ID' => 'CondIBDateActiveFrom',
                'FIELD' => 'DATE_ACTIVE_FROM',
                'FIELD_TYPE' => 'datetime',
                'LABEL' => Loc::getMessage('BT_MOD_CATALOG_COND_CMP_IBLOCK_DATE_ACTIVE_FROM_LABEL'),
                'PREFIX' => Loc::getMessage('BT_MOD_CATALOG_COND_CMP_IBLOCK_DATE_ACTIVE_FROM_PREFIX'),
                'LOGIC' => static::GetLogic([BT_COND_LOGIC_EQ, BT_COND_LOGIC_NOT_EQ, BT_COND_LOGIC_GR, BT_COND_LOGIC_LS, BT_COND_LOGIC_EGR, BT_COND_LOGIC_ELS]),
                'JS_VALUE' => [
                    'type' => 'datetime',
                    'format' => 'datetime',
                ],
                'PHP_VALUE' => '',
            ],
            'CondIBDateActiveTo' => [
                'ID' => 'CondIBDateActiveTo',
                'FIELD' => 'DATE_ACTIVE_TO',
                'FIELD_TYPE' => 'datetime',
                'LABEL' => Loc::getMessage('BT_MOD_CATALOG_COND_CMP_IBLOCK_DATE_ACTIVE_TO_LABEL'),
                'PREFIX' => Loc::getMessage('BT_MOD_CATALOG_COND_CMP_IBLOCK_DATE_ACTIVE_TO_PREFIX'),
                'LOGIC' => static::GetLogic([BT_COND_LOGIC_EQ, BT_COND_LOGIC_NOT_EQ, BT_COND_LOGIC_GR, BT_COND_LOGIC_LS, BT_COND_LOGIC_EGR, BT_COND_LOGIC_ELS]),
                'JS_VALUE' => [
                    'type' => 'datetime',
                    'format' => 'datetime',
                ],
                'PHP_VALUE' => '',
            ],
            'CondIBSort' => [
                'ID' => 'CondIBSort',
                'FIELD' => 'SORT',
                'FIELD_TYPE' => 'int',
                'LABEL' => Loc::getMessage('BT_MOD_CATALOG_COND_CMP_IBLOCK_SORT_LABEL'),
                'PREFIX' => Loc::getMessage('BT_MOD_CATALOG_COND_CMP_IBLOCK_SORT_PREFIX'),
                'LOGIC' => static::GetLogic([BT_COND_LOGIC_EQ, BT_COND_LOGIC_NOT_EQ, BT_COND_LOGIC_GR, BT_COND_LOGIC_LS, BT_COND_LOGIC_EGR, BT_COND_LOGIC_ELS]),
                'JS_VALUE' => [
                    'type' => 'input',
                ],
                'PHP_VALUE' => '',
            ],
            'CondIBPreviewText' => [
                'ID' => 'CondIBPreviewText',
                'FIELD' => 'PREVIEW_TEXT',
                'FIELD_TYPE' => 'text',
                'LABEL' => Loc::getMessage('BT_MOD_CATALOG_COND_CMP_IBLOCK_PREVIEW_TEXT_LABEL'),
                'PREFIX' => Loc::getMessage('BT_MOD_CATALOG_COND_CMP_IBLOCK_PREVIEW_TEXT_PREFIX'),
                'LOGIC' => static::GetLogic([BT_COND_LOGIC_EQ, BT_COND_LOGIC_NOT_EQ, BT_COND_LOGIC_CONT, BT_COND_LOGIC_NOT_CONT]),
                'JS_VALUE' => [
                    'type' => 'input',
                ],
                'PHP_VALUE' => '',
            ],
            'CondIBDetailText' => [
                'ID' => 'CondIBDetailText',
                'FIELD' => 'DETAIL_TEXT',
                'FIELD_TYPE' => 'text',
                'LABEL' => Loc::getMessage('BT_MOD_CATALOG_COND_CMP_IBLOCK_DETAIL_TEXT_LABEL'),
                'PREFIX' => Loc::getMessage('BT_MOD_CATALOG_COND_CMP_IBLOCK_DETAIL_TEXT_PREFIX'),
                'LOGIC' => static::GetLogic([BT_COND_LOGIC_EQ, BT_COND_LOGIC_NOT_EQ, BT_COND_LOGIC_CONT, BT_COND_LOGIC_NOT_CONT]),
                'JS_VALUE' => [
                    'type' => 'input',
                ],
                'PHP_VALUE' => '',
            ],
            'CondIBDateCreate' => [
                'ID' => 'CondIBDateCreate',
                'FIELD' => 'DATE_CREATE',
                'FIELD_TYPE' => 'datetime',
                'LABEL' => Loc::getMessage('BT_MOD_CATALOG_COND_CMP_IBLOCK_DATE_CREATE_LABEL'),
                'PREFIX' => Loc::getMessage('BT_MOD_CATALOG_COND_CMP_IBLOCK_DATE_CREATE_PREFIX'),
                'LOGIC' => static::GetLogic([BT_COND_LOGIC_EQ, BT_COND_LOGIC_NOT_EQ, BT_COND_LOGIC_GR, BT_COND_LOGIC_LS, BT_COND_LOGIC_EGR, BT_COND_LOGIC_ELS]),
                'JS_VALUE' => [
                    'type' => 'datetime',
                    'format' => 'datetime',
                ],
                'PHP_VALUE' => '',
            ],
            'CondIBCreatedBy' => [
                'ID' => 'CondIBCreatedBy',
                'FIELD' => 'CREATED_BY',
                'FIELD_TYPE' => 'int',
                'LABEL' => Loc::getMessage('BT_MOD_CATALOG_COND_CMP_IBLOCK_CREATED_BY_LABEL'),
                'PREFIX' => Loc::getMessage('BT_MOD_CATALOG_COND_CMP_IBLOCK_CREATED_BY_PREFIX'),
                'LOGIC' => static::GetLogic([BT_COND_LOGIC_EQ, BT_COND_LOGIC_NOT_EQ]),
                'JS_VALUE' => [
                    'type' => 'input',
                ],
                'PHP_VALUE' => [
                    'VALIDATE' => 'user',
                ],
            ],
            'CondIBTimestampX' => [
                'ID' => 'CondIBTimestampX',
                'FIELD' => 'TIMESTAMP_X',
                'FIELD_TYPE' => 'datetime',
                'LABEL' => Loc::getMessage('BT_MOD_CATALOG_COND_CMP_IBLOCK_TIMESTAMP_X_LABEL'),
                'PREFIX' => Loc::getMessage('BT_MOD_CATALOG_COND_CMP_IBLOCK_TIMESTAMP_X_PREFIX'),
                'LOGIC' => static::GetLogic([BT_COND_LOGIC_EQ, BT_COND_LOGIC_NOT_EQ, BT_COND_LOGIC_GR, BT_COND_LOGIC_LS, BT_COND_LOGIC_EGR, BT_COND_LOGIC_ELS]),
                'JS_VALUE' => [
                    'type' => 'datetime',
                    'format' => 'datetime',
                ],
                'PHP_VALUE' => '',
            ],
            'CondIBModifiedBy' => [
                'ID' => 'CondIBModifiedBy',
                'FIELD' => 'MODIFIED_BY',
                'FIELD_TYPE' => 'int',
                'LABEL' => Loc::getMessage('BT_MOD_CATALOG_COND_CMP_IBLOCK_MODIFIED_BY_LABEL'),
                'PREFIX' => Loc::getMessage('BT_MOD_CATALOG_COND_CMP_IBLOCK_MODIFIED_BY_PREFIX'),
                'LOGIC' => static::GetLogic([BT_COND_LOGIC_EQ, BT_COND_LOGIC_NOT_EQ]),
                'JS_VALUE' => [
                    'type' => 'input',
                ],
                'PHP_VALUE' => [
                    'VALIDATE' => 'user',
                ],
            ],
            'CondIBTags' => [
                'ID' => 'CondIBTags',
                'FIELD' => 'TAGS',
                'FIELD_TYPE' => 'string',
                'FIELD_LENGTH' => 255,
                'LABEL' => Loc::getMessage('BT_MOD_CATALOG_COND_CMP_IBLOCK_TAGS_LABEL'),
                'PREFIX' => Loc::getMessage('BT_MOD_CATALOG_COND_CMP_IBLOCK_TAGS_PREFIX'),
                'LOGIC' => static::GetLogic([BT_COND_LOGIC_EQ, BT_COND_LOGIC_NOT_EQ, BT_COND_LOGIC_CONT, BT_COND_LOGIC_NOT_CONT]),
                'JS_VALUE' => [
                    'type' => 'input',
                ],
                'PHP_VALUE' => '',
            ],
            'CondCatQuantity' => [
                'ID' => 'CondCatQuantity',
                'PARENT' => false,
                'MODULE_ENTITY' => 'catalog',
                'ENTITY' => 'PRODUCT',
                'FIELD' => 'CATALOG_QUANTITY',
                'FIELD_TABLE' => 'QUANTITY',
                'FIELD_TYPE' => 'double',
                'LABEL' => Loc::getMessage('BT_MOD_CATALOG_COND_CMP_CATALOG_QUANTITY_LABEL'),
                'PREFIX' => Loc::getMessage('BT_MOD_CATALOG_COND_CMP_CATALOG_QUANTITY_PREFIX'),
                'LOGIC' => static::GetLogic([BT_COND_LOGIC_EQ, BT_COND_LOGIC_NOT_EQ, BT_COND_LOGIC_GR, BT_COND_LOGIC_LS, BT_COND_LOGIC_EGR, BT_COND_LOGIC_ELS]),
                'JS_VALUE' => [
                    'type' => 'input',
                ],
            ],
            'CondCatWeight' => [
                'ID' => 'CondCatWeight',
                'PARENT' => false,
                'MODULE_ENTITY' => 'catalog',
                'ENTITY' => 'PRODUCT',
                'FIELD' => 'CATALOG_WEIGHT',
                'FIELD_TABLE' => 'WEIGHT',
                'FIELD_TYPE' => 'double',
                'LABEL' => Loc::getMessage('BT_MOD_CATALOG_COND_CMP_CATALOG_WEIGHT_LABEL'),
                'PREFIX' => Loc::getMessage('BT_MOD_CATALOG_COND_CMP_CATALOG_WEIGHT_PREFIX'),
                'LOGIC' => static::GetLogic([BT_COND_LOGIC_EQ, BT_COND_LOGIC_NOT_EQ, BT_COND_LOGIC_GR, BT_COND_LOGIC_LS, BT_COND_LOGIC_EGR, BT_COND_LOGIC_ELS]),
                'JS_VALUE' => [
                    'type' => 'input',
                ],
                'PHP_VALUE' => '',
            ],
            'CondCatVatID' => [
                'ID' => 'CondCatVatID',
                'PARENT' => false,
                'MODULE_ENTITY' => 'catalog',
                'ENTITY' => 'PRODUCT',
                'FIELD' => 'CATALOG_VAT_ID',
                'FIELD_TABLE' => 'VAT_ID',
                'FIELD_TYPE' => 'int',
                'LABEL' => Loc::getMessage('BT_MOD_CATALOG_COND_CMP_CATALOG_VAT_ID_LABEL'),
                'PREFIX' => Loc::getMessage('BT_MOD_CATALOG_COND_CMP_CATALOG_VAT_ID_PREFIX'),
                'LOGIC' => static::GetLogic([BT_COND_LOGIC_EQ, BT_COND_LOGIC_NOT_EQ]),
                'JS_VALUE' => [
                    'type' => 'select',
                    'values' => $vatList,
                ],
                'PHP_VALUE' => [
                    'VALIDATE' => 'list',
                ],
            ],
            'CondCatVatIncluded' => [
                'ID' => 'CondCatVatIncluded',
                'PARENT' => false,
                'MODULE_ENTITY' => 'catalog',
                'ENTITY' => 'PRODUCT',
                'FIELD' => 'CATALOG_VAT_INCLUDED',
                'FIELD_TABLE' => 'VAT_INCLUDED',
                'FIELD_TYPE' => 'char',
                'LABEL' => Loc::getMessage('BT_MOD_CATALOG_COND_CMP_CATALOG_VAT_INCLUDED_LABEL'),
                'PREFIX' => Loc::getMessage('BT_MOD_CATALOG_COND_CMP_CATALOG_VAT_INCLUDED_PREFIX'),
                'LOGIC' => static::GetLogic([BT_COND_LOGIC_EQ, BT_COND_LOGIC_NOT_EQ]),
                'JS_VALUE' => [
                    'type' => 'select',
                    'values' => [
                        'Y' => Loc::getMessage('BT_MOD_CATALOG_COND_CMP_CATALOG_VAT_INCLUDED_VALUE_YES'),
                        'N' => Loc::getMessage('BT_MOD_CATALOG_COND_CMP_CATALOG_VAT_INCLUDED_VALUE_NO'),
                    ],
                ],
                'PHP_VALUE' => [
                    'VALIDATE' => 'list',
                ],
            ],
        ];
        if (empty($vatList)) {
            unset($arControlList['CondCatVatID'], $arControlList['CondCatVatIncluded']);
        }
        foreach ($arControlList as &$control) {
            if (!isset($control['PARENT'])) {
                $control['PARENT'] = true;
            }
            $control['EXIST_HANDLER'] = 'Y';
            $control['MODULE_ID'] = 'catalog';
            if (!isset($control['MODULE_ENTITY'])) {
                $control['MODULE_ENTITY'] = 'iblock';
            }
            if (!isset($control['ENTITY'])) {
                $control['ENTITY'] = 'ELEMENT';
            }
            if (!isset($control['FIELD_TABLE'])) {
                $control['FIELD_TABLE'] = false;
            }
            $control['MULTIPLE'] = 'N';
            $control['GROUP'] = 'N';
            $control['ENTITY_ID'] = -1;
        }
        unset($control);
        $arControlList['CondIBSection']['MULTIPLE'] = 'Y';

        return static::searchControl($arControlList, $strControlID);
    }

    public static function Generate($arOneCondition, $arParams, $arControl, $arSubs = false)
    {
        $strParentResult = '';
        $strResult = '';
        $parentResultValues = [];
        $resultValues = [];

        if (is_string($arControl)) {
            $arControl = static::GetControls($arControl);
        }
        $boolError = !is_array($arControl);

        if (!$boolError) {
            $arValues = static::Check($arOneCondition, $arOneCondition, $arControl, false);
            $boolError = (false === $arValues);
        }

        if (!$boolError) {
            $boolError = !isset($arControl['MULTIPLE']);
        }

        if (!$boolError) {
            $arLogic = static::SearchLogic($arValues['logic'], $arControl['LOGIC']);
            if (!isset($arLogic['OP'][$arControl['MULTIPLE']]) || empty($arLogic['OP'][$arControl['MULTIPLE']])) {
                $boolError = true;
            } else {
                $useParent = ($arControl['PARENT'] && isset($arLogic['PARENT']));
                $strParent = $arParams['FIELD'].'[\'PARENT_'.$arControl['FIELD'].'\']';
                $strField = $arParams['FIELD'].'[\''.$arControl['FIELD'].'\']';

                switch ($arControl['FIELD_TYPE']) {
                    case 'int':
                    case 'double':
                        if (is_array($arValues['value'])) {
                            if (!isset($arLogic['MULTI_SEP'])) {
                                $boolError = true;
                            } else {
                                foreach ($arValues['value'] as $value) {
                                    if ($useParent) {
                                        $parentResultValues[] = str_replace(
                                            ['#FIELD#', '#VALUE#'],
                                            [$strParent, $value],
                                            $arLogic['OP'][$arControl['MULTIPLE']]
                                        );
                                    }
                                    $resultValues[] = str_replace(
                                        ['#FIELD#', '#VALUE#'],
                                        [$strField, $value],
                                        $arLogic['OP'][$arControl['MULTIPLE']]
                                    );
                                }
                                unset($value);
                                if ($useParent) {
                                    $strParentResult = '('.implode($arLogic['MULTI_SEP'], $parentResultValues).')';
                                }
                                $strResult = '('.implode($arLogic['MULTI_SEP'], $resultValues).')';
                                unset($resultValues, $parentResultValues);
                            }
                        } else {
                            if ($useParent) {
                                $strParentResult = str_replace(
                                    ['#FIELD#', '#VALUE#'],
                                    [$strParent, $arValues['value']],
                                    $arLogic['OP'][$arControl['MULTIPLE']]
                                );
                            }
                            $strResult = str_replace(
                                ['#FIELD#', '#VALUE#'],
                                [$strField, $arValues['value']],
                                $arLogic['OP'][$arControl['MULTIPLE']]
                            );
                        }

                        break;

                    case 'char':
                    case 'string':
                    case 'text':
                        if (is_array($arValues['value'])) {
                            $boolError = true;
                        } else {
                            if ($useParent) {
                                $strParentResult = str_replace(
                                    ['#FIELD#', '#VALUE#'],
                                    [$strParent, '"'.EscapePHPString($arValues['value']).'"'],
                                    $arLogic['OP'][$arControl['MULTIPLE']]
                                );
                            }
                            $strResult = str_replace(
                                ['#FIELD#', '#VALUE#'],
                                [$strField, '"'.EscapePHPString($arValues['value']).'"'],
                                $arLogic['OP'][$arControl['MULTIPLE']]
                            );
                        }

                        break;

                    case 'date':
                    case 'datetime':
                        if (is_array($arValues['value'])) {
                            $boolError = true;
                        } else {
                            if ($useParent) {
                                $strParentResult = str_replace(['#FIELD#', '#VALUE#'], [$strParent, $arValues['value']], $arLogic['OP'][$arControl['MULTIPLE']]);
                            }
                            $strResult = str_replace(['#FIELD#', '#VALUE#'], [$strField, $arValues['value']], $arLogic['OP'][$arControl['MULTIPLE']]);
                            if (!(BT_COND_LOGIC_EQ === $arLogic['ID'] || BT_COND_LOGIC_NOT_EQ === $arLogic['ID'])) {
                                if ($useParent) {
                                    $strParentResult = 'null !== '.$strParent.' && \'\' !== '.$strParent.' && '.$strResult;
                                }
                                $strResult = 'null !== '.$strField.' && \'\' !== '.$strField.' && '.$strResult;
                            }
                        }

                        break;
                }
                $strResult = 'isset('.$strField.') && ('.$strResult.')';
                if ($useParent) {
                    $strResult = '(isset('.$strParent.') ? (('.$strResult.')'.$arLogic['PARENT'].$strParentResult.') : ('.$strResult.'))';
                }
            }
        }

        return !$boolError ? $strResult : false;
    }

    public static function ApplyValues($arOneCondition, $arControl)
    {
        $arResult = [];

        $arLogicID = [
            BT_COND_LOGIC_EQ,
            BT_COND_LOGIC_EGR,
            BT_COND_LOGIC_ELS,
        ];

        if (is_string($arControl)) {
            $arControl = static::GetControls($arControl);
        }
        $boolError = !is_array($arControl);

        if (!$boolError) {
            $arValues = static::Check($arOneCondition, $arOneCondition, $arControl, false);
            if (false === $arValues) {
                $boolError = true;
            }
        }

        if (!$boolError) {
            $arLogic = static::SearchLogic($arValues['logic'], $arControl['LOGIC']);
            if (in_array($arLogic['ID'], $arLogicID, true)) {
                $arResult = [
                    'ID' => $arControl['ID'],
                    'FIELD' => $arControl['FIELD'],
                    'FIELD_TYPE' => $arControl['FIELD_TYPE'],
                    'VALUES' => (is_array($arValues['value']) ? $arValues['value'] : [$arValues['value']]),
                ];
            }
        }

        return !$boolError ? $arResult : false;
    }

    /**
     * @return string
     */
    private static function getAdminSection()
    {
        // TODO: need use \CAdminPage::getSelfFolderUrl, but in general it is impossible now
        return defined('SELF_FOLDER_URL') ? SELF_FOLDER_URL : '/bitrix/admin/';
    }
}

class CCatalogCondCtrlIBlockProps extends CCatalogCondCtrlComplex
{
    public static function GetControlDescr()
    {
        $description = parent::GetControlDescr();
        $description['SORT'] = 300;

        return $description;
    }

    /**
     * @param bool|string $strControlID
     *
     * @return array|bool
     */
    public static function GetControls($strControlID = false)
    {
        $arControlList = [];
        $arIBlockList = [];
        $iterator = Catalog\CatalogIblockTable::getList([
            'select' => ['IBLOCK_ID', 'PRODUCT_IBLOCK_ID'],
        ]);
        while ($arIBlock = $iterator->fetch()) {
            $arIBlock['IBLOCK_ID'] = (int) $arIBlock['IBLOCK_ID'];
            $arIBlock['PRODUCT_IBLOCK_ID'] = (int) $arIBlock['PRODUCT_IBLOCK_ID'];
            if ($arIBlock['IBLOCK_ID'] > 0) {
                $arIBlockList[$arIBlock['IBLOCK_ID']] = true;
            }
            if ($arIBlock['PRODUCT_IBLOCK_ID'] > 0) {
                $arIBlockList[$arIBlock['PRODUCT_IBLOCK_ID']] = true;
            }
        }
        unset($arIBlock, $iterator);
        if (!empty($arIBlockList)) {
            $arIBlockList = array_keys($arIBlockList);
            sort($arIBlockList);
            foreach ($arIBlockList as $intIBlockID) {
                $strName = CIBlock::GetArrayByID($intIBlockID, 'NAME');
                if (false !== $strName) {
                    $boolSep = true;
                    $rsProps = CIBlockProperty::GetList(['SORT' => 'ASC', 'NAME' => 'ASC'], ['IBLOCK_ID' => $intIBlockID]);
                    while ($arProp = $rsProps->Fetch()) {
                        if ('CML2_LINK' === $arProp['XML_ID'] || 'F' === $arProp['PROPERTY_TYPE']) {
                            continue;
                        }
                        if ('L' === $arProp['PROPERTY_TYPE']) {
                            $arProp['VALUES'] = [];
                        }

                        $strFieldType = '';
                        $arLogic = [];
                        $arValue = [];
                        $arPhpValue = '';

                        $boolUserType = false;
                        if (isset($arProp['USER_TYPE']) && !empty($arProp['USER_TYPE'])) {
                            switch ($arProp['USER_TYPE']) {
                                case 'DateTime':
                                    $strFieldType = 'datetime';
                                    $arLogic = static::GetLogic([BT_COND_LOGIC_EQ, BT_COND_LOGIC_NOT_EQ, BT_COND_LOGIC_GR, BT_COND_LOGIC_LS, BT_COND_LOGIC_EGR, BT_COND_LOGIC_ELS]);
                                    $arValue = [
                                        'type' => 'datetime',
                                        'format' => 'datetime',
                                    ];
                                    $boolUserType = true;

                                    break;

                                case 'Date':
                                    $strFieldType = 'date';
                                    $arLogic = static::GetLogic([BT_COND_LOGIC_EQ, BT_COND_LOGIC_NOT_EQ, BT_COND_LOGIC_GR, BT_COND_LOGIC_LS, BT_COND_LOGIC_EGR, BT_COND_LOGIC_ELS]);
                                    $arValue = [
                                        'type' => 'datetime',
                                        'format' => 'date',
                                    ];
                                    $boolUserType = true;

                                    break;

                                case 'directory':
                                    $strFieldType = 'text';
                                    $arLogic = static::GetLogic([BT_COND_LOGIC_EQ, BT_COND_LOGIC_NOT_EQ]);
                                    $arValue = [
                                        'type' => 'lazySelect',
                                        'load_url' => '/bitrix/tools/catalog/get_property_values.php',
                                        'load_params' => [
                                            'lang' => LANGUAGE_ID,
                                            'propertyId' => $arProp['ID'],
                                        ],
                                    ];
                                    $boolUserType = true;

                                    break;

                                default:
                                    $boolUserType = false;

                                    break;
                            }
                        }

                        if (!$boolUserType) {
                            switch ($arProp['PROPERTY_TYPE']) {
                                case 'N':
                                    $strFieldType = 'double';
                                    $arLogic = static::GetLogic([BT_COND_LOGIC_EQ, BT_COND_LOGIC_NOT_EQ, BT_COND_LOGIC_GR, BT_COND_LOGIC_LS, BT_COND_LOGIC_EGR, BT_COND_LOGIC_ELS]);
                                    $arValue = ['type' => 'input'];

                                    break;

                                case 'S':
                                    $strFieldType = 'text';
                                    $arLogic = static::GetLogic([BT_COND_LOGIC_EQ, BT_COND_LOGIC_NOT_EQ, BT_COND_LOGIC_CONT, BT_COND_LOGIC_NOT_CONT]);
                                    $arValue = ['type' => 'input'];

                                    break;

                                case 'L':
                                    $strFieldType = 'int';
                                    $arLogic = static::GetLogic([BT_COND_LOGIC_EQ, BT_COND_LOGIC_NOT_EQ]);
                                    $arValue = [
                                        'type' => 'lazySelect',
                                        'load_url' => '/bitrix/tools/catalog/get_property_values.php',
                                        'load_params' => [
                                            'lang' => LANGUAGE_ID,
                                            'propertyId' => $arProp['ID'],
                                        ],
                                    ];
                                    $arPhpValue = ['VALIDATE' => 'enumValue'];

                                    break;

                                case 'E':
                                    $strFieldType = 'int';
                                    $arLogic = static::GetLogic([BT_COND_LOGIC_EQ, BT_COND_LOGIC_NOT_EQ]);
                                    $arValue = [
                                        'type' => 'popup',
                                        'popup_url' => self::getAdminSection().'iblock_element_search.php',
                                        'popup_params' => [
                                            'lang' => LANGUAGE_ID,
                                            'IBLOCK_ID' => $arProp['LINK_IBLOCK_ID'],
                                            'discount' => 'Y',
                                        ],
                                        'param_id' => 'n',
                                    ];
                                    $arPhpValue = ['VALIDATE' => 'element'];

                                    break;

                                case 'G':
                                    $popupParams = [
                                        'lang' => LANGUAGE_ID,
                                        'IBLOCK_ID' => $arProp['LINK_IBLOCK_ID'],
                                        'discount' => 'Y',
                                        'simplename' => 'Y',
                                    ];
                                    if ($arProp['LINK_IBLOCK_ID'] > 0) {
                                        $popupParams['iblockfix'] = 'y';
                                    }
                                    $strFieldType = 'int';
                                    $arLogic = static::GetLogic([BT_COND_LOGIC_EQ, BT_COND_LOGIC_NOT_EQ]);
                                    $arValue = [
                                        'type' => 'popup',
                                        'popup_url' => self::getAdminSection().'iblock_section_search.php',
                                        'popup_params' => $popupParams,
                                        'param_id' => 'n',
                                    ];
                                    unset($popupParams);
                                    $arPhpValue = ['VALIDATE' => 'section'];

                                    break;
                            }
                        }
                        $arControlList['CondIBProp:'.$intIBlockID.':'.$arProp['ID']] = [
                            'ID' => 'CondIBProp:'.$intIBlockID.':'.$arProp['ID'],
                            'PARENT' => false,
                            'EXIST_HANDLER' => 'Y',
                            'MODULE_ID' => 'catalog',
                            'MODULE_ENTITY' => 'iblock',
                            'ENTITY' => 'ELEMENT_PROPERTY',
                            'ENTITY_ID' => $intIBlockID,
                            'IBLOCK_ID' => $intIBlockID, // deprecated
                            'PROPERTY_ID' => $arProp['ID'],
                            'FIELD' => 'PROPERTY_'.$arProp['ID'].'_VALUE',
                            'FIELD_TABLE' => $intIBlockID.':'.$arProp['ID'],
                            'FIELD_TYPE' => $strFieldType,
                            'MULTIPLE' => 'Y',
                            'GROUP' => 'N',
                            'SEP' => ($boolSep ? 'Y' : 'N'),
                            'SEP_LABEL' => (
                                $boolSep
                                ? str_replace(
                                    ['#ID#', '#NAME#'],
                                    [$intIBlockID, $strName],
                                    Loc::getMessage('BT_MOD_CATALOG_COND_CMP_IBLOCK_PROP_LABEL')
                                )
                                : ''
                            ),
                            'LABEL' => $arProp['NAME'],
                            'PREFIX' => str_replace(
                                ['#NAME#', '#IBLOCK_ID#', '#IBLOCK_NAME#'],
                                [$arProp['NAME'], $intIBlockID, $strName],
                                Loc::getMessage('BT_MOD_CATALOG_COND_CMP_IBLOCK_ONE_PROP_PREFIX')
                            ),
                            'LOGIC' => $arLogic,
                            'JS_VALUE' => $arValue,
                            'PHP_VALUE' => $arPhpValue,
                        ];

                        $boolSep = false;
                    }
                }
            }
            unset($intIBlockID);
        }
        unset($arIBlockList);

        return static::searchControl($arControlList, $strControlID);
    }

    public static function GetControlShow($arParams)
    {
        $arControls = static::GetControls();
        $arResult = [];
        $intCount = -1;
        foreach ($arControls as &$arOneControl) {
            if (isset($arOneControl['SEP']) && 'Y' === $arOneControl['SEP']) {
                ++$intCount;
                $arResult[$intCount] = [
                    'controlgroup' => true,
                    'group' => false,
                    'label' => $arOneControl['SEP_LABEL'],
                    'showIn' => static::GetShowIn($arParams['SHOW_IN_GROUPS']),
                    'children' => [],
                ];
            }
            $arLogic = static::GetLogicAtom($arOneControl['LOGIC']);
            $arValue = static::GetValueAtom($arOneControl['JS_VALUE']);

            $arResult[$intCount]['children'][] = [
                'controlId' => $arOneControl['ID'],
                'group' => false,
                'label' => $arOneControl['LABEL'],
                'showIn' => static::GetShowIn($arParams['SHOW_IN_GROUPS']),
                'control' => [
                    [
                        'id' => 'prefix',
                        'type' => 'prefix',
                        'text' => $arOneControl['PREFIX'],
                    ],
                    $arLogic,
                    $arValue,
                ],
            ];
        }
        if (isset($arOneControl)) {
            unset($arOneControl);
        }

        return $arResult;
    }

    public static function Generate($arOneCondition, $arParams, $arControl, $arSubs = false)
    {
        $strResult = '';

        if (is_string($arControl)) {
            $arControl = static::GetControls($arControl);
        }
        $boolError = !is_array($arControl);

        if (!$boolError) {
            $strResult = parent::Generate($arOneCondition, $arParams, $arControl, $arSubs);
            if (false === $strResult || '' === $strResult) {
                $boolError = true;
            } else {
                $strField = 'isset('.$arParams['FIELD'].'[\''.$arControl['FIELD'].'\'])';
                $strResult = $strField.' && '.$strResult;
            }
        }

        return !$boolError ? $strResult : false;
    }

    public static function ApplyValues($arOneCondition, $arControl)
    {
        $arResult = [];
        $arValues = false;

        $arLogicID = [
            BT_COND_LOGIC_EQ,
            BT_COND_LOGIC_EGR,
            BT_COND_LOGIC_ELS,
        ];

        if (is_string($arControl)) {
            $arControl = static::GetControls($arControl);
        }
        $boolError = !is_array($arControl);

        if (!$boolError) {
            $arValues = static::Check($arOneCondition, $arOneCondition, $arControl, false);
            if (false === $arValues) {
                $boolError = true;
            }
        }

        if (!$boolError) {
            $arLogic = static::SearchLogic($arValues['logic'], $arControl['LOGIC']);
            if (in_array($arLogic['ID'], $arLogicID, true)) {
                $arResult = [
                    'ID' => $arControl['ID'],
                    'FIELD' => $arControl['FIELD'],
                    'FIELD_TYPE' => $arControl['FIELD_TYPE'],
                    'VALUES' => (is_array($arValues['value']) ? $arValues['value'] : [$arValues['value']]),
                ];
            }
        }

        return !$boolError ? $arResult : false;
    }

    public static function Check($arOneCondition, $arParams, $arControl, $boolShow)
    {
        $result = parent::Check($arOneCondition, $arParams, $arControl, $boolShow);
        if (self::checkActiveProperty($arControl)) {
            return $result;
        }
        $boolShow = (true === $boolShow);
        if ($boolShow) {
            $result['err_cond'] = 'Y';
            if (isset($result['err_cond_mess'])) {
                $result['err_cond_mess'] .= '. '.Loc::getMessage('BT_MOD_CATALOG_COND_CMP_IBLOCK_PROPERTY_NOT_ACTIVE');
            } else {
                $result['err_cond_mess'] = Loc::getMessage('BT_MOD_CATALOG_COND_CMP_IBLOCK_PROPERTY_NOT_ACTIVE');
            }
        } else {
            $result = false;
        }

        return $result;
    }

    /**
     * @return string
     */
    private static function getAdminSection()
    {
        // TODO: need use \CAdminPage::getSelfFolderUrl, but in general it is impossible now
        return defined('SELF_FOLDER_URL') ? SELF_FOLDER_URL : '/bitrix/admin/';
    }

    /**
     * @return bool
     */
    private static function checkActiveProperty(array $control)
    {
        $iterator = Iblock\PropertyTable::getList([
            'select' => ['ID', 'IBLOCK_ID'],
            'filter' => [
                '=IBLOCK_ID' => $control['IBLOCK_ID'],
                '=ID' => $control['PROPERTY_ID'],
                '=ACTIVE' => 'Y',
            ],
        ]);
        $row = $iterator->fetch();
        $result = !empty($row);
        unset($row, $iterator);

        return $result;
    }
}

class CGlobalCondTree
{
    protected const PARAM_TITLE_MASK = '/^[A-Za-z_][A-Za-z01-9_]*$/';

    protected $intMode = BT_COND_MODE_DEFAULT;			// work mode
    protected $arEvents = [];						// events ID
    protected $arInitParams = [];					// start params
    protected $boolError = false;						// error flag
    protected $arMsg = [];							// messages (errors)

    protected $strFormName = '';						// form name
    protected $strFormID = '';							// form id
    protected $strContID = '';							// container id
    protected $strJSName = '';							// js object var name
    protected $boolCreateForm = false;					// need create form
    protected $boolCreateCont = false;					// need create container
    protected $strPrefix = 'rule';						// prefix for input
    protected $strSepID = '__';							// separator for id

    protected $arSystemMess = [];					// system messages

    protected $arAtomList;						// atom list cache
    protected $arAtomJSPath;						// atom js files
    protected $arControlList;					// control list cache
    protected $arShowControlList;				// control show method list
    protected $arShowInGroups;					// showin group list
    protected $forcedShowInGroup;				// forced showin list
    protected $arInitControlList;				// control init list

    protected $arDefaultControl = [
        'Parse',
        'GetConditionShow',
        'Generate',
        'ApplyValues',
    ];													// required control fields

    protected $usedModules = [];					// modules for real conditions
    protected $usedExtFiles = [];					// files from AddEventHandler
    protected $usedEntity = [];					// entity list in conditions

    protected $arConditions;						// conditions array

    public function __construct()
    {
        CJSCore::Init(['core_condtree']);
    }

    public function __destruct() {}

    protected function __ConvertKey($strKey)
    {
        if ('' !== $strKey) {
            $arKeys = explode($this->strSepID, $strKey);
            if (is_array($arKeys)) {
                foreach ($arKeys as &$intOneKey) {
                    $intOneKey = (int) $intOneKey;
                }
            }

            return $arKeys;
        }

        return false;
    }

    protected function __SetCondition(&$arResult, $arKeys, $intIndex, $arOneCondition)
    {
        if (0 === $intIndex) {
            if (1 === count($arKeys)) {
                $arResult = $arOneCondition;

                return true;
            }

            return $this->__SetCondition($arResult, $arKeys, $intIndex + 1, $arOneCondition);
        }

        if (!isset($arResult['CHILDREN'])) {
            $arResult['CHILDREN'] = [];
        }
        if (!isset($arResult['CHILDREN'][$arKeys[$intIndex]])) {
            $arResult['CHILDREN'][$arKeys[$intIndex]] = [];
        }
        if (($intIndex + 1) < count($arKeys)) {
            return $this->__SetCondition($arResult['CHILDREN'][$arKeys[$intIndex]], $arKeys, $intIndex + 1, $arOneCondition);
        }

        if (!empty($arResult['CHILDREN'][$arKeys[$intIndex]])) {
            return false;
        }

        $arResult['CHILDREN'][$arKeys[$intIndex]] = $arOneCondition;

        return true;
    }

    public function OnConditionAtomBuildList()
    {
        if ($this->boolError || isset($this->arAtomList)) {
            return;
        }

        $this->arAtomList = [];
        $this->arAtomJSPath = [];

        $result = [];
        if (isset($this->arEvents['INTERFACE_ATOMS'])) {
            $event = new Main\Event(
                $this->arEvents['INTERFACE_ATOMS']['MODULE_ID'],
                $this->arEvents['INTERFACE_ATOMS']['EVENT_ID']
            );
            $event->send();
            $resultList = $event->getResults();
            if (!empty($resultList)) {
                foreach ($resultList as $eventResult) {
                    if (Main\EventResult::SUCCESS !== $eventResult->getType()) {
                        continue;
                    }
                    $module = $eventResult->getModuleId();
                    if (empty($module)) {
                        continue;
                    }
                    $result[] = $eventResult->getParameters();
                }
                unset($eventResult);
            }
            unset($resultList, $event);
        }
        if (isset($this->arEvents['ATOMS'])) {
            foreach (GetModuleEvents($this->arEvents['ATOMS']['MODULE_ID'], $this->arEvents['ATOMS']['EVENT_ID'], true) as $arEvent) {
                $result[] = ExecuteModuleEventEx($arEvent);
            }
        }

        if (!empty($result)) {
            foreach ($result as $row) {
                if (empty($row) || !is_array($row)) {
                    continue;
                }
                if (empty($row['ID']) || isset($this->arAtomList[$row['ID']])) {
                    continue;
                }
                $this->arAtomList[$row['ID']] = $row;
                if (
                    !empty($row['JS_SRC'])
                    && is_string($row['JS_SRC'])
                    && !in_array($row['JS_SRC'], $this->arAtomJSPath, true)
                ) {
                    $this->arAtomJSPath[] = $row['JS_SRC'];
                }
            }
            unset($row);
        }
        unset($result);
    }

    public function OnConditionControlBuildList()
    {
        if ($this->boolError || isset($this->arControlList)) {
            return;
        }

        $this->arControlList = [];
        $this->arShowInGroups = [];
        $this->forcedShowInGroup = [];
        $this->arShowControlList = [];
        $this->arInitControlList = [];

        $result = [];

        if (isset($this->arEvents['CONTROLS'])) {
            foreach (GetModuleEvents($this->arEvents['CONTROLS']['MODULE_ID'], $this->arEvents['CONTROLS']['EVENT_ID'], true) as $arEvent) {
                $result[] = ExecuteModuleEventEx($arEvent);
            }
        }
        if (isset($this->arEvents['INTERFACE_CONTROLS'])) {
            $event = new Main\Event(
                $this->arEvents['INTERFACE_CONTROLS']['MODULE_ID'],
                $this->arEvents['INTERFACE_CONTROLS']['EVENT_ID']
            );
            $event->send();
            $resultList = $event->getResults();
            if (!empty($resultList)) {
                foreach ($resultList as $eventResult) {
                    if (Main\EventResult::SUCCESS !== $eventResult->getType()) {
                        continue;
                    }
                    $module = $eventResult->getModuleId();
                    if (empty($module)) {
                        continue;
                    }
                    $result[] = $eventResult->getParameters();
                }
                unset($eventResult);
            }
            unset($resultList, $event);
        }

        if (!empty($result)) {
            $rawControls = [];
            $controlIndex = 0;
            foreach ($result as $arRes) {
                if (empty($arRes) || !is_array($arRes)) {
                    continue;
                }
                if (isset($arRes['ID'])) {
                    if (isset($arRes['EXIST_HANDLER']) && 'Y' === $arRes['EXIST_HANDLER']) {
                        if (!isset($arRes['MODULE_ID']) && !isset($arRes['EXT_FILE'])) {
                            continue;
                        }
                    } else {
                        $arRes['MODULE_ID'] = '';
                        $arRes['EXT_FILE'] = '';
                    }
                    if (array_key_exists('EXIST_HANDLER', $arRes)) {
                        unset($arRes['EXIST_HANDLER']);
                    }
                    $arRes['GROUP'] = (isset($arRes['GROUP']) && 'Y' === $arRes['GROUP'] ? 'Y' : 'N');
                    if (isset($this->arControlList[$arRes['ID']])) {
                        $this->arMsg[] = ['id' => 'CONTROLS', 'text' => str_replace('#CONTROL#', $arRes['ID'], Loc::getMessage('BT_MOD_COND_ERR_CONTROL_DOUBLE'))];
                        $this->boolError = true;
                    } else {
                        if (!$this->CheckControl($arRes)) {
                            continue;
                        }
                        $this->arControlList[$arRes['ID']] = $arRes;
                        if ('Y' === $arRes['GROUP']) {
                            if (empty($arRes['FORCED_SHOW_LIST'])) {
                                $this->arShowInGroups[] = $arRes['ID'];
                            } else {
                                $forcedList = $arRes['FORCED_SHOW_LIST'];
                                if (!is_array($forcedList)) {
                                    $forcedList = [$forcedList];
                                }
                                foreach ($forcedList as $forcedId) {
                                    if (is_array($forcedId)) {
                                        continue;
                                    }
                                    $forcedId = trim($forcedId);
                                    if ('' === $forcedId) {
                                        continue;
                                    }
                                    if (!isset($this->forcedShowInGroup[$forcedId])) {
                                        $this->forcedShowInGroup[$forcedId] = [];
                                    }
                                    $this->forcedShowInGroup[$forcedId][] = $arRes['ID'];
                                }
                                unset($forcedId, $forcedList);
                            }
                        }
                        if (isset($arRes['GetControlShow']) && !empty($arRes['GetControlShow'])) {
                            if (!in_array($arRes['GetControlShow'], $this->arShowControlList, true)) {
                                $this->arShowControlList[] = $arRes['GetControlShow'];
                                $showDescription = [
                                    'CONTROL' => $arRes['GetControlShow'],
                                ];
                                if (isset($arRes['SORT']) && (int) $arRes['SORT'] > 0) {
                                    $showDescription['SORT'] = (int) $arRes['SORT'];
                                    $showDescription['INDEX'] = 1;
                                } else {
                                    $showDescription['SORT'] = INF;
                                    $showDescription['INDEX'] = $controlIndex;
                                    ++$controlIndex;
                                }
                                $rawControls[] = $showDescription;
                                unset($showDescription);
                            }
                        }
                        if (isset($arRes['InitParams']) && !empty($arRes['InitParams'])) {
                            if (!in_array($arRes['InitParams'], $this->arInitControlList, true)) {
                                $this->arInitControlList[] = $arRes['InitParams'];
                            }
                        }
                    }
                } elseif (isset($arRes['COMPLEX']) && 'Y' === $arRes['COMPLEX']) {
                    $complexModuleID = '';
                    $complexExtFiles = '';
                    if (isset($arRes['EXIST_HANDLER']) && 'Y' === $arRes['EXIST_HANDLER']) {
                        if (isset($arRes['MODULE_ID'])) {
                            $complexModuleID = $arRes['MODULE_ID'];
                        }
                        if (isset($arRes['EXT_FILE'])) {
                            $complexExtFiles = $arRes['EXT_FILE'];
                        }
                    }
                    if (isset($arRes['CONTROLS']) && !empty($arRes['CONTROLS']) && is_array($arRes['CONTROLS'])) {
                        if (array_key_exists('EXIST_HANDLER', $arRes)) {
                            unset($arRes['EXIST_HANDLER']);
                        }
                        $arInfo = $arRes;
                        unset($arInfo['COMPLEX'], $arInfo['CONTROLS']);
                        foreach ($arRes['CONTROLS'] as &$arOneControl) {
                            if (isset($arOneControl['ID'])) {
                                if (isset($arOneControl['EXIST_HANDLER']) && 'Y' === $arOneControl['EXIST_HANDLER']) {
                                    if (!isset($arOneControl['MODULE_ID']) && !isset($arOneControl['EXT_FILE'])) {
                                        continue;
                                    }
                                }
                                $arInfo['GROUP'] = 'N';
                                $arInfo['MODULE_ID'] = isset($arOneControl['MODULE_ID']) ? $arOneControl['MODULE_ID'] : $complexModuleID;
                                $arInfo['EXT_FILE'] = isset($arOneControl['EXT_FILE']) ? $arOneControl['EXT_FILE'] : $complexExtFiles;
                                $control = array_merge($arOneControl, $arInfo);
                                if (isset($this->arControlList[$control['ID']])) {
                                    $this->arMsg[] = ['id' => 'CONTROLS', 'text' => str_replace('#CONTROL#', $control['ID'], Loc::getMessage('BT_MOD_COND_ERR_CONTROL_DOUBLE'))];
                                    $this->boolError = true;
                                } else {
                                    if (!$this->CheckControl($control)) {
                                        continue;
                                    }
                                    $this->arControlList[$control['ID']] = $control;
                                }
                                unset($control);
                            }
                        }
                        if (isset($arOneControl)) {
                            unset($arOneControl);
                        }
                        if (isset($arRes['GetControlShow']) && !empty($arRes['GetControlShow'])) {
                            if (!in_array($arRes['GetControlShow'], $this->arShowControlList, true)) {
                                $this->arShowControlList[] = $arRes['GetControlShow'];
                                $showDescription = [
                                    'CONTROL' => $arRes['GetControlShow'],
                                ];
                                if (isset($arRes['SORT']) && (int) $arRes['SORT'] > 0) {
                                    $showDescription['SORT'] = (int) $arRes['SORT'];
                                    $showDescription['INDEX'] = 1;
                                } else {
                                    $showDescription['SORT'] = INF;
                                    $showDescription['INDEX'] = $controlIndex;
                                    ++$controlIndex;
                                }
                                $rawControls[] = $showDescription;
                                unset($showDescription);
                            }
                        }
                        if (isset($arRes['InitParams']) && !empty($arRes['InitParams'])) {
                            if (!in_array($arRes['InitParams'], $this->arInitControlList, true)) {
                                $this->arInitControlList[] = $arRes['InitParams'];
                            }
                        }
                    }
                } else {
                    foreach ($arRes as &$arOneRes) {
                        if (is_array($arOneRes) && isset($arOneRes['ID'])) {
                            if (isset($arOneRes['EXIST_HANDLER']) && 'Y' === $arOneRes['EXIST_HANDLER']) {
                                if (!isset($arOneRes['MODULE_ID']) && !isset($arOneRes['EXT_FILE'])) {
                                    continue;
                                }
                            } else {
                                $arOneRes['MODULE_ID'] = '';
                                $arOneRes['EXT_FILE'] = '';
                            }
                            if (array_key_exists('EXIST_HANDLER', $arOneRes)) {
                                unset($arOneRes['EXIST_HANDLER']);
                            }
                            $arOneRes['GROUP'] = (isset($arOneRes['GROUP']) && 'Y' === $arOneRes['GROUP'] ? 'Y' : 'N');
                            if (isset($this->arControlList[$arOneRes['ID']])) {
                                $this->arMsg[] = ['id' => 'CONTROLS', 'text' => str_replace('#CONTROL#', $arOneRes['ID'], Loc::getMessage('BT_MOD_COND_ERR_CONTROL_DOUBLE'))];
                                $this->boolError = true;
                            } else {
                                if (!$this->CheckControl($arOneRes)) {
                                    continue;
                                }
                                $this->arControlList[$arOneRes['ID']] = $arOneRes;
                                if ('Y' === $arOneRes['GROUP']) {
                                    if (empty($arOneRes['FORCED_SHOW_LIST'])) {
                                        $this->arShowInGroups[] = $arOneRes['ID'];
                                    } else {
                                        $forcedList = (!is_array($arOneRes['FORCED_SHOW_LIST']) ? [$arOneRes['FORCED_SHOW_LIST']] : $arOneRes['FORCED_SHOW_LIST']);
                                        foreach ($forcedList as &$forcedId) {
                                            if (is_array($forcedId)) {
                                                continue;
                                            }
                                            $forcedId = trim($forcedId);
                                            if ('' === $forcedId) {
                                                continue;
                                            }
                                            if (!isset($this->forcedShowInGroup[$forcedId])) {
                                                $this->forcedShowInGroup[$forcedId] = [];
                                            }
                                            $this->forcedShowInGroup[$forcedId][] = $arOneRes['ID'];
                                        }
                                        unset($forcedId);
                                    }
                                }
                                if (isset($arOneRes['GetControlShow']) && !empty($arOneRes['GetControlShow'])) {
                                    if (!in_array($arOneRes['GetControlShow'], $this->arShowControlList, true)) {
                                        $this->arShowControlList[] = $arOneRes['GetControlShow'];
                                        $showDescription = [
                                            'CONTROL' => $arOneRes['GetControlShow'],
                                        ];
                                        if (isset($arOneRes['SORT']) && (int) $arOneRes['SORT'] > 0) {
                                            $showDescription['SORT'] = (int) $arOneRes['SORT'];
                                            $showDescription['INDEX'] = 1;
                                        } else {
                                            $showDescription['SORT'] = INF;
                                            $showDescription['INDEX'] = $controlIndex;
                                            ++$controlIndex;
                                        }
                                        $rawControls[] = $showDescription;
                                        unset($showDescription);
                                    }
                                }
                                if (isset($arOneRes['InitParams']) && !empty($arOneRes['InitParams'])) {
                                    if (!in_array($arOneRes['InitParams'], $this->arInitControlList, true)) {
                                        $this->arInitControlList[] = $arOneRes['InitParams'];
                                    }
                                }
                            }
                        }
                    }
                    unset($arOneRes);
                }
            }
            unset($arRes);

            if (!empty($rawControls)) {
                $this->arShowControlList = [];
                Main\Type\Collection::sortByColumn($rawControls, ['SORT' => SORT_ASC, 'INDEX' => SORT_ASC]);
                foreach ($rawControls as $row) {
                    $this->arShowControlList[] = $row['CONTROL'];
                }
                unset($row);
            }
            unset($controlIndex, $rawControls);
        }
        if (empty($this->arControlList)) {
            $this->arMsg[] = ['id' => 'CONTROLS', 'text' => Loc::getMessage('BT_MOD_COND_ERR_CONTROLS_EMPTY')];
            $this->boolError = true;
        }
    }

    public function Init($intMode, $mxEvent, $arParams = [])
    {
        global $APPLICATION;
        $this->arMsg = [];

        $intMode = (int) $intMode;
        if (!in_array($intMode, $this->GetModeList(), true)) {
            $intMode = BT_COND_MODE_DEFAULT;
        }
        $this->intMode = $intMode;

        $arEvent = false;
        if (is_array($mxEvent)) {
            $fields = [
                'INTERFACE_ATOMS', 'INTERFACE_CONTROLS',
                'ATOMS', 'CONTROLS',
            ];
            foreach ($fields as $fieldName) {
                if (!isset($mxEvent[$fieldName]) || !$this->CheckEvent($mxEvent[$fieldName])) {
                    continue;
                }
                $arEvent[$fieldName] = $mxEvent[$fieldName];
            }
            unset($fieldName);
            if (!isset($arEvent['INTERFACE_CONTROLS']) && !isset($arEvent['CONTROLS'])) {
                $arEvent = false;
            }
        } else {
            $mxEvent = (int) $mxEvent;
            if ($mxEvent >= 0) {
                $arEvent = $this->GetEventList($mxEvent);
            }
        }

        if (false === $arEvent) {
            $this->boolError = true;
            $this->arMsg[] = ['id' => 'EVENT', 'text' => Loc::getMessage('BT_MOD_COND_ERR_EVENT_BAD')];
        } else {
            $this->arEvents = $arEvent;
        }

        $this->arInitParams = $arParams;

        if (!is_array($arParams)) {
            $arParams = [];
        }

        $parsedValues = [];
        if (BT_COND_MODE_DEFAULT === $this->intMode) {
            if (!empty($arParams) && is_array($arParams)) {
                if (
                    isset($arParams['FORM_NAME'])
                    && is_string($arParams['FORM_NAME'])
                    && preg_match(self::PARAM_TITLE_MASK, $arParams['FORM_NAME'], $parsedValues)
                ) {
                    $this->strFormName = $arParams['FORM_NAME'];
                }
                if (
                    isset($arParams['FORM_ID'])
                    && is_string($arParams['FORM_ID'])
                    && preg_match(self::PARAM_TITLE_MASK, $arParams['FORM_ID'], $parsedValues)
                ) {
                    $this->strFormID = $arParams['FORM_ID'];
                }
                if (
                    isset($arParams['CONT_ID'])
                    && is_string($arParams['CONT_ID'])
                    && preg_match(self::PARAM_TITLE_MASK, $arParams['CONT_ID'], $parsedValues)
                ) {
                    $this->strContID = $arParams['CONT_ID'];
                }
                if (
                    isset($arParams['JS_NAME'])
                    && is_string($arParams['JS_NAME'])
                    && preg_match(self::PARAM_TITLE_MASK, $arParams['JS_NAME'], $parsedValues)
                ) {
                    $this->strJSName = $arParams['JS_NAME'];
                }

                $this->boolCreateForm = (isset($arParams['CREATE_FORM']) && 'Y' === $arParams['CREATE_FORM']);
                $this->boolCreateCont = (isset($arParams['CREATE_CONT']) && 'Y' === $arParams['CREATE_CONT']);
            }

            if (empty($this->strJSName)) {
                if (empty($this->strContID)) {
                    $this->boolError = true;
                    $this->arMsg[] = ['id' => 'JS_NAME', 'text' => Loc::getMessage('BT_MOD_COND_ERR_JS_NAME_BAD')];
                } else {
                    $this->strJSName = md5($this->strContID);
                }
            }
        }
        if (BT_COND_MODE_DEFAULT === $this->intMode || BT_COND_MODE_PARSE === $this->intMode) {
            if (!empty($arParams) && is_array($arParams)) {
                if (
                    isset($arParams['PREFIX'])
                    && is_string($arParams['PREFIX'])
                    && preg_match(self::PARAM_TITLE_MASK, $arParams['PREFIX'], $parsedValues)
                ) {
                    $this->strPrefix = $arParams['PREFIX'];
                }
                if (
                    isset($arParams['SEP_ID'])
                    && is_string($arParams['SEP_ID'])
                    && preg_match(self::PARAM_TITLE_MASK, $arParams['SEP_ID'], $parsedValues)
                ) {
                    $this->strSepID = $arParams['SEP_ID'];
                }
            }
        }

        $this->OnConditionAtomBuildList();
        $this->OnConditionControlBuildList();

        if (!$this->boolError) {
            if (!empty($this->arInitControlList) && is_array($this->arInitControlList)) {
                if (!empty($arParams) && is_array($arParams)) {
                    if (isset($arParams['INIT_CONTROLS']) && !empty($arParams['INIT_CONTROLS']) && is_array($arParams['INIT_CONTROLS'])) {
                        foreach ($this->arInitControlList as &$arOneControl) {
                            call_user_func_array(
                                $arOneControl,
                                [
                                    $arParams['INIT_CONTROLS'],
                                ]
                            );
                        }
                        if (isset($arOneControl)) {
                            unset($arOneControl);
                        }
                    }
                }
            }
        }

        if (isset($arParams['SYSTEM_MESSAGES']) && !empty($arParams['SYSTEM_MESSAGES']) && is_array($arParams['SYSTEM_MESSAGES'])) {
            $this->arSystemMess = $arParams['SYSTEM_MESSAGES'];
        }

        if ($this->boolError) {
            $obError = new CAdminException($this->arMsg);
            $APPLICATION->ThrowException($obError);
        }

        return !$this->boolError;
    }

    public function Show($arConditions)
    {
        $this->arMsg = [];

        if (!$this->boolError) {
            if (!empty($arConditions)) {
                if (!is_array($arConditions)) {
                    if (!CheckSerializedData($arConditions)) {
                        $this->boolError = true;
                        $this->arMsg[] = ['id' => 'CONDITIONS', 'text' => Loc::getMessage('BT_MOD_COND_ERR_SHOW_DATA_UNSERIALIZE')];
                    } else {
                        $arConditions = unserialize($arConditions, ['allowed_classes' => false]);
                        if (!is_array($arConditions)) {
                            $this->boolError = true;
                            $this->arMsg[] = ['id' => 'CONDITIONS', 'text' => Loc::getMessage('BT_MOD_COND_ERR_SHOW_DATA_UNSERIALIZE')];
                        }
                    }
                }
            }
        }

        if (!$this->boolError) {
            $this->arConditions = (!empty($arConditions) ? $arConditions : $this->GetDefaultConditions());

            $strResult = '';

            $this->ShowScripts();

            if ($this->boolCreateForm) {
            }
            if ($this->boolCreateCont) {
            }

            $strResult .= '<script type="text/javascript">'."\n";
            $strResult .= 'var '.$this->strJSName.' = new BX.TreeConditions('."\n";
            $strResult .= $this->ShowParams().",\n";
            $strResult .= $this->ShowConditions().",\n";
            $strResult .= $this->ShowControls()."\n";

            $strResult .= ');'."\n";
            $strResult .= '</script>'."\n";

            if ($this->boolCreateCont) {
            }
            if ($this->boolCreateForm) {
            }

            echo $strResult;
        }
    }

    public function GetDefaultConditions()
    {
        return [
            'CLASS_ID' => 'CondGroup',
            'DATA' => ['All' => 'AND', 'True' => 'True'],
            'CHILDREN' => [],
        ];
    }

    public function Parse($arData = '', $arParams = false)
    {
        global $APPLICATION;
        $this->arMsg = [];

        $this->usedModules = [];
        $this->usedExtFiles = [];

        $arResult = [];
        if (!$this->boolError) {
            if (empty($arData) || !is_array($arData)) {
                if (isset($_POST[$this->strPrefix]) && !empty($_POST[$this->strPrefix]) && is_array($_POST[$this->strPrefix])) {
                    $arData = $_POST[$this->strPrefix];
                } else {
                    $this->boolError = true;
                    $this->arMsg[] = ['id' => 'CONDITIONS', 'text' => Loc::getMessage('BT_MOD_COND_ERR_PARSE_DATA_EMPTY')];
                }
            }
        }

        if (!$this->boolError) {
            foreach ($arData as $strKey => $value) {
                $arKeys = $this->__ConvertKey($strKey);
                if (empty($arKeys)) {
                    $this->boolError = true;
                    $this->arMsg[] = ['id' => 'CONDITIONS', 'text' => Loc::getMessage('BT_MOD_COND_ERR_PARSE_DATA_BAD_KEY')];

                    break;
                }

                if (!isset($value['controlId']) || empty($value['controlId'])) {
                    $this->boolError = true;
                    $this->arMsg[] = ['id' => 'CONDITIONS', 'text' => Loc::getMessage('BT_MOD_COND_ERR_PARSE_DATA_EMPTY_CONTROLID')];

                    break;
                }

                if (!isset($this->arControlList[$value['controlId']])) {
                    $this->boolError = true;
                    $this->arMsg[] = ['id' => 'CONDITIONS', 'text' => Loc::getMessage('BT_MOD_COND_ERR_PARSE_DATA_BAD_CONTROLID')];

                    break;
                }

                $arOneCondition = call_user_func_array(
                    $this->arControlList[$value['controlId']]['Parse'],
                    [
                        $value,
                    ]
                );
                if (false === $arOneCondition) {
                    $this->boolError = true;
                    $this->arMsg[] = ['id' => 'CONDITIONS', 'text' => Loc::getMessage('BT_MOD_COND_ERR_PARSE_DATA_CONTROL_BAD_VALUE')];

                    break;
                }

                $arItem = [
                    'CLASS_ID' => $value['controlId'],
                    'DATA' => $arOneCondition,
                ];
                if ('Y' === $this->arControlList[$value['controlId']]['GROUP']) {
                    $arItem['CHILDREN'] = [];
                }
                if (!$this->__SetCondition($arResult, $arKeys, 0, $arItem)) {
                    $this->boolError = true;
                    $this->arMsg[] = ['id' => 'CONDITIONS', 'text' => Loc::getMessage('BT_MOD_COND_ERR_PARSE_DATA_DOUBLE_KEY')];

                    break;
                }
            }
        }

        if ($this->boolError) {
            $obError = new CAdminException($this->arMsg);
            $APPLICATION->ThrowException($obError);
        }

        return !$this->boolError ? $arResult : '';
    }

    public function ShowScripts()
    {
        if (!$this->boolError) {
            $this->ShowAtoms();
        }
    }

    public function ShowAtoms()
    {
        if (!$this->boolError) {
            if (!isset($this->arAtomList)) {
                $this->OnConditionAtomBuildList();
            }
            if (!empty($this->arAtomJSPath) && is_array($this->arAtomJSPath)) {
                $asset = Main\Page\Asset::getInstance();
                foreach ($this->arAtomJSPath as $jsPath) {
                    $asset->addJs($jsPath);
                }
                unset($jsPath, $asset);
            }
        }
    }

    public function ShowParams()
    {
        if (!$this->boolError) {
            $arParams = [
                'parentContainer' => $this->strContID,
                'form' => $this->strFormID,
                'formName' => $this->strFormName,
                'sepID' => $this->strSepID,
                'prefix' => $this->strPrefix,
            ];

            if (!empty($this->arSystemMess)) {
                $arParams['messTree'] = $this->arSystemMess;
            }

            return CUtil::PhpToJSObject($arParams);
        }

        return '';
    }

    public function ShowControls()
    {
        if ($this->boolError) {
            return '';
        }

        $result = [];
        if (!empty($this->arShowControlList)) {
            foreach ($this->arShowControlList as &$arOneControl) {
                $arShowControl = call_user_func_array($arOneControl, [
                    ['SHOW_IN_GROUPS' => $this->arShowInGroups],
                ]);
                if (!empty($arShowControl) && is_array($arShowControl)) {
                    $this->fillForcedShow($arShowControl);
                    if (isset($arShowControl['controlId']) || isset($arShowControl['controlgroup'])) {
                        $result[] = $arShowControl;
                    } else {
                        foreach ($arShowControl as &$oneControl) {
                            $result[] = $oneControl;
                        }
                        unset($oneControl);
                    }
                }
            }
            unset($arOneControl);
        }

        return CUtil::PhpToJSObject($result);
    }

    public function ShowLevel(&$arLevel, $boolFirst = false)
    {
        $boolFirst = (true === $boolFirst);
        $arResult = [];
        if (empty($arLevel) || !is_array($arLevel)) {
            return $arResult;
        }
        $intCount = 0;
        if ($boolFirst) {
            if (isset($arLevel['CLASS_ID']) && !empty($arLevel['CLASS_ID'])) {
                if (isset($this->arControlList[$arLevel['CLASS_ID']])) {
                    $arOneControl = $this->arControlList[$arLevel['CLASS_ID']];
                    $arParams = [
                        'COND_NUM' => $intCount,
                        'DATA' => $arLevel['DATA'],
                        'ID' => $arOneControl['ID'],
                    ];
                    $arOneResult = call_user_func_array(
                        $arOneControl['GetConditionShow'],
                        [
                            $arParams,
                        ]
                    );
                    if ('Y' === $arOneControl['GROUP']) {
                        $arOneResult['children'] = [];
                        if (isset($arLevel['CHILDREN'])) {
                            $arOneResult['children'] = $this->ShowLevel($arLevel['CHILDREN'], false);
                        }
                    }
                    $arResult[] = $arOneResult;
                }
            }
        } else {
            foreach ($arLevel as &$arOneCondition) {
                if (isset($arOneCondition['CLASS_ID']) && !empty($arOneCondition['CLASS_ID'])) {
                    if (isset($this->arControlList[$arOneCondition['CLASS_ID']])) {
                        $arOneControl = $this->arControlList[$arOneCondition['CLASS_ID']];
                        $arParams = [
                            'COND_NUM' => $intCount,
                            'DATA' => $arOneCondition['DATA'],
                            'ID' => $arOneControl['ID'],
                        ];
                        $arOneResult = call_user_func_array(
                            $arOneControl['GetConditionShow'],
                            [
                                $arParams,
                            ]
                        );

                        if ('Y' === $arOneControl['GROUP'] && isset($arOneCondition['CHILDREN'])) {
                            $arOneResult['children'] = $this->ShowLevel($arOneCondition['CHILDREN'], false);
                        }
                        $arResult[] = $arOneResult;
                        ++$intCount;
                    }
                }
            }
            if (isset($arOneCondition)) {
                unset($arOneCondition);
            }
        }

        return $arResult;
    }

    public function ShowConditions()
    {
        if (!$this->boolError) {
            if (empty($this->arConditions)) {
                $this->arConditions = $this->GetDefaultConditions();
            }

            $arResult = $this->ShowLevel($this->arConditions, true);

            return CUtil::PhpToJSObject(current($arResult));
        }

        return '';
    }

    public function Generate($arConditions, $arParams)
    {
        $this->usedModules = [];
        $this->usedExtFiles = [];
        $this->usedEntity = [];

        $strResult = '';
        if (!$this->boolError) {
            if (!empty($arConditions) && is_array($arConditions)) {
                $arResult = $this->GenerateLevel($arConditions, $arParams, true);
                if (empty($arResult)) {
                    $strResult = '';
                    $this->boolError = true;
                } else {
                    $strResult = current($arResult);
                }
            } else {
                $this->boolError = true;
            }
        }

        return $strResult;
    }

    public function GenerateLevel(&$arLevel, $arParams, $boolFirst = false)
    {
        $arResult = [];
        $boolFirst = (true === $boolFirst);
        if (empty($arLevel) || !is_array($arLevel)) {
            return $arResult;
        }
        if ($boolFirst) {
            if (isset($arLevel['CLASS_ID']) && !empty($arLevel['CLASS_ID'])) {
                if (isset($this->arControlList[$arLevel['CLASS_ID']])) {
                    $arOneControl = $this->arControlList[$arLevel['CLASS_ID']];
                    if ('Y' === $arOneControl['GROUP']) {
                        $arSubEval = $this->GenerateLevel($arLevel['CHILDREN'], $arParams);
                        if (false === $arSubEval || !is_array($arSubEval)) {
                            return false;
                        }
                        $strEval = call_user_func_array(
                            $arOneControl['Generate'],
                            [$arLevel['DATA'], $arParams, $arLevel['CLASS_ID'], $arSubEval]
                        );
                    } else {
                        $strEval = call_user_func_array(
                            $arOneControl['Generate'],
                            [$arLevel['DATA'], $arParams, $arLevel['CLASS_ID']]
                        );
                    }
                    if (false === $strEval || !is_string($strEval) || 'false' === $strEval) {
                        return false;
                    }
                    $arResult[] = '('.$strEval.')';
                    $this->fillUsedData($arOneControl);
                }
            }
        } else {
            foreach ($arLevel as &$arOneCondition) {
                if (isset($arOneCondition['CLASS_ID']) && !empty($arOneCondition['CLASS_ID'])) {
                    if (isset($this->arControlList[$arOneCondition['CLASS_ID']])) {
                        $arOneControl = $this->arControlList[$arOneCondition['CLASS_ID']];
                        if ('Y' === $arOneControl['GROUP']) {
                            $arSubEval = $this->GenerateLevel($arOneCondition['CHILDREN'], $arParams);
                            if (false === $arSubEval || !is_array($arSubEval)) {
                                return false;
                            }
                            $strEval = call_user_func_array(
                                $arOneControl['Generate'],
                                [$arOneCondition['DATA'], $arParams, $arOneCondition['CLASS_ID'], $arSubEval]
                            );
                        } else {
                            $strEval = call_user_func_array(
                                $arOneControl['Generate'],
                                [$arOneCondition['DATA'], $arParams, $arOneCondition['CLASS_ID']]
                            );
                        }

                        if (false === $strEval || !is_string($strEval) || 'false' === $strEval) {
                            return false;
                        }
                        $arResult[] = '('.$strEval.')';
                        $this->fillUsedData($arOneControl);
                    }
                }
            }
            if (isset($arOneCondition)) {
                unset($arOneCondition);
            }
        }

        if (!empty($arResult)) {
            foreach ($arResult as $key => $value) {
                if ('' === $value || '()' === $value) {
                    unset($arResult[$key]);
                }
            }
        }
        if (!empty($arResult)) {
            $arResult = array_values($arResult);
        }

        return $arResult;
    }

    public function GetConditionValues($arConditions)
    {
        $arResult = false;
        if (!$this->boolError) {
            if (!empty($arConditions) && is_array($arConditions)) {
                $arValues = [];
                $this->GetConditionValuesLevel($arConditions, $arValues, true);
                $arResult = $arValues;
            }
        }

        return $arResult;
    }

    public function GetConditionValuesLevel(&$arLevel, &$arResult, $boolFirst = false)
    {
        $boolFirst = (true === $boolFirst);
        if (is_array($arLevel) && !empty($arLevel)) {
            if ($boolFirst) {
                if (isset($arLevel['CLASS_ID']) && !empty($arLevel['CLASS_ID'])) {
                    if (isset($this->arControlList[$arLevel['CLASS_ID']])) {
                        $arOneControl = $this->arControlList[$arLevel['CLASS_ID']];
                        if ('Y' === $arOneControl['GROUP']) {
                            if (call_user_func_array(
                                $arOneControl['ApplyValues'],
                                [$arLevel['DATA'], $arLevel['CLASS_ID']]
                            )) {
                                $this->GetConditionValuesLevel($arLevel['CHILDREN'], $arResult, false);
                            }
                        } else {
                            $arCondInfo = call_user_func_array(
                                $arOneControl['ApplyValues'],
                                [$arLevel['DATA'], $arLevel['CLASS_ID']]
                            );
                            if (!empty($arCondInfo) && is_array($arCondInfo)) {
                                if (!isset($arResult[$arLevel['CLASS_ID']]) || empty($arResult[$arLevel['CLASS_ID']]) || !is_array($arResult[$arLevel['CLASS_ID']])) {
                                    $arResult[$arLevel['CLASS_ID']] = $arCondInfo;
                                } else {
                                    $arResult[$arLevel['CLASS_ID']]['VALUES'] = array_merge($arResult[$arLevel['CLASS_ID']]['VALUES'], $arCondInfo['VALUES']);
                                }
                            }
                        }
                    }
                }
            } else {
                foreach ($arLevel as &$arOneCondition) {
                    if (isset($arOneCondition['CLASS_ID']) && !empty($arOneCondition['CLASS_ID'])) {
                        if (isset($this->arControlList[$arOneCondition['CLASS_ID']])) {
                            $arOneControl = $this->arControlList[$arOneCondition['CLASS_ID']];
                            if ('Y' === $arOneControl['GROUP']) {
                                if (call_user_func_array(
                                    $arOneControl['ApplyValues'],
                                    [$arOneCondition['DATA'], $arOneCondition['CLASS_ID']]
                                )) {
                                    $this->GetConditionValuesLevel($arOneCondition['CHILDREN'], $arResult, false);
                                }
                            } else {
                                $arCondInfo = call_user_func_array(
                                    $arOneControl['ApplyValues'],
                                    [$arOneCondition['DATA'], $arOneCondition['CLASS_ID']]
                                );
                                if (!empty($arCondInfo) && is_array($arCondInfo)) {
                                    if (!isset($arResult[$arOneCondition['CLASS_ID']]) || empty($arResult[$arOneCondition['CLASS_ID']]) || !is_array($arResult[$arOneCondition['CLASS_ID']])) {
                                        $arResult[$arOneCondition['CLASS_ID']] = $arCondInfo;
                                    } else {
                                        $arResult[$arOneCondition['CLASS_ID']]['VALUES'] = array_merge($arResult[$arOneCondition['CLASS_ID']]['VALUES'], $arCondInfo['VALUES']);
                                    }
                                }
                            }
                        }
                    }
                }
                if (isset($arOneCondition)) {
                    unset($arOneCondition);
                }
            }
        }
    }

    public function GetConditionHandlers()
    {
        return [
            'MODULES' => (!empty($this->usedModules) ? array_keys($this->usedModules) : []),
            'EXT_FILES' => (!empty($this->usedExtFiles) ? array_keys($this->usedExtFiles) : []),
        ];
    }

    public function GetUsedEntityList()
    {
        return $this->usedEntity;
    }

    protected function CheckControl($arControl)
    {
        $boolResult = true;
        foreach ($this->arDefaultControl as &$strKey) {
            if (!isset($arControl[$strKey]) || empty($arControl[$strKey])) {
                $boolResult = false;

                break;
            }
        }
        unset($strKey);

        return $boolResult;
    }

    protected function GetModeList()
    {
        return [
            BT_COND_MODE_DEFAULT,
            BT_COND_MODE_PARSE,
            BT_COND_MODE_GENERATE,
            BT_COND_MODE_SQL,
            BT_COND_MODE_SEARCH,
        ];
    }

    protected function GetEventList($intEventID)
    {
        $arEventList = [
            BT_COND_BUILD_CATALOG => [
                'INTERFACE_ATOMS' => [
                    'MODULE_ID' => 'catalog',
                    'EVENT_ID' => 'onBuildDiscountInterfaceAtoms',
                ],
                'INTERFACE_CONTROLS' => [
                    'MODULE_ID' => 'catalog',
                    'EVENT_ID' => 'onBuildDiscountInterfaceControls',
                ],
                'ATOMS' => [
                    'MODULE_ID' => 'catalog',
                    'EVENT_ID' => 'OnCondCatAtomBuildList',
                ],
                'CONTROLS' => [
                    'MODULE_ID' => 'catalog',
                    'EVENT_ID' => 'OnCondCatControlBuildList',
                ],
            ],
            BT_COND_BUILD_SALE => [
                'INTERFACE_ATOMS' => [
                    'MODULE_ID' => 'sale',
                    'EVENT_ID' => 'onBuildDiscountConditionInterfaceAtoms',
                ],
                'INTERFACE_CONTROLS' => [
                    'MODULE_ID' => 'sale',
                    'EVENT_ID' => 'onBuildDiscountConditionInterfaceControls',
                ],
                'ATOMS' => [
                    'MODULE_ID' => 'sale',
                    'EVENT_ID' => 'OnCondSaleAtomBuildList',
                ],
                'CONTROLS' => [
                    'MODULE_ID' => 'sale',
                    'EVENT_ID' => 'OnCondSaleControlBuildList',
                ],
            ],
            BT_COND_BUILD_SALE_ACTIONS => [
                'INTERFACE_ATOMS' => [
                    'MODULE_ID' => 'sale',
                    'EVENT_ID' => 'onBuildDiscountActionInterfaceAtoms',
                ],
                'INTERFACE_CONTROLS' => [
                    'MODULE_ID' => 'sale',
                    'EVENT_ID' => 'onBuildDiscountActionInterfaceControls',
                ],
                'ATOMS' => [
                    'MODULE_ID' => 'sale',
                    'EVENT_ID' => 'OnCondSaleActionsAtomBuildList',
                ],
                'CONTROLS' => [
                    'MODULE_ID' => 'sale',
                    'EVENT_ID' => 'OnCondSaleActionsControlBuildList',
                ],
            ],
        ];

        return isset($arEventList[$intEventID]) ? $arEventList[$intEventID] : false;
    }

    protected function CheckEvent($arEvent)
    {
        if (!is_array($arEvent)) {
            return false;
        }
        if (!isset($arEvent['MODULE_ID']) || empty($arEvent['MODULE_ID']) || !is_string($arEvent['MODULE_ID'])) {
            return false;
        }
        if (!isset($arEvent['EVENT_ID']) || empty($arEvent['EVENT_ID']) || !is_string($arEvent['EVENT_ID'])) {
            return false;
        }

        return true;
    }

    protected function fillUsedData(&$control)
    {
        if (!empty($control['MODULE_ID'])) {
            if (is_array($control['MODULE_ID'])) {
                foreach ($control['MODULE_ID'] as &$oneModuleID) {
                    if ($oneModuleID !== $this->arEvents['CONTROLS']['MODULE_ID']) {
                        $this->usedModules[$oneModuleID] = true;
                    }
                }
                unset($oneModuleID);
            } else {
                if ($control['MODULE_ID'] !== $this->arEvents['CONTROLS']['MODULE_ID']) {
                    $this->usedModules[$control['MODULE_ID']] = true;
                }
            }
        }
        if (!empty($control['EXT_FILE'])) {
            if (is_array($control['EXT_FILE'])) {
                foreach ($control['EXT_FILE'] as &$oneExtFile) {
                    $this->usedExtFiles[$oneExtFile] = true;
                }
                unset($oneExtFile);
            } else {
                $this->usedExtFiles[$control['EXT_FILE']] = true;
            }
        }

        if (!empty($control['ENTITY'])) {
            $entityID = $control['ENTITY'].'|';
            $entityID .= (is_array($control['FIELD']) ? implode('-', $control['FIELD']) : $control['FIELD']);
            if (!isset($this->usedEntity[$entityID])) {
                $row = [
                    'MODULE' => (!empty($control['MODULE_ID']) ? $control['MODULE_ID'] : $control['MODULE_ENTITY']),
                    'ENTITY' => $control['ENTITY'],
                    'FIELD_ENTITY' => $control['FIELD'],
                    'FIELD_TABLE' => (!empty($control['FIELD_TABLE']) ? $control['FIELD_TABLE'] : $control['FIELD']),
                ];
                if (isset($control['ENTITY_ID'])) {
                    $row['ENTITY_ID'] = $control['ENTITY_ID'];
                }
                if (isset($control['ENTITY_VALUE']) || isset($control['ENTITY_ID'])) {
                    $row['ENTITY_VALUE'] = (
                        isset($control['ENTITY_VALUE'])
                        ? $control['ENTITY_VALUE']
                        : $control['ENTITY_ID']
                    );
                }
                $this->usedEntity[$entityID] = $row;
                unset($row);
            }
            unset($entityID);
        }
    }

    protected function fillForcedShow(&$showControl)
    {
        if (empty($this->forcedShowInGroup)) {
            return;
        }
        if (isset($showControl['controlId']) || isset($showControl['controlgroup'])) {
            if (!isset($showControl['controlgroup'])) {
                if (isset($this->forcedShowInGroup[$showControl['controlId']])) {
                    $showControl['showIn'] = array_values(array_unique(array_merge(
                        $showControl['showIn'],
                        $this->forcedShowInGroup[$showControl['controlId']]
                    )));
                }
            } else {
                $forcedGroup = [];
                foreach ($showControl['children'] as &$oneControl) {
                    if (isset($oneControl['controlId'])) {
                        if (isset($this->forcedShowInGroup[$oneControl['controlId']])) {
                            $oneControl['showIn'] = array_values(array_unique(array_merge(
                                $oneControl['showIn'],
                                $this->forcedShowInGroup[$oneControl['controlId']]
                            )));
                            $forcedGroup = array_merge($forcedGroup, $this->forcedShowInGroup[$oneControl['controlId']]);
                        }
                    }
                }
                unset($oneControl);
                if (!empty($forcedGroup)) {
                    $forcedGroup = array_values(array_unique($forcedGroup));
                    $showControl['showIn'] = array_values(array_unique(array_merge($showControl['showIn'], $forcedGroup)));
                }
                unset($forcedGroup);
            }
        } else {
            foreach ($showControl as &$oneControl) {
                if (isset($oneControl['controlId'])) {
                    if (isset($this->forcedShowInGroup[$oneControl['controlId']])) {
                        $oneControl['showIn'] = array_values(array_unique(array_merge(
                            $oneControl['showIn'],
                            $this->forcedShowInGroup[$oneControl['controlId']]
                        )));
                    }
                }
            }
            unset($oneControl);
        }
    }
}

class CCatalogCondTree extends CGlobalCondTree
{
    public function __construct()
    {
        parent::__construct();
    }

    public function __destruct()
    {
        parent::__destruct();
    }
}
