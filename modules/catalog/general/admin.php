<?php

/** @global CAdminMenu $adminMenu */

use Bitrix\Catalog;
use Bitrix\Catalog\Access\AccessController;
use Bitrix\Catalog\Access\ActionDictionary;
use Bitrix\Iblock;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

class admin
{
    protected static $catalogRead = false;
    protected static $catalogGroup = false;
    protected static $catalogPrice = false;
    protected static $catalogMeasure = false;
    protected static $catalogDiscount = false;
    protected static $catalogVat = false;
    protected static $catalogExtra = false;
    protected static $catalogStore = false;
    protected static $catalogExportEdit = false;
    protected static $catalogExportExec = false;
    protected static $catalogImportEdit = false;
    protected static $catalogImportExec = false;
    protected static $catalogSettings = false;
    protected static $adminMenuExists;

    public static function get_other_elements_menu($IBLOCK_TYPE_ID, $IBLOCK_ID, $arSection, &$more_url)
    {
        $arSection['ID'] = (int) $arSection['ID'];
        $urlSectionAdminPage = CIBlock::GetAdminSectionListLink($IBLOCK_ID, ['catalog' => null, 'skip_public' => true]);
        $more_url[] = $urlSectionAdminPage.'&find_section_section='.$arSection['ID'];
        $more_url[] = CIBlock::GetAdminElementListLink($IBLOCK_ID, ['find_section_section' => $arSection['ID']]);
        $more_url[] = CIBlock::GetAdminSectionEditLink($IBLOCK_ID, $arSection['ID'], ['catalog' => null, 'find_section_section' => $arSection['ID']]);
        $more_url[] = CIBlock::GetAdminSectionEditLink($IBLOCK_ID, 0, ['catalog' => null, 'find_section_section' => $arSection['ID']]);

        if (($arSection['RIGHT_MARGIN'] - $arSection['LEFT_MARGIN']) > 1) {
            $rsSections = CIBlockSection::GetList(
                ['LEFT_MARGIN' => 'ASC'],
                [
                    'IBLOCK_ID' => $IBLOCK_ID,
                    'SECTION_ID' => $arSection['ID'],
                ],
                false,
                ['ID', 'IBLOCK_SECTION_ID', 'NAME', 'LEFT_MARGIN', 'RIGHT_MARGIN']
            );
            while ($arSubSection = $rsSections->Fetch()) {
                CCatalogAdmin::get_other_elements_menu($IBLOCK_TYPE_ID, $IBLOCK_ID, $arSubSection, $more_url);
            }
        }
    }

    public static function get_sections_menu($IBLOCK_TYPE_ID, $IBLOCK_ID, $DEPTH_LEVEL, $SECTION_ID, $arSectionsChain = false)
    {
        if (null === self::$adminMenuExists) {
            self::$adminMenuExists = isset($adminMenu) && $adminMenu instanceof CAdminMenu;
        }

        if (isset($_REQUEST['public_menu'])) {
            return [];
        }

        global $adminMenu;
        if (false === $arSectionsChain) {
            $arSectionsChain = [];
            if (isset($_REQUEST['admin_mnu_menu_id'])) {
                $menu_id = 'menu_catalog_category_'.$IBLOCK_ID.'/';
                if (0 === strncmp($_REQUEST['admin_mnu_menu_id'], $menu_id, mb_strlen($menu_id))) {
                    $rsSections = CIBlockSection::GetNavChain($IBLOCK_ID, mb_substr($_REQUEST['admin_mnu_menu_id'], mb_strlen($menu_id)), ['ID', 'IBLOCK_ID']);
                    while ($arSection = $rsSections->Fetch()) {
                        $arSectionsChain[$arSection['ID']] = $arSection['ID'];
                    }
                }
            }
            if (
                isset($_REQUEST['find_section_section'])
                && (int) $_REQUEST['find_section_section'] > 0
                && isset($_REQUEST['IBLOCK_ID'])
                && $_REQUEST['IBLOCK_ID'] === $IBLOCK_ID
            ) {
                $rsSections = CIBlockSection::GetNavChain($IBLOCK_ID, $_REQUEST['find_section_section'], ['ID', 'IBLOCK_ID']);
                while ($arSection = $rsSections->Fetch()) {
                    $arSectionsChain[$arSection['ID']] = $arSection['ID'];
                }
            }
            if (defined('PUBLIC_MODE') && PUBLIC_MODE === 1) {
                $arSectionsChain = [];
                $rsSections = CIBlockSection::GetList([], ['IBLOCK_ID' => $IBLOCK_ID], false, ['ID']);
                while ($arSection = $rsSections->Fetch()) {
                    $arSectionsChain[$arSection['ID']] = $arSection['ID'];
                }
            }
        }

        $baseUrlSectionAdminPage = CIBlock::GetAdminSectionListLink($IBLOCK_ID, ['catalog' => null, 'skip_public' => true]);

        $arSections = [];
        $rsSections = CIBlockSection::GetList(
            ['LEFT_MARGIN' => 'ASC'],
            [
                'IBLOCK_ID' => $IBLOCK_ID,
                'SECTION_ID' => $SECTION_ID,
            ],
            false,
            ['ID', 'IBLOCK_SECTION_ID', 'NAME', 'LEFT_MARGIN', 'RIGHT_MARGIN']
        );
        $intCount = 0;
        $arOtherSectionTmp = [];
        $limit = (int) Option::get('iblock', 'iblock_menu_max_sections');
        $sortCount = 0.01;
        while ($arSection = $rsSections->Fetch()) {
            $arSection['ID'] = (int) $arSection['ID'];
            $arSection['IBLOCK_SECTION_ID'] = (int) $arSection['IBLOCK_SECTION_ID'];
            if ($limit > 0 && $intCount >= $limit) {
                if (empty($arOtherSectionTmp)) {
                    $urlSectionAdminPage = $baseUrlSectionAdminPage.'&find_section_section='.
                        $arSection['IBLOCK_SECTION_ID'].'&SECTION_ID='.$arSection['IBLOCK_SECTION_ID'];
                    $arOtherSectionTmp = [
                        'text' => Loc::getMessage('CAT_MENU_ALL_OTH'),
                        'url' => $urlSectionAdminPage.'&apply_filter=Y',
                        'more_url' => [
                            $urlSectionAdminPage,
                            CIBlock::GetAdminElementListLink($IBLOCK_ID, ['find_section_section' => $arSection['ID']]),
                            CIBlock::GetAdminElementEditLink($IBLOCK_ID, 0, ['find_section_section' => $arSection['ID']]),
                            CIBlock::GetAdminSectionEditLink($IBLOCK_ID, 0, ['catalog' => null]),
                            CIBlock::GetAdminSectionEditLink($IBLOCK_ID, $arSection['ID'], ['catalog' => null]),
                        ],
                        'title' => Loc::getMessage('CAT_MENU_ALL_OTH_TITLE'),
                        'icon' => 'iblock_menu_icon_sections',
                        'page_icon' => 'iblock_page_icon_sections',
                        'skip_chain' => true,
                        'items_id' => 'menu_catalog_category_'.$IBLOCK_ID.'/'.$arSection['ID'],
                        'module_id' => 'catalog',
                        'items' => [],
                        'sort' => 203 + $sortCount,
                    ];
                    CCatalogAdmin::get_other_elements_menu($IBLOCK_TYPE_ID, $IBLOCK_ID, $arSection, $arOtherSectionTmp['more_url']);
                } else {
                    $arOtherSectionTmp['more_url'][] = $baseUrlSectionAdminPage.'&find_section_section='.$arSection['ID'].'&SECTION_ID='.$arSection['ID'];
                    $arOtherSectionTmp['more_url'][] = CIBlock::GetAdminElementEditLink($IBLOCK_ID, 0, ['find_section_section' => $arSection['ID']]);
                    $arOtherSectionTmp['more_url'][] = CIBlock::GetAdminSectionEditLink($IBLOCK_ID, 0, ['catalog' => null]);
                    $arOtherSectionTmp['more_url'][] = CIBlock::GetAdminSectionEditLink($IBLOCK_ID, $arSection['ID'], ['catalog' => null]);
                }
                $sortCount += $sortCount + 0.01;
            } else {
                $urlSectionAdminPage = $baseUrlSectionAdminPage.'&find_section_section='.$arSection['ID'].'&SECTION_ID='.$arSection['ID'];
                $arSectionTmp = [
                    'text' => htmlspecialcharsEx($arSection['NAME']),
                    'url' => $urlSectionAdminPage.'&apply_filter=Y',
                    'more_url' => [
                        $urlSectionAdminPage,
                        CIBlock::GetAdminElementListLink($IBLOCK_ID, ['find_section_section' => $arSection['ID']]),
                        CIBlock::GetAdminElementEditLink($IBLOCK_ID, 0, ['find_section_section' => $arSection['ID']]),
                        CIBlock::GetAdminSectionEditLink($IBLOCK_ID, 0, ['catalog' => null]),
                        CIBlock::GetAdminSectionEditLink($IBLOCK_ID, $arSection['ID'], ['catalog' => null]),
                    ],
                    'title' => htmlspecialcharsEx($arSection['NAME']),
                    'icon' => 'iblock_menu_icon_sections',
                    'page_icon' => 'iblock_page_icon_sections',
                    'skip_chain' => true,
                    'items_id' => 'menu_catalog_category_'.$IBLOCK_ID.'/'.$arSection['ID'],
                    'module_id' => 'catalog',
                    'dynamic' => (($arSection['RIGHT_MARGIN'] - $arSection['LEFT_MARGIN']) > 1),
                    'items' => [],
                    'sort' => 203 + $sortCount,
                ];

                if (isset($arSectionsChain[$arSection['ID']])) {
                    $arSectionTmp['items'] = CCatalogAdmin::get_sections_menu($IBLOCK_TYPE_ID, $IBLOCK_ID, $DEPTH_LEVEL + 1, $arSection['ID'], $arSectionsChain);
                } elseif (self::$adminMenuExists) {
                    if ($adminMenu->IsSectionActive('menu_catalog_category_'.$IBLOCK_ID.'/'.$arSection['ID'])) {
                        $arSectionTmp['items'] = CCatalogAdmin::get_sections_menu($IBLOCK_TYPE_ID, $IBLOCK_ID, $DEPTH_LEVEL + 1, $arSection['ID'], $arSectionsChain);
                    }
                }

                $arSections[] = $arSectionTmp;
                $sortCount += $sortCount + 0.01;
            }
            ++$intCount;
        }
        if (!empty($arOtherSectionTmp)) {
            $arSections[] = $arOtherSectionTmp;
        }

        return $arSections;
    }

    public static function OnBuildGlobalMenu(/* @noinspection PhpUnusedParameterInspection */ &$aGlobalMenu, &$aModuleMenu)
    {
        if (defined('BX_CATALOG_UNINSTALLED')) {
            return;
        }

        if (!Loader::includeModule('iblock')) {
            return;
        }

        self::initRights();

        $publicMenu = isset($_REQUEST['public_menu']);

        $aMenu = [
            'text' => Loc::getMessage('CAT_MENU_ROOT'),
            'title' => '',
            'items_id' => 'menu_catalog_list',
            'items' => [],
            'sort' => 200,
        ];
        $arCatalogs = [];
        $arCatalogSku = [];
        $iterator = Catalog\CatalogIblockTable::getList([
            'select' => ['IBLOCK_ID', 'PRODUCT_IBLOCK_ID'],
        ]);
        while ($row = $iterator->fetch()) {
            $row['PRODUCT_IBLOCK_ID'] = (int) $row['PRODUCT_IBLOCK_ID'];
            $row['IBLOCK_ID'] = (int) $row['IBLOCK_ID'];
            if ($row['PRODUCT_IBLOCK_ID'] > 0) {
                $arCatalogs[$row['PRODUCT_IBLOCK_ID']] = true;
                $arCatalogSku[$row['PRODUCT_IBLOCK_ID']] = $row['IBLOCK_ID'];
            } else {
                $arCatalogs[$row['IBLOCK_ID']] = true;
            }
        }
        unset($row, $iterator);
        if (empty($arCatalogs)) {
            return;
        }

        // TODO: replace this hack to api
        if ($publicMenu && Loader::includeModule('crm')) {
            $defaultCrmIblock = CCrmCatalog::GetDefaultID();
            $iterator = Iblock\IblockTable::getList([
                'select' => ['ID', 'XML_ID'],
                'filter' => ['@ID' => array_keys($arCatalogs)],
            ]);
            while ($row = $iterator->fetch()) {
                $iblockId = (int) $row['ID'];
                if ($iblockId === $defaultCrmIblock) {
                    continue;
                }
                if (0 === strncmp($row['XML_ID'], 'crm_external_', 13)) {
                    unset($arCatalogs[$iblockId]);
                }
            }
            unset($iblockId, $row, $iterator);
        }

        $listIblockId = array_keys($arCatalogs);

        if (empty($listIblockId)) {
            return;
        }

        $defaultProductsName = Loc::getMessage('CAT_MENU_PRODUCT_LIST_EXT');
        $defaultSectionsName = Loc::getMessage('CAT_MENU_PRODUCT_SECTION_LIST');
        $defaultMixedName = Loc::getMessage('CAT_MENU_PRODUCT_MIXED_LIST');

        $rsIBlocks = CIBlock::GetList(
            ['SORT' => 'ASC', 'NAME' => 'ASC'],
            ['ID' => $listIblockId, 'MIN_PERMISSION' => 'S']
        );
        $sortCount = 0.01;
        $totalCount = ($publicMenu ? $rsIBlocks->SelectedRowsCount() : 0);
        while ($arIBlock = $rsIBlocks->Fetch()) {
            $mixedList = Iblock\IblockTable::LIST_MODE_COMBINED === CIBlock::GetAdminListMode($arIBlock['ID']);
            $url = ($mixedList ? 'cat_product_list.php' : 'cat_product_admin.php');

            if ($mixedList) {
                $productsName = $defaultMixedName;
                $sectionsName = '';
            } else {
                $productsName = (string) CIBlock::GetArrayByID($arIBlock['ID'], 'ELEMENTS_NAME');
                if ('' === $productsName) {
                    $productsName = $defaultProductsName;
                }
                $sectionsName = (string) CIBlock::GetArrayByID($arIBlock['ID'], 'SECTIONS_NAME');
                if ('' === $sectionsName) {
                    $sectionsName = $defaultSectionsName;
                }
            }

            $arItems = [];
            $arItems[] = [
                'text' => htmlspecialcharsbx($productsName),
                'url' => (
                    $mixedList
                    ? $url.'?IBLOCK_ID='.$arIBlock['ID'].'&type='.urlencode($arIBlock['IBLOCK_TYPE_ID']).'&lang='.LANGUAGE_ID.'&find_section_section=0&SECTION_ID=0&apply_filter=Y'
                    : $url.'?IBLOCK_ID='.$arIBlock['ID'].'&type='.urlencode($arIBlock['IBLOCK_TYPE_ID']).'&lang='.LANGUAGE_ID.'&find_section_section=-1'
                ),
                'more_url' => [
                    $url.'?IBLOCK_ID='.$arIBlock['ID'].'&type='.urlencode($arIBlock['IBLOCK_TYPE_ID']).'&lang='.LANGUAGE_ID,
                    CIBlock::GetAdminElementListLink($arIBlock['ID'], ['find_section_section' => -1]),
                    CIBlock::GetAdminElementEditLink($arIBlock['ID'], null),
                    'cat_product_list.php?IBLOCK_ID='.$arIBlock['ID'].'&find_section_section=-1',
                    'cat_product_edit.php?IBLOCK_ID='.$arIBlock['ID'],
                ],
                'title' => '',
                'page_icon' => 'iblock_page_icon_elements',
                'items_id' => 'menu_catalog_goods_'.$arIBlock['ID'],
                'module_id' => 'catalog',
                'sort' => 202 + $sortCount,
            ];
            if (!$mixedList) {
                $arItems[] = [
                    'text' => htmlspecialcharsbx($sectionsName),
                    'url' => 'cat_section_admin.php?lang='.LANGUAGE_ID.'&type='.$arIBlock['IBLOCK_TYPE_ID'].'&IBLOCK_ID='.
                        $arIBlock['ID'].'&find_section_section=0&SECTION_ID=0&apply_filter=Y',
                    'more_url' => [
                        CIBlock::GetAdminElementListLink($arIBlock['ID'], ['find_section_section' => 0]),
                        'cat_section_admin.php?lang='.LANGUAGE_ID.'IBLOCK_ID='.$arIBlock['ID'].'&find_section_section=0&SECTION_ID=0',
                        CIBlock::GetAdminSectionEditLink($arIBlock['ID'], 0, ['catalog' => null]),
                    ],
                    'title' => '',
                    'page_icon' => 'iblock_page_icon_sections',
                    'items_id' => 'menu_catalog_category_'.$arIBlock['ID'],
                    'module_id' => 'catalog',
                    'items' => CCatalogAdmin::get_sections_menu($arIBlock['IBLOCK_TYPE_ID'], $arIBlock['ID'], 1, 0),
                    'sort' => 203 + $sortCount,
                    'ajax_options' => ($publicMenu ? [
                        'module_id' => 'catalog',
                        'params' => [
                            'iblock_id' => $arIBlock['ID'],
                            'section_id' => 0,
                        ],
                    ] : []),
                ];
            }

            if (
                CIBlockRights::UserHasRightTo($arIBlock['ID'], $arIBlock['ID'], 'iblock_edit')
                || ($publicMenu && self::$catalogSettings)
            ) {
                $arItems[] = [
                    'text' => Loc::getMessage('CAT_MENU_PRODUCT_PROPERTIES'),
                    'url' => 'iblock_property_admin.php?lang='.LANGUAGE_ID.'&IBLOCK_ID='.$arIBlock['ID'].'&admin=N',
                    'more_url' => [
                        'iblock_property_admin.php?IBLOCK_ID='.$arIBlock['ID'].'&admin=N',
                        'iblock_edit_property.php?IBLOCK_ID='.$arIBlock['ID'].'&admin=N',
                    ],
                    'title' => '',
                    'page_icon' => 'iblock_page_icon_settings',
                    'items_id' => 'menu_catalog_attributes_'.$arIBlock['ID'],
                    'module_id' => 'catalog',
                    'sort' => 204 + $sortCount,
                ];
            }

            if (isset($arCatalogSku[$arIBlock['ID']])) {
                $intOffersIBlockID = $arCatalogSku[$arIBlock['ID']];
                if (
                    CIBlockRights::UserHasRightTo($intOffersIBlockID, $intOffersIBlockID, 'iblock_edit')
                    || ($publicMenu && self::$catalogSettings)
                ) {
                    $arItems[] = [
                        'text' => Loc::getMessage('CAT_MENU_SKU_PROPERTIES'),
                        'url' => 'iblock_property_admin.php?lang='.LANGUAGE_ID.'&IBLOCK_ID='.$intOffersIBlockID.'&admin=N',
                        'more_url' => [
                            'iblock_property_admin.php?IBLOCK_ID='.$intOffersIBlockID.'&admin=N',
                            'iblock_edit_property.php?IBLOCK_ID='.$intOffersIBlockID.'&admin=N',
                        ],
                        'title' => '',
                        'page_icon' => 'iblock_page_icon_settings',
                        'items_id' => 'menu_catalog_attributes_'.$intOffersIBlockID,
                        'module_id' => 'catalog',
                        'sort' => 205 + $sortCount,
                    ];
                }
            }

            if (
                CIBlockRights::UserHasRightTo($arIBlock['ID'], $arIBlock['ID'], 'iblock_edit')
                || ($publicMenu && self::$catalogSettings)
            ) {
                $arItems[] = [
                    'text' => Loc::getMessage('CAT_MENU_CATALOG_SETTINGS'),
                    'url' => 'cat_catalog_edit.php?lang='.LANGUAGE_ID.'&IBLOCK_ID='.$arIBlock['ID'],
                    'more_url' => [
                        'cat_catalog_edit.php?IBLOCK_ID='.$arIBlock['ID'],
                    ],
                    'title' => '',
                    'page_icon' => 'iblock_page_icon_settings',
                    'items_id' => 'menu_catalog_edit_'.$arIBlock['ID'],
                    'module_id' => 'catalog',
                    'sort' => 206 + $sortCount,
                ];
            }

            if ($publicMenu) {
                $text = ($totalCount > 1 ? htmlspecialcharsEx($arIBlock['NAME']) : Loc::getMessage('CAT_MENU_ROOT_TITLE'));
            } else {
                $text = htmlspecialcharsEx($arIBlock['NAME']);
            }

            $aMenu['items'][] = [
                'text' => $text,
                'title' => '',
                'page_icon' => 'iblock_page_icon_sections',
                'items_id' => 'menu_catalog_'.$arIBlock['ID'],
                'module_id' => 'catalog',
                'items' => $arItems,
                'url' => $url.'?lang='.LANGUAGE_ID.'&IBLOCK_ID='.$arIBlock['ID'].'&type='.urlencode($arIBlock['IBLOCK_TYPE_ID']).'&find_section_section=-1',
                'sort' => 201 + $sortCount,
            ];
            $sortCount += $sortCount + 0.01;
        }
        unset($arIBlock, $rsIBlocks);

        // @global CUser $USER
        global $USER;
        $showMarketplaceLink = $USER->CanDoOperation('install_updates');

        if (!empty($aMenu['items'])) {
            $singleCatalog = 1 === count($aMenu['items']);
            if ($singleCatalog) {
                $aMenu = $aMenu['items'][0];
            } else {
                $aMenu['text'] = Loc::getMessage('CAT_MENU_ROOT_MULTI');
                if ($showMarketplaceLink) {
                    $aMenu['items'][] = [
                        'text' => Loc::getMessage('CAT_MENU_CATALOG_MARKETPLACE_ADD'),
                        'url' => 'update_system_market.php?category=107&lang='.LANGUAGE_ID,
                        'more_url' => ['update_system_market.php?category=107'],
                        'title' => Loc::getMessage('CAT_MENU_CATALOG_MARKETPLACE_ADD'),
                        'items_id' => 'update_system_market',
                        'sort' => 207 + $sortCount,
                    ];
                }
            }
            $aMenu['parent_menu'] = 'global_menu_store';
            $aMenu['section'] = 'catalog_list';
            $aMenu['sort'] = 200;
            $aMenu['icon'] = 'iblock_menu_icon_sections';
            $aMenu['page_icon'] = 'iblock_page_icon_types';
            $aModuleMenu[] = $aMenu;
            if ($singleCatalog && $showMarketplaceLink) {
                $aModuleMenu[] = [
                    'parent_menu' => 'global_menu_store',
                    'section' => 'catalog_list',
                    'text' => Loc::getMessage('CAT_MENU_CATALOG_MARKETPLACE_CATALOG_TOOLS'),
                    'title' => Loc::getMessage('CAT_MENU_CATALOG_MARKETPLACE_CATALOG_TOOLS'),
                    'icon' => 'iblock_menu_icon_sections',
                    'page_icon' => 'iblock_page_icon_types',
                    'items_id' => 'update_system_market',
                    'url' => 'update_system_market.php?category=107&lang='.LANGUAGE_ID,
                    'more_url' => ['update_system_market.php?category=107'],
                    'sort' => 201,
                ];
            }
            unset($singleCatalog);
        } else {
            if ($showMarketplaceLink) {
                $aMenu['items'] = [
                    [
                        'text' => Loc::getMessage('CAT_MENU_CATALOG_MARKETPLACE_CATALOG_TOOLS'),
                        'url' => 'update_system_market.php?category=107&lang='.LANGUAGE_ID,
                        'more_url' => ['update_system_market.php?category=107'],
                        'title' => Loc::getMessage('CAT_MENU_CATALOG_MARKETPLACE_CATALOG_TOOLS'),
                        'items_id' => 'update_system_market',
                        'sort' => 207,
                    ],
                ];
                $aMenu['parent_menu'] = 'global_menu_store';
                $aMenu['section'] = 'catalog_list';
                $aMenu['sort'] = 200;
                $aMenu['icon'] = 'iblock_menu_icon_sections';
                $aMenu['page_icon'] = 'iblock_page_icon_types';
                $aModuleMenu[] = $aMenu;
            }
        }
        unset($showMarketplaceLink, $aMenu);
    }

    /**
     * @deprecated deprecated since catalog 20.0.100
     *
     * @param CAdminUiList $obList
     */
    public static function OnAdminListDisplay(&$obList) {}

    public static function OnBuildSaleMenu(/* @noinspection PhpUnusedParameterInspection */ &$arGlobalMenu, &$arModuleMenu)
    {
        global $adminMenu;

        if (defined('BX_CATALOG_UNINSTALLED')) {
            return;
        }

        global $USER;
        if (!CCatalog::IsUserExists()) {
            return;
        }
        if (!Loader::includeModule('sale')) {
            return;
        }

        if (!defined('BX_SALE_MENU_CATALOG_CLEAR') || BX_SALE_MENU_CATALOG_CLEAR !== 'Y') {
            return;
        }

        self::initRights();
        self::$adminMenuExists = isset($adminMenu) && $adminMenu instanceof CAdminMenu;

        static::OnBuildSaleMenuItem($arModuleMenu);
    }

    protected static function OnBuildSaleMenuItem(&$arMenu)
    {
        if (empty($arMenu) || !is_array($arMenu)) {
            return;
        }

        $arMenuID = [
            'menu_sale_discounts',
            'menu_sale_taxes',
            'menu_sale_settings',
            'menu_catalog_store',
            'menu_sale_buyers',
        ];

        foreach ($arMenu as &$arMenuItem) {
            if (!isset($arMenuItem['items']) || !is_array($arMenuItem['items'])) {
                continue;
            }

            if (!isset($arMenuItem['items_id']) || !is_string($arMenuItem['items_id']) || !in_array($arMenuItem['items_id'], $arMenuID, true)) {
                continue;
            }

            switch ($arMenuItem['items_id']) {
                case 'menu_sale_discounts':
                    $useSaleDiscountOnly = (string) Option::get('sale', 'use_sale_discount_only');
                    if ('Y' !== $useSaleDiscountOnly) {
                        static::OnBuildSaleDiscountMenu($arMenuItem['items']);
                    }

                    break;

                case 'menu_sale_taxes':
                    static::OnBuildSaleTaxMenu($arMenuItem['items']);

                    break;

                case 'menu_sale_settings':
                    static::OnBuildSaleSettingsMenu($arMenuItem['items']);

                    break;

                case 'menu_catalog_store':
                    static::OnBuildSaleStoreMenu($arMenuItem['items']);

                    break;

                case 'menu_sale_buyers':
                    static::OnBuildSaleBuyersMenu($arMenuItem['items']);

                    break;
            }

            static::OnBuildSaleMenuItem($arMenuItem['items']);
        }
        unset($arMenuItem);
    }

    protected static function OnBuildSaleDiscountMenu(&$arItems)
    {
        if (self::$catalogRead || self::$catalogDiscount) {
            $arItemsIdAtEnd = ['menu_sale_marketplace'];
            $arItemsForEnd = [];
            foreach ($arItems as $key => $item) {
                if (isset($item['items_id']) && in_array($item['items_id'], $arItemsIdAtEnd, true)) {
                    $arItemsForEnd[] = $arItems[$key];
                    unset($arItems[$key]);
                }
            }

            $arItems[] = [
                'text' => Loc::getMessage('CM_DISCOUNTS3'),
                'title' => Loc::getMessage('CM_DISCOUNTS_ALT2'),
                'items_id' => 'menu_catalog_discount',
                'items' => [
                    [
                        'text' => Loc::getMessage('CM_DISCOUNTS3'),
                        'url' => 'cat_discount_admin.php?lang='.LANGUAGE_ID,
                        'more_url' => ['cat_discount_edit.php'],
                        'title' => Loc::getMessage('CM_DISCOUNTS_ALT2'),
                        'readonly' => !self::$catalogDiscount,
                        'items_id' => 'cat_discount_admin',
                    ],
                    [
                        'text' => Loc::getMessage('CM_COUPONS_EXT'),
                        'url' => 'cat_discount_coupon.php?lang='.LANGUAGE_ID,
                        'more_url' => ['cat_discount_coupon_edit.php'],
                        'title' => Loc::getMessage('CM_COUPONS_TITLE'),
                        'readonly' => !self::$catalogDiscount,
                        'items_id' => 'cat_discount_coupon',
                    ],
                ],
            ];
            if (Catalog\Config\Feature::isCumulativeDiscountsEnabled()) {
                $arItems[] = [
                    'text' => Loc::getMessage('CAT_DISCOUNT_SAVE'),
                    'url' => 'cat_discsave_admin.php?lang='.LANGUAGE_ID,
                    'more_url' => ['cat_discsave_edit.php'],
                    'title' => Loc::getMessage('CAT_DISCOUNT_SAVE_DESCR'),
                    'readonly' => !self::$catalogDiscount,
                    'items_id' => 'cat_discsave_admin',
                ];
            }

            if ($arItemsForEnd) {
                $arItems = array_merge($arItems, $arItemsForEnd);
            }
        }
    }

    protected static function OnBuildSaleTaxMenu(&$arItems)
    {
        if (self::$catalogRead || self::$catalogVat) {
            $arItems[] = [
                'text' => Loc::getMessage('VAT'),
                'url' => 'cat_vat_admin.php?lang='.LANGUAGE_ID,
                'more_url' => ['cat_vat_edit.php'],
                'title' => Loc::getMessage('VAT_ALT'),
                'readonly' => !self::$catalogVat,
                'items_id' => 'cat_vat_admin',
            ];
        }
    }

    protected static function OnBuildSaleSettingsMenu(&$arItems)
    {
        $showPrices = self::$catalogRead || self::$catalogGroup;
        $showExtra = (Catalog\Config\Feature::isMultiPriceTypesEnabled() && (self::$catalogRead || self::$catalogExtra));
        if ($showPrices || $showExtra) {
            $section = [
                'text' => Loc::getMessage('PRICES_SECTION'),
                'title' => Loc::getMessage('PRICES_SECTION_TITLE'),
                'items_id' => 'menu_catalog_prices',
                'items' => [],
                'sort' => 725.1,
            ];
            if ($showPrices) {
                $section['items'][] = [
                    'text' => Loc::getMessage('GROUP'),
                    'title' => Loc::getMessage('GROUP_ALT'),
                    'url' => 'cat_group_admin.php?lang='.LANGUAGE_ID,
                    'more_url' => ['cat_group_edit.php'],
                    'readonly' => !self::$catalogGroup,
                    'items_id' => 'cat_group_admin',
                    'sort' => 725.2,
                ];
                $section['items'][] = [
                    'text' => Loc::getMessage('PRICE_ROUND'),
                    'title' => Loc::getMessage('PRICE_ROUND_TITLE'),
                    'url' => 'cat_round_list.php?lang='.LANGUAGE_ID,
                    'more_url' => ['cat_round_edit.php'],
                    'readonly' => !self::$catalogGroup,
                    'items_id' => 'cat_round_list',
                    'sort' => 725.3,
                ];
            }
            if ($showExtra) {
                $section['items'][] = [
                    'text' => Loc::getMessage('EXTRA'),
                    'title' => Loc::getMessage('EXTRA_ALT'),
                    'url' => 'cat_extra.php?lang='.LANGUAGE_ID,
                    'more_url' => ['cat_extra_edit.php'],
                    'readonly' => !self::$catalogExtra,
                    'items_id' => 'cat_extra',
                    'sort' => 725.4,
                ];
            }
            $arItems[] = $section;
            unset($section);
        }
        unset($showExtra, $showPrices);

        if (self::$catalogRead || self::$catalogMeasure) {
            $arItems[] = [
                'text' => Loc::getMessage('MEASURE'),
                'url' => 'cat_measure_list.php?lang='.LANGUAGE_ID,
                'more_url' => ['cat_measure_edit.php'],
                'title' => Loc::getMessage('MEASURE_ALT'),
                'readonly' => !self::$catalogMeasure,
                'items_id' => 'cat_measure_list',
                'sort' => 726.1,
            ];
        }

        if (self::$catalogRead || self::$catalogExportEdit || self::$catalogExportExec) {
            $arItems[] = [
                'text' => Loc::getMessage('SETUP_UNLOAD_DATA'),
                'url' => 'cat_export_setup.php?lang='.LANGUAGE_ID,
                'more_url' => ['cat_exec_exp.php'],
                'title' => Loc::getMessage('SETUP_UNLOAD_DATA_ALT'),
                'dynamic' => true,
                'module_id' => 'sale',
                'items_id' => 'mnu_catalog_exp',
                'readonly' => !self::$catalogExportEdit && !self::$catalogExportExec,
                'items' => static::OnBuildSaleExportMenu('mnu_catalog_exp'),
            ];
        }

        if (self::$catalogRead || self::$catalogImportEdit || self::$catalogImportExec) {
            $arItems[] = [
                'text' => Loc::getMessage('SETUP_LOAD_DATA'),
                'url' => 'cat_import_setup.php?lang='.LANGUAGE_ID,
                'more_url' => ['cat_exec_imp.php'],
                'title' => Loc::getMessage('SETUP_LOAD_DATA_ALT'),
                'dynamic' => true,
                'module_id' => 'sale',
                'items_id' => 'mnu_catalog_imp',
                'readonly' => !self::$catalogImportEdit && !self::$catalogImportExec,
                'items' => static::OnBuildSaleImportMenu('mnu_catalog_imp'),
            ];
        }

        // @global CUser $USER
        global $USER;
        if (self::$catalogRead && $USER->CanDoOperation('install_updates')) {
            $arItems[] = [
                'text' => Loc::getMessage('SALE_MENU_MARKETPLACE_SETTINGS_ADD'),
                'url' => 'update_system_market.php?category=54&lang='.LANGUAGE_ID,
                'title' => Loc::getMessage('SALE_MENU_MARKETPLACE_SETTINGS_ADD'),
                'module_id' => 'sale',
                'items_id' => 'update_system_market',
            ];
        }
    }

    protected static function OnBuildSaleStoreMenu(&$arItems)
    {
        if (self::$catalogRead || self::$catalogStore) {
            $result = [];
            if (self::$catalogStore) {
                $allowRows = false;
                $rows = [
                    [
                        'text' => Loc::getMessage('CM_STORE_DOCS'),
                        'url' => 'cat_store_document_list.php?lang='.LANGUAGE_ID,
                        'more_url' => ['cat_store_document_edit.php'],
                        'title' => Loc::getMessage('CM_STORE_DOCS'),
                        'readonly' => !self::$catalogStore,
                        'items_id' => 'cat_store_document_list',
                        'sort' => 551,
                    ],
                    [
                        'text' => Loc::getMessage('CM_CONTRACTORS'),
                        'url' => 'cat_contractor_list.php?lang='.LANGUAGE_ID,
                        'more_url' => ['cat_contractor_edit.php'],
                        'title' => Loc::getMessage('CM_CONTRACTORS'),
                        'readonly' => !self::$catalogStore,
                        'items_id' => 'cat_contractor_list',
                        'sort' => 552,
                    ],
                ];
                if (Catalog\Config\Feature::isInventoryManagementEnabled()) {
                    if (Catalog\Config\State::isUsedInventoryManagement()) {
                        $allowRows = true;
                    }
                } else {
                    $helpLink = Catalog\Config\Feature::getInventoryManagementHelpLink();
                    if (null !== $helpLink) {
                        $allowRows = true;
                        foreach ($rows as &$item) {
                            unset($item['url'], $item['more_url']);
                            $item['is_locked'] = true;
                            $item['on_click'] = $helpLink['LINK'];
                        }
                        unset($item);
                    }
                }

                if ($allowRows) {
                    $result = $rows;
                }
                unset($rows, $allowRows);
            }
            $result[] = [
                'text' => Loc::getMessage('CM_STORE'),
                'url' => 'cat_store_list.php?lang='.LANGUAGE_ID,
                'more_url' => ['cat_store_edit.php'],
                'title' => Loc::getMessage('CM_STORE'),
                'readonly' => !self::$catalogStore,
                'items_id' => 'cat_store_list',
                'sort' => 553,
            ];
            $arItems = $result;
        }
    }

    protected static function OnBuildSaleBuyersMenu(&$arItems)
    {
        if (self::$catalogRead) {
            $found = false;
            if (!empty($arItems)) {
                foreach ($arItems as $item) {
                    if ($item['url'] === 'cat_subscription_list.php?lang='.LANGUAGE_ID) {
                        $found = true;

                        break;
                    }
                }
                unset($item);
            }
            if (!$found) {
                $arItems[] = [
                    'text' => Loc::getMessage('CM_SUBSCRIPTION_PRODUCT'),
                    'url' => 'cat_subscription_list.php?lang='.LANGUAGE_ID,
                    'more_url' => ['cat_subscription_list.php'],
                    'title' => Loc::getMessage('CM_SUBSCRIPTION_PRODUCT'),
                    'items_id' => 'cat_subscription_list',
                    'sort' => 407,
                ];
            }
            unset($found);
        }
    }

    protected static function OnBuildSaleExportMenu($strItemID)
    {
        global $adminMenu;

        $arProfileList = [];

        if (empty($strItemID)) {
            return $arProfileList;
        }
        if (!(self::$catalogRead || self::$catalogExportEdit || self::$catalogExportExec)) {
            return $arProfileList;
        }

        if (!self::$adminMenuExists) {
            return $arProfileList;
        }

        if ($adminMenu->IsSectionActive($strItemID)) {
            $rsProfiles = CCatalogExport::GetList(['NAME' => 'ASC', 'ID' => 'ASC'], ['IN_MENU' => 'Y']);
            while ($arProfile = $rsProfiles->Fetch()) {
                $arProfile['NAME'] = (string) $arProfile['NAME'];
                $strName = ('' !== $arProfile['NAME'] ? $arProfile['NAME'] : $arProfile['FILE_NAME']);
                if ('Y' === $arProfile['DEFAULT_PROFILE']) {
                    $arProfileList[] = [
                        'text' => htmlspecialcharsbx($strName),
                        'url' => 'cat_exec_exp.php?lang='.LANGUAGE_ID.'&ACT_FILE='.$arProfile['FILE_NAME'].'&ACTION=EXPORT&PROFILE_ID='.$arProfile['ID'].'&'.bitrix_sessid_get(),
                        'title' => Loc::getMessage('CAM_EXPORT_DESCR_EXPORT').' &quot;'.htmlspecialcharsbx($strName).'&quot;',
                        'readonly' => !self::$catalogExportExec,
                    ];
                } else {
                    $arProfileList[] = [
                        'text' => htmlspecialcharsbx($strName),
                        'url' => 'cat_export_setup.php?lang='.LANGUAGE_ID.'&ACT_FILE='.$arProfile['FILE_NAME'].'&ACTION=EXPORT_EDIT&PROFILE_ID='.$arProfile['ID'].'&'.bitrix_sessid_get(),
                        'title' => Loc::getMessage('CAM_EXPORT_DESCR_EDIT').' &quot;'.htmlspecialcharsbx($strName).'&quot;',
                        'readonly' => !self::$catalogExportEdit,
                    ];
                }
            }
        }

        return $arProfileList;
    }

    protected static function OnBuildSaleImportMenu($strItemID)
    {
        global $adminMenu;

        $arProfileList = [];

        if (empty($strItemID)) {
            return $arProfileList;
        }

        if (!(self::$catalogRead || self::$catalogImportEdit || self::$catalogImportExec)) {
            return $arProfileList;
        }

        if (!self::$adminMenuExists) {
            return $arProfileList;
        }

        if ($adminMenu->IsSectionActive($strItemID)) {
            $rsProfiles = CCatalogImport::GetList(['NAME' => 'ASC', 'ID' => 'ASC'], ['IN_MENU' => 'Y']);
            while ($arProfile = $rsProfiles->Fetch()) {
                $arProfile['NAME'] = (string) $arProfile['NAME'];
                $strName = ('' !== $arProfile['NAME'] ? $arProfile['NAME'] : $arProfile['FILE_NAME']);
                if ('Y' === $arProfile['DEFAULT_PROFILE']) {
                    $arProfileList[] = [
                        'text' => htmlspecialcharsbx($strName),
                        'url' => 'cat_exec_imp.php?lang='.LANGUAGE_ID.'&ACT_FILE='.$arProfile['FILE_NAME'].'&ACTION=IMPORT&PROFILE_ID='.$arProfile['ID'].'&'.bitrix_sessid_get(),
                        'title' => Loc::getMessage('CAM_IMPORT_DESCR_IMPORT').' &quot;'.htmlspecialcharsbx($strName).'&quot;',
                        'readonly' => !self::$catalogImportExec,
                    ];
                } else {
                    $arProfileList[] = [
                        'text' => htmlspecialcharsbx($strName),
                        'url' => 'cat_import_setup.php?lang='.LANGUAGE_ID.'&ACT_FILE='.$arProfile['FILE_NAME'].'&ACTION=IMPORT_EDIT&PROFILE_ID='.$arProfile['ID'].'&'.bitrix_sessid_get(),
                        'title' => Loc::getMessage('CAM_IMPORT_DESCR_EDIT').' &quot;'.htmlspecialcharsbx($strName).'&quot;',
                        'readonly' => !self::$catalogImportEdit,
                    ];
                }
            }
        }

        return $arProfileList;
    }

    private static function initRights(): void
    {
        $accessController = AccessController::getCurrent();

        self::$catalogRead = $accessController->check(ActionDictionary::ACTION_CATALOG_READ);
        self::$catalogGroup = $accessController->check(ActionDictionary::ACTION_PRICE_GROUP_EDIT);
        self::$catalogPrice = $accessController->check(ActionDictionary::ACTION_PRICE_EDIT);
        self::$catalogMeasure = $accessController->check(ActionDictionary::ACTION_MEASURE_EDIT);
        self::$catalogDiscount = $accessController->check(ActionDictionary::ACTION_PRODUCT_DISCOUNT_SET);
        self::$catalogVat = $accessController->check(ActionDictionary::ACTION_VAT_EDIT);
        self::$catalogExtra = $accessController->check(ActionDictionary::ACTION_PRODUCT_PRICE_EXTRA_EDIT);
        self::$catalogStore = $accessController->check(ActionDictionary::ACTION_STORE_VIEW);
        self::$catalogExportEdit = $accessController->check(ActionDictionary::ACTION_CATALOG_EXPORT_EDIT);
        self::$catalogExportExec = $accessController->check(ActionDictionary::ACTION_CATALOG_EXPORT_EXECUTION);
        self::$catalogImportEdit = $accessController->check(ActionDictionary::ACTION_CATALOG_IMPORT_EDIT);
        self::$catalogImportExec = $accessController->check(ActionDictionary::ACTION_CATALOG_IMPORT_EXECUTION);
        self::$catalogSettings = $accessController->check(ActionDictionary::ACTION_CATALOG_SETTINGS_ACCESS);
    }
}
