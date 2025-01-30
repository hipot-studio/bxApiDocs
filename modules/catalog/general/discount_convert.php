<?php

use Bitrix\Catalog;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

class discount_convert
{
    public static $intConvertPerStep = 0;
    public static $intNextConvertPerStep = 0;
    public static $intConverted = 0;
    public static $intLastConvertID = 0;
    public static $boolEmptyList = false;
    public static $intErrors = 0;
    public static $arErrors = [];
    public static $strSessID = '';

    public function __construct() {}

    public static function InitStep()
    {
        if ('' === self::$strSessID) {
            self::$strSessID = 'DC'.time();
        }
        if (isset($_SESSION[self::$strSessID]) && is_array($_SESSION[self::$strSessID])) {
            if (isset($_SESSION[self::$strSessID]['ERRORS_COUNT']) && (int) $_SESSION[self::$strSessID]['ERRORS_COUNT'] > 0) {
                self::$intErrors = (int) $_SESSION[self::$strSessID]['ERRORS_COUNT'];
            }
            if (isset($_SESSION[self::$strSessID]['ERRORS']) && is_array($_SESSION[self::$strSessID]['ERRORS'])) {
                self::$arErrors = $_SESSION[self::$strSessID]['ERRORS'];
            }
        }
    }

    public static function SaveStep()
    {
        if ('' === self::$strSessID) {
            self::$strSessID = 'DC'.time();
        }
        if (!isset($_SESSION[self::$strSessID]) || !is_array($_SESSION[self::$strSessID])) {
            $_SESSION[self::$strSessID] = [];
        }
        if (self::$intErrors > 0) {
            $_SESSION[self::$strSessID]['ERRORS_COUNT'] = self::$intErrors;
        }
        if (!empty(self::$arErrors)) {
            $_SESSION[self::$strSessID]['ERRORS'] = self::$arErrors;
        }
    }

    public static function GetErrors()
    {
        return self::$arErrors;
    }

    public static function ConvertDiscount($intStep = 100, $intMaxExecutionTime = 15)
    {
        global $DB;
        global $APPLICATION;

        self::InitStep();

        $intStep = (int) $intStep;
        if ($intStep <= 0) {
            $intStep = 100;
        }
        $startConvertTime = getmicrotime();

        $obDiscount = new CCatalogDiscount();

        $strQueryPriceTypes = '';
        $strQueryUserGroups = '';
        $strTableName = '';

        $strQueryPriceTypes = 'select CATALOG_GROUP_ID from b_catalog_discount2cat where DISCOUNT_ID = #ID#';
        $strQueryUserGroups = 'select GROUP_ID from b_catalog_discount2group where DISCOUNT_ID = #ID#';
        $strTableName = 'b_catalog_discount';

        CTimeZone::Disable();

        $rsDiscounts = CCatalogDiscount::GetList(
            ['ID' => 'ASC'],
            [
                'TYPE' => CCatalogDiscount::ENTITY_ID,
                'VERSION' => CCatalogDiscount::OLD_FORMAT,
            ],
            false,
            ['nTopCount' => $intStep],
            ['ID', 'MODIFIED_BY', 'TIMESTAMP_X', 'NAME', 'ACTIVE']
        );
        while ($arDiscount = $rsDiscounts->Fetch()) {
            $boolActive = true;
            $arSrcEntity = [];

            $arFields = [
                'MODIFIED_BY' => $arDiscount['MODIFIED_BY'],
                'ACTIVE' => $arDiscount['ACTIVE'],
            ];

            $arPriceTypes = [];
            $arUserGroups = [];

            $rsPriceTypes = $DB->Query(str_replace('#ID#', $arDiscount['ID'], $strQueryPriceTypes), false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
            while ($arPrice = $rsPriceTypes->Fetch()) {
                $arPrice['CATALOG_GROUP_ID'] = (int) $arPrice['CATALOG_GROUP_ID'];
                if ($arPrice['CATALOG_GROUP_ID'] > 0) {
                    $arPriceTypes[] = $arPrice['CATALOG_GROUP_ID'];
                }
            }
            if (!empty($arPriceTypes)) {
                $arPriceTypes = array_values(array_unique($arPriceTypes));
            } else {
                $arPriceTypes = [-1];
            }

            $rsUserGroups = $DB->Query(str_replace('#ID#', $arDiscount['ID'], $strQueryUserGroups), false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
            while ($arGroup = $rsUserGroups->Fetch()) {
                $arGroup['GROUP_ID'] = (int) $arGroup['GROUP_ID'];
                if ($arGroup['GROUP_ID'] > 0) {
                    $arUserGroups[] = $arGroup['GROUP_ID'];
                }
            }
            if (!empty($arUserGroups)) {
                $arUserGroups = array_values(array_unique($arUserGroups));
            } else {
                $arUserGroups = [-1];
            }

            $arFields['CATALOG_GROUP_IDS'] = $arPriceTypes;
            $arFields['GROUP_IDS'] = $arUserGroups;

            $arIBlockList = [];
            $arSectionList = [];
            $arElementList = [];
            $arConditions = [
                'CLASS_ID' => 'CondGroup',
                'DATA' => [
                    'All' => 'AND',
                    'True' => 'True',
                ],
                'CHILDREN' => [],
            ];
            $intEntityCount = 0;

            $boolEmpty = true;
            $arSrcList = [];
            $rsIBlocks = CCatalogDiscount::GetDiscountIBlocksList([], ['DISCOUNT_ID' => $arDiscount['ID']], false, false, ['IBLOCK_ID']);
            while ($arIBlock = $rsIBlocks->Fetch()) {
                $boolEmpty = false;
                $arSrcList[] = $arIBlock['IBLOCK_ID'];
                $arIBlock['IBLOCK_ID'] = (int) $arIBlock['IBLOCK_ID'];
                if ($arIBlock['IBLOCK_ID'] > 0) {
                    $strName = CIBlock::GetArrayByID($arIBlock['IBLOCK_ID'], 'NAME');
                    if (false !== $strName && null !== $strName) {
                        $arIBlockList[] = $arIBlock['IBLOCK_ID'];
                    }
                }
            }
            if (!empty($arIBlockList)) {
                $arIBlockList = array_values(array_unique($arIBlockList));
                ++$intEntityCount;
            } else {
                if (!$boolEmpty) {
                    $boolActive = false;
                    $arSrcEntity[] = str_replace('#IDS#', implode(', ', $arSrcList), Loc::getMessage('BT_MOD_CAT_DSC_CONV_ENTITY_IBLOCK_ERR'));
                }
            }

            $boolEmpty = true;
            $arSrcList = [];
            $rsSections = CCatalogDiscount::GetDiscountSectionsList([], ['DISCOUNT_ID' => $arDiscount['ID']], false, false, ['SECTION_ID']);
            while ($arSection = $rsSections->Fetch()) {
                $boolEmpty = false;
                $arSrcList[] = $arSection['SECTION_ID'];
                $arSection['SECTION_ID'] = (int) $arSection['SECTION_ID'];
                if ($arSection['SECTION_ID'] > 0) {
                    $arSectionList[] = $arSection['SECTION_ID'];
                }
            }
            if (!empty($arSectionList)) {
                $arSectionList = array_values(array_unique($arSectionList));
                $rsSections = CIBlockSection::GetList([], ['ID' => $arSectionList], false, ['ID']);
                $arCheckResult = [];
                while ($arSection = $rsSections->Fetch()) {
                    $arCheckResult[] = (int) $arSection['ID'];
                }
                if (!empty($arCheckResult)) {
                    $arSectionList = $arCheckResult;
                    ++$intEntityCount;
                } else {
                    $arSectionList = [];
                }
            }

            if (empty($arSectionList)) {
                if (!$boolEmpty) {
                    $boolActive = false;
                    $arSrcEntity[] = str_replace('#IDS#', implode(', ', $arSrcList), Loc::getMessage('BT_MOD_CAT_DSC_CONV_ENTITY_SECTION_ERR'));
                }
            }

            $boolEmpty = true;
            $arSrcList = [];
            $rsElements = CCatalogDiscount::GetDiscountProductsList([], ['DISCOUNT_ID' => $arDiscount['ID']], false, false, ['PRODUCT_ID']);
            while ($arElement = $rsElements->Fetch()) {
                $boolEmpty = false;
                $arSrcList[] = $arElement['PRODUCT_ID'];
                $arElement['PRODUCT_ID'] = (int) $arElement['PRODUCT_ID'];
                if ($arElement['PRODUCT_ID'] > 0) {
                    $arElementList[] = $arElement['PRODUCT_ID'];
                }
            }
            if (!empty($arElementList)) {
                $arElementList = array_values(array_unique($arElementList));
                $rsItems = CIBlockElement::GetList([], ['ID' => $arElementList], false, false, ['ID']);
                $arCheckResult = [];
                while ($arItem = $rsItems->Fetch()) {
                    $arCheckResult[] = (int) $arItem['ID'];
                }
                if (!empty($arCheckResult)) {
                    $arElementList = $arCheckResult;
                    ++$intEntityCount;
                } else {
                    $arElementList = [];
                }
            }

            if (empty($arElementList)) {
                if (!$boolEmpty) {
                    $boolActive = false;
                    $arSrcEntity[] = str_replace('#IDS#', implode(', ', $arSrcList), Loc::getMessage('BT_MOD_CAT_DSC_CONV_ENTITY_ELEMENT_ERR'));
                }
            }

            if (!empty($arIBlockList)) {
                if (1 < count($arIBlockList)) {
                    $arList = [];
                    foreach ($arIBlockList as &$intItemID) {
                        $arList[] = [
                            'CLASS_ID' => 'CondIBIBlock',
                            'DATA' => [
                                'logic' => 'Equal',
                                'value' => $intItemID,
                            ],
                        ];
                    }
                    if (isset($intItemID)) {
                        unset($intItemID);
                    }
                    if (1 === $intEntityCount) {
                        $arConditions = [
                            'CLASS_ID' => 'CondGroup',
                            'DATA' => [
                                'All' => 'OR',
                                'True' => 'True',
                            ],
                            'CHILDREN' => $arList,
                        ];
                    } else {
                        $arConditions['CHILDREN'][] = [
                            'CLASS_ID' => 'CondGroup',
                            'DATA' => [
                                'All' => 'OR',
                                'True' => 'True',
                            ],
                            'CHILDREN' => $arList,
                        ];
                    }
                } else {
                    $arConditions['CHILDREN'][] = [
                        'CLASS_ID' => 'CondIBIBlock',
                        'DATA' => [
                            'logic' => 'Equal',
                            'value' => current($arIBlockList),
                        ],
                    ];
                }
            }

            if (!empty($arSectionList)) {
                if (1 < count($arSectionList)) {
                    $arList = [];
                    foreach ($arSectionList as &$intItemID) {
                        $arList[] = [
                            'CLASS_ID' => 'CondIBSection',
                            'DATA' => [
                                'logic' => 'Equal',
                                'value' => $intItemID,
                            ],
                        ];
                    }
                    if (isset($intItemID)) {
                        unset($intItemID);
                    }
                    if (1 === $intEntityCount) {
                        $arConditions = [
                            'CLASS_ID' => 'CondGroup',
                            'DATA' => [
                                'All' => 'OR',
                                'True' => 'True',
                            ],
                            'CHILDREN' => $arList,
                        ];
                    } else {
                        $arConditions['CHILDREN'][] = [
                            'CLASS_ID' => 'CondGroup',
                            'DATA' => [
                                'All' => 'OR',
                                'True' => 'True',
                            ],
                            'CHILDREN' => $arList,
                        ];
                    }
                } else {
                    $arConditions['CHILDREN'][] = [
                        'CLASS_ID' => 'CondIBSection',
                        'DATA' => [
                            'logic' => 'Equal',
                            'value' => current($arSectionList),
                        ],
                    ];
                }
            }

            if (!empty($arElementList)) {
                if (1 < count($arElementList)) {
                    $arList = [];
                    foreach ($arElementList as &$intItemID) {
                        $arList[] = [
                            'CLASS_ID' => 'CondIBElement',
                            'DATA' => [
                                'logic' => 'Equal',
                                'value' => $intItemID,
                            ],
                        ];
                    }
                    if (isset($intItemID)) {
                        unset($intItemID);
                    }
                    if (1 === $intEntityCount) {
                        $arConditions = [
                            'CLASS_ID' => 'CondGroup',
                            'DATA' => [
                                'All' => 'OR',
                                'True' => 'True',
                            ],
                            'CHILDREN' => $arList,
                        ];
                    } else {
                        $arConditions['CHILDREN'][] = [
                            'CLASS_ID' => 'CondGroup',
                            'DATA' => [
                                'All' => 'OR',
                                'True' => 'True',
                            ],
                            'CHILDREN' => $arList,
                        ];
                    }
                } else {
                    $arConditions['CHILDREN'][] = [
                        'CLASS_ID' => 'CondIBElement',
                        'DATA' => [
                            'logic' => 'Equal',
                            'value' => current($arElementList),
                        ],
                    ];
                }
            }

            $arFields['CONDITIONS'] = $arConditions;

            if (!$boolActive) {
                $arFields['ACTIVE'] = 'N';
                ++self::$intErrors;
                self::$arErrors[] = [
                    'ID' => $arDiscount['ID'],
                    'NAME' => $arDiscount['NAME'],
                    'ERROR' => Loc::getMessage('BT_MOD_CAT_DSC_CONV_INACTIVE').' '.implode('; ', $arSrcEntity),
                ];
            }

            $mxRes = $obDiscount->Update($arDiscount['ID'], $arFields);
            if (!$mxRes) {
                ++self::$intErrors;
                $strError = '';
                if ($ex = $APPLICATION->GetException()) {
                    $strError = $ex->GetString();
                }
                if (empty($strError)) {
                    $strError = Loc::getMessage('BT_MOD_CAT_DSC_FORMAT_ERR');
                }
                self::$arErrors[] = [
                    'ID' => $arDiscount['ID'],
                    'NAME' => $arDiscount['NAME'],
                    'ERROR' => $strError,
                ];
            } else {
                $arTimeFields = ['~TIMESTAMP_X' => $DB->CharToDateFunction($arDiscount['TIMESTAMP_X'], 'FULL')];
                $strUpdate = $DB->PrepareUpdate($strTableName, $arTimeFields);
                if (!empty($strUpdate)) {
                    $strQuery = 'UPDATE '.$strTableName.' SET '.$strUpdate.' WHERE ID = '.$arDiscount['ID'].' AND TYPE = '.CCatalogDiscount::ENTITY_ID;
                    $DB->Query($strQuery, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
                }

                ++self::$intConverted;
                ++self::$intConvertPerStep;
            }

            if ($intMaxExecutionTime > 0 && (getmicrotime() - $startConvertTime > $intMaxExecutionTime)) {
                break;
            }
        }

        CTimeZone::Enable();

        if ($intMaxExecutionTime > (2 * (getmicrotime() - $startConvertTime))) {
            self::$intNextConvertPerStep = $intStep * 2;
        } else {
            self::$intNextConvertPerStep = $intStep;
        }

        self::SaveStep();
    }

    public static function ConvertFormatDiscount($intStep = 20, $intMaxExecutionTime = 15)
    {
        global $DB;
        global $APPLICATION;

        self::InitStep();

        $intStep = (int) $intStep;
        if ($intStep <= 0) {
            $intStep = 20;
        }
        $startConvertTime = getmicrotime();

        $obDiscount = new CCatalogDiscount();

        $strTableName = 'b_catalog_discount';

        if (!CCatalogDiscountConvertTmp::CreateTable()) {
            return false;
        }

        if (self::$intLastConvertID <= 0) {
            self::$intLastConvertID = CCatalogDiscountConvertTmp::GetLastID();
        }

        CTimeZone::Disable();

        self::$boolEmptyList = true;

        $rsDiscounts = CCatalogDiscount::GetList(
            ['ID' => 'ASC'],
            [
                '>ID' => self::$intLastConvertID,
                'TYPE' => CCatalogDiscount::ENTITY_ID,
                'VERSION' => CCatalogDiscount::CURRENT_FORMAT,
            ],
            false,
            ['nTopCount' => $intStep],
            ['ID', 'MODIFIED_BY', 'TIMESTAMP_X', 'CONDITIONS', 'NAME', 'ACTIVE']
        );
        while ($arDiscount = $rsDiscounts->Fetch()) {
            $mxExist = CCatalogDiscountConvertTmp::IsExistID($arDiscount['ID']);
            if (false === $mxExist) {
                ++self::$intErrors;

                return false;
            }
            self::$boolEmptyList = false;
            if (0 < $mxExist) {
                ++self::$intConverted;
                ++self::$intConvertPerStep;
                self::$intLastConvertID = $arDiscount['ID'];

                continue;
            }

            $iterator = Catalog\DiscountCouponTable::getList([
                'select' => ['DISCOUNT_ID'],
                'filter' => ['=DISCOUNT_ID' => (int) $arDiscount['ID']],
                'limit' => 1,
            ]);
            $existRow = $iterator->fetch();
            unset($iterator);
            $arFields = [
                'MODIFIED_BY' => $arDiscount['MODIFIED_BY'],
                'CONDITIONS' => $arDiscount['CONDITIONS'],
                'ACTIVE' => $arDiscount['ACTIVE'],
                'USE_COUPONS' => (!empty($existRow) ? 'Y' : 'N'),
            ];
            unset($existRow);

            $mxRes = $obDiscount->Update($arDiscount['ID'], $arFields);
            if (!$mxRes) {
                ++self::$intErrors;
                $strError = '';
                if ($ex = $APPLICATION->GetException()) {
                    $strError = $ex->GetString();
                }
                if (empty($strError)) {
                    $strError = Loc::getMessage('BT_MOD_CAT_DSC_FORMAT_ERR');
                }
                self::$arErrors[] = [
                    'ID' => $arDiscount['ID'],
                    'NAME' => $arDiscount['NAME'],
                    'ERROR' => $strError,
                ];
                if (!CCatalogDiscountConvertTmp::SetID($arDiscount['ID'])) {
                    return false;
                }

                ++self::$intConverted;
                ++self::$intConvertPerStep;
                self::$intLastConvertID = $arDiscount['ID'];
            } else {
                $arTimeFields = ['~TIMESTAMP_X' => $DB->CharToDateFunction($arDiscount['TIMESTAMP_X'], 'FULL')];
                $strUpdate = $DB->PrepareUpdate($strTableName, $arTimeFields);
                if (!empty($strUpdate)) {
                    $strQuery = 'UPDATE '.$strTableName.' SET '.$strUpdate.' WHERE ID = '.$arDiscount['ID'].' AND TYPE = '.CCatalogDiscount::ENTITY_ID;
                    $DB->Query($strQuery, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
                }
                if (!CCatalogDiscountConvertTmp::SetID($arDiscount['ID'])) {
                    return false;
                }

                ++self::$intConverted;
                ++self::$intConvertPerStep;
                self::$intLastConvertID = $arDiscount['ID'];
            }

            if ($intMaxExecutionTime > 0 && (getmicrotime() - $startConvertTime > $intMaxExecutionTime)) {
                break;
            }
        }
        CTimeZone::Enable();

        if ($intMaxExecutionTime > (2 * (getmicrotime() - $startConvertTime))) {
            self::$intNextConvertPerStep = $intStep * 2;
        } else {
            self::$intNextConvertPerStep = $intStep;
        }

        self::SaveStep();

        return true;
    }

    public static function GetCountOld()
    {
        global $DB;

        $strSql = 'SELECT COUNT(*) CNT FROM b_catalog_discount WHERE TYPE='.CCatalogDiscount::ENTITY_ID.' AND VERSION='.CCatalogDiscount::OLD_FORMAT;

        $res = $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
        if (!$res) {
            return 0;
        }

        if ($row = $res->Fetch()) {
            return (int) $row['CNT'];
        }

        return 0;
    }

    public static function GetCountFormat()
    {
        if (!CCatalogDiscountConvertTmp::CreateTable()) {
            return false;
        }

        return CCatalogDiscountConvertTmp::GetNeedConvert(self::$intLastConvertID);
    }

    public static function FormatComplete()
    {
        return CCatalogDiscountConvertTmp::DropTable();
    }
}
