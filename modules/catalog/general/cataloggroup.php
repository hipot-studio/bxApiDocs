<?php

use Bitrix\Catalog;
use Bitrix\Main;
use Bitrix\Main\Application;

IncludeModuleLangFile(__FILE__);

class cataloggroup
{
    protected static $arBaseGroupCache = [];

    public static function CheckFields($ACTION, &$arFields, $ID = 0)
    {
        global $APPLICATION;
        global $USER;
        global $DB;

        $boolResult = true;
        $arMsg = [];

        $ACTION = mb_strtoupper($ACTION);
        if ('UPDATE' !== $ACTION && 'ADD' !== $ACTION) {
            return false;
        }

        if (array_key_exists('NAME', $arFields) || 'ADD' === $ACTION) {
            $arFields['NAME'] = trim($arFields['NAME']);
            if ('' === $arFields['NAME']) {
                $arMsg[] = ['id' => 'NAME', 'text' => GetMessage('BT_MOD_CAT_GROUP_ERR_EMPTY_NAME')];
                $boolResult = false;
            }
        }

        if ((array_key_exists('BASE', $arFields) || 'ADD' === $ACTION) && 'Y' !== $arFields['BASE']) {
            $arFields['BASE'] = 'N';
        }

        if (array_key_exists('SORT', $arFields) || 'ADD' === $ACTION) {
            $arFields['SORT'] = (int) $arFields['SORT'];
            if (0 >= $arFields['SORT']) {
                $arFields['SORT'] = 100;
            }
        }

        $intUserID = 0;
        $boolUserExist = CCatalog::IsUserExists();
        if ($boolUserExist) {
            $intUserID = (int) $USER->GetID();
        }
        $strDateFunction = $DB->GetNowFunction();
        if (array_key_exists('TIMESTAMP_X', $arFields)) {
            unset($arFields['TIMESTAMP_X']);
        }
        if (array_key_exists('DATE_CREATE', $arFields)) {
            unset($arFields['DATE_CREATE']);
        }
        $arFields['~TIMESTAMP_X'] = $strDateFunction;
        if (array_key_exists('MODIFIED_BY', $arFields)) {
            if (false !== $arFields['MODIFIED_BY']) {
                $arFields['MODIFIED_BY'] = (int) $arFields['MODIFIED_BY'];
                if ($arFields['MODIFIED_BY'] <= 0) {
                    unset($arFields['MODIFIED_BY']);
                }
            }
        }
        if (!isset($arFields['MODIFIED_BY']) && $boolUserExist) {
            $arFields['MODIFIED_BY'] = $intUserID;
        }
        if ('ADD' === $ACTION) {
            $arFields['~DATE_CREATE'] = $strDateFunction;
            if (array_key_exists('CREATED_BY', $arFields)) {
                if (false !== $arFields['CREATED_BY']) {
                    $arFields['CREATED_BY'] = (int) $arFields['CREATED_BY'];
                    if ($arFields['CREATED_BY'] <= 0) {
                        unset($arFields['CREATED_BY']);
                    }
                }
            }
            if (!isset($arFields['CREATED_BY']) && $boolUserExist) {
                $arFields['CREATED_BY'] = $intUserID;
            }
        }
        if ('UPDATE' === $ACTION) {
            if (array_key_exists('CREATED_BY', $arFields)) {
                unset($arFields['CREATED_BY']);
            }
        }

        if (is_set($arFields, 'USER_GROUP') || 'ADD' === $ACTION) {
            if (!is_array($arFields['USER_GROUP']) || empty($arFields['USER_GROUP'])) {
                $arMsg[] = ['id' => 'USER_GROUP', 'text' => GetMessage('BT_MOD_CAT_GROUP_ERR_EMPTY_USER_GROUP')];
                $boolResult = false;
            } else {
                $arValid = [];
                foreach ($arFields['USER_GROUP'] as &$intValue) {
                    $intValue = (int) $intValue;
                    if (0 < $intValue) {
                        $arValid[] = $intValue;
                    }
                }
                if (isset($intValue)) {
                    unset($intValue);
                }
                if (!empty($arValid)) {
                    $arFields['USER_GROUP'] = array_values(array_unique($arValid));
                } else {
                    $arMsg[] = ['id' => 'USER_GROUP', 'text' => GetMessage('BT_MOD_CAT_GROUP_ERR_EMPTY_USER_GROUP')];
                    $boolResult = false;
                }
            }
        }

        if (is_set($arFields, 'USER_GROUP_BUY') || 'ADD' === $ACTION) {
            if (!is_array($arFields['USER_GROUP_BUY']) || empty($arFields['USER_GROUP_BUY'])) {
                $arMsg[] = ['id' => 'USER_GROUP_BUY', 'text' => GetMessage('BT_MOD_CAT_GROUP_ERR_EMPTY_USER_GROUP_BUY')];
                $boolResult = false;
            } else {
                $arValid = [];
                foreach ($arFields['USER_GROUP_BUY'] as &$intValue) {
                    $intValue = (int) $intValue;
                    if (0 < $intValue) {
                        $arValid[] = $intValue;
                    }
                }
                if (isset($intValue)) {
                    unset($intValue);
                }
                if (!empty($arValid)) {
                    $arFields['USER_GROUP_BUY'] = array_values(array_unique($arValid));
                } else {
                    $arMsg[] = ['id' => 'USER_GROUP_BUY', 'text' => GetMessage('BT_MOD_CAT_GROUP_ERR_EMPTY_USER_GROUP_BUY')];
                    $boolResult = false;
                }
            }
        }

        if (!$boolResult) {
            $obError = new CAdminException($arMsg);
            $APPLICATION->ResetException();
            $APPLICATION->ThrowException($obError);
        }

        return $boolResult;
    }

    public static function GetGroupsPerms($arUserGroups = [], $arCatalogGroupsFilter = [])
    {
        global $USER;

        if (!is_array($arUserGroups)) {
            $arUserGroups = [$arUserGroups];
        }

        if (empty($arUserGroups)) {
            $arUserGroups = (CCatalog::IsUserExists() ? $USER->GetUserGroupArray() : [2]);
        }
        Main\Type\Collection::normalizeArrayValuesByInt($arUserGroups);

        if (!is_array($arCatalogGroupsFilter)) {
            $arCatalogGroupsFilter = [$arCatalogGroupsFilter];
        }
        Main\Type\Collection::normalizeArrayValuesByInt($arCatalogGroupsFilter);
        if (!empty($arCatalogGroupsFilter)) {
            $arCatalogGroupsFilter = array_fill_keys($arCatalogGroupsFilter, true);
        }

        $result = [
            'view' => [],
            'buy' => [],
        ];

        if (empty($arUserGroups)) {
            return $result;
        }

        if (defined('CATALOG_SKIP_CACHE') && CATALOG_SKIP_CACHE) {
            $priceTypeIterator = CCatalogGroup::GetGroupsList(['@GROUP_ID' => $arUserGroups]);
            while ($priceType = $priceTypeIterator->Fetch()) {
                $priceTypeId = (int) $priceType['CATALOG_GROUP_ID'];
                $key = ('Y' === $priceType['BUY'] ? 'buy' : 'view');
                if ('view' === $key && !empty($arCatalogGroupsFilter) && !isset($arCatalogGroupsFilter[$priceTypeId])) {
                    continue;
                }
                $result[$key][$priceTypeId] = $priceTypeId;
                unset($key, $priceTypeId);
            }
            unset($priceType, $priceTypeIterator);
            if (!empty($result['view'])) {
                $result['view'] = array_values($result['view']);
            }
            if (!empty($result['buy'])) {
                $result['buy'] = array_values($result['buy']);
            }

            return $result;
        }

        $data = [];
        $cacheTime = (int) (defined('CATALOG_CACHE_TIME') ? CATALOG_CACHE_TIME : CATALOG_CACHE_DEFAULT_TIME);
        $managedCache = Application::getInstance()->getManagedCache();
        if ($managedCache->read($cacheTime, 'catalog_group_perms')) {
            $data = $managedCache->get('catalog_group_perms');
        } else {
            $priceTypeIterator = CCatalogGroup::GetGroupsList();
            while ($priceType = $priceTypeIterator->Fetch()) {
                $priceTypeId = (int) $priceType['CATALOG_GROUP_ID'];
                $groupId = (int) $priceType['GROUP_ID'];
                $key = ('Y' === $priceType['BUY'] ? 'buy' : 'view');

                if (!isset($data[$groupId])) {
                    $data[$groupId] = [
                        'view' => [],
                        'buy' => [],
                    ];
                }
                $data[$groupId][$key][$priceTypeId] = $priceTypeId;
                unset($key, $groupId, $priceTypeId);
            }
            unset($priceType, $priceTypeIterator);
            if (!empty($data)) {
                foreach ($data as &$groupData) {
                    if (!empty($groupData['view'])) {
                        $groupData['view'] = array_values($groupData['view']);
                    }
                    if (!empty($groupData['buy'])) {
                        $groupData['buy'] = array_values($groupData['buy']);
                    }
                }
                unset($groupData);
            }
            $managedCache->set('catalog_group_perms', $data);
        }

        foreach ($arUserGroups as &$groupId) {
            if (!isset($data[$groupId])) {
                continue;
            }
            if (!empty($data[$groupId]['view'])) {
                $priceTypeList = $data[$groupId]['view'];
                foreach ($priceTypeList as &$priceTypeId) {
                    if (!empty($arCatalogGroupsFilter) && !isset($arCatalogGroupsFilter[$priceTypeId])) {
                        continue;
                    }
                    $result['view'][$priceTypeId] = $priceTypeId;
                }
                unset($priceTypeId, $priceTypeList);
            }
            if (!empty($data[$groupId]['buy'])) {
                $priceTypeList = $data[$groupId]['buy'];
                foreach ($priceTypeList as &$priceTypeId) {
                    $result['buy'][$priceTypeId] = $priceTypeId;
                }
                unset($priceTypeId, $priceTypeList);
            }
        }
        unset($groupId);

        if (!empty($result['view'])) {
            $result['view'] = array_values($result['view']);
        }
        if (!empty($result['buy'])) {
            $result['buy'] = array_values($result['buy']);
        }

        return $result;
    }

    /**
     * @deprecated
     * @see Catalog\GroupTable::getTypeList()
     */
    public static function GetListArray(): array
    {
        return Catalog\GroupTable::getTypeList();
    }

    /**
     * @deprecated
     * @see Catalog\GroupTable::getBasePriceType()
     *
     * @return array|false
     */
    public static function GetBaseGroup()
    {
        $group = Catalog\GroupTable::getBasePriceType();
        if (!empty($group)) {
            $group['NAME_LANG'] = (string) $group['NAME_LANG'];
            $group['XML_ID'] = (string) $group['XML_ID'];

            return $group;
        }

        return false;
    }

    /**
     * @deprecated
     * @see Catalog\GroupTable::getBasePriceTypeId()
     */
    public static function GetBaseGroupId(): ?int
    {
        return Catalog\GroupTable::getBasePriceTypeId();
    }
}
