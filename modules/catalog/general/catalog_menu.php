<?php
IncludeModuleLangFile(__FILE__);

/**
 * Class CCatalogMenu.
 */
class catalog_menu extends CAdminMenu
{
    /**
     * @var string
     */
    protected static $urlCurrent = '';

    /**
     * @param int    $level
     * @param string $urlCurrent
     *
     * @return string
     */
    public function Show($aMenu, $level = 0, $urlCurrent = '')
    {
        if (!static::$urlCurrent) {
            static::$urlCurrent = $urlCurrent;
        }

        $scripts = '';
        $aMenu['module_id'] = 'iblock';

        $bSubmenu = (isset($aMenu['items']) && is_array($aMenu['items']) && !empty($aMenu['items'])) || isset($aMenu['dynamic']) && true === $aMenu['dynamic'];
        $bSectionActive = isset($aMenu['items_id']) && (in_array($aMenu['items_id'], array_keys($this->aActiveSections), true) || $this->IsSectionActive($aMenu['items_id']));

        $icon = isset($aMenu['icon']) && '' !== $aMenu['icon']
            ? '<span class="adm-submenu-item-link-icon '.$aMenu['icon'].'"></span>'
            //			: ($level < 1 ? '<span class="adm-submenu-item-link-icon" id="default_menu_icon"></span>' : '');
            : '';
        $id = 'menu_item_'.randString(10);
        ?><div class="adm-sub-submenu-block<?php echo $level > 0 ? ' adm-submenu-level-'.($level + 1) : ''; ?><?php echo $bSectionActive && isset($aMenu['items']) && is_array($aMenu['items']) && count($aMenu['items']) > 0 ? ' adm-sub-submenu-open' : ''; ?><?php echo $aMenu['_active'] ? ' adm-submenu-item-active' : ''; ?>"><?php
        ?><div class="adm-submenu-item-name<?php echo !$bSubmenu ? ' adm-submenu-no-children' : ''; ?>" id="<?php echo $id; ?>" <?php echo isset($aMenu['fav_id']) ? ' data-fav-id="'.(int) $aMenu['fav_id'].'"' : ''; ?>><?php
        $onclick = '';
        if ($bSubmenu) {
            if (isset($aMenu['dynamic']) && true === $aMenu['dynamic'] && (!$aMenu['items'] || count($aMenu['items']) <= 0)) {
                $onclick = 'BX.adminMenu.toggleDynSection('.$this->_get_menu_item_width($level).", this.parentNode.parentNode, '".htmlspecialcharsbx(CUtil::JSEscape($aMenu['module_id']))."', '".htmlspecialcharsbx(CUtil::JSEscape($aMenu['items_id']))."', '".($level + 1)."', '".CUtil::JSEscape(htmlspecialcharsbx(static::$urlCurrent))."')";
            } elseif (!$aMenu['dynamic'] || !$bSectionActive || $aMenu['dynamic'] && $bSectionActive && isset($aMenu['items']) && count($aMenu['items']) > 0) {
                $onclick = "BX.adminMenu.toggleSection(this.parentNode.parentNode, '".htmlspecialcharsbx(CUtil::JSEscape($aMenu['items_id']))."', '".($level + 1)."')";
            } // endif;
        }

        ?><span class="adm-submenu-item-arrow"<?php echo $level > 0 ? ' style="width:'.$this->_get_menu_item_width($level).'px;"' : ''; ?><?php echo $onclick ? ' onclick="'.$onclick.'"' : ''; ?>><span class="adm-submenu-item-arrow-icon"></span></span><?php

        if (isset($aMenu['url']) && '' !== $aMenu['url']) {
            ?><a class="adm-submenu-item-name-link<?php echo isset($aMenu['readonly']) && true === $aMenu['readonly'] ? ' menutext-readonly' : ''; ?>"<?php echo $level > 0 ? ' style="padding-left:'.$this->_get_menu_item_padding($level).'px;"' : ''; ?> href="<?php echo htmlspecialcharsbx($aMenu['url']); ?>"><?php echo $icon; ?><span class="adm-submenu-item-name-link-text"><?php echo $aMenu['text']; ?></span></a><?php
        } elseif ($bSubmenu) {
            if (isset($aMenu['dynamic']) && true === $aMenu['dynamic'] && !$bSectionActive && (!$aMenu['items'] || count($aMenu['items']) <= 0)) {
                ?><a class="adm-submenu-item-name-link<?php echo isset($aMenu['readonly']) && true === $aMenu['readonly'] ? ' menutext-readonly' : ''; ?>"<?php echo $level > 0 ? ' style="padding-left:'.$this->_get_menu_item_padding($level).'px;"' : ''; ?> href="javascript:void(0)" onclick="BX.adminMenu.toggleDynSection(<?php echo $this->_get_menu_item_width($level - 1); ?>, this.parentNode.parentNode, '<?php echo htmlspecialcharsbx(CUtil::JSEscape($aMenu['module_id'])); ?>', '<?php echo htmlspecialcharsbx(CUtil::JSEscape($aMenu['items_id'])); ?>', '<?php echo $level + 1; ?>', '<?php echo CUtil::JSEscape(htmlspecialcharsbx(static::$urlCurrent)); ?>')"><?php echo $icon; ?><span class="adm-submenu-item-name-link-text"><?php echo $aMenu['text']; ?></span></a><?php
            } elseif (!$aMenu['dynamic'] || !$bSectionActive || $aMenu['dynamic'] && $bSectionActive && isset($aMenu['items']) && count($aMenu['items']) > 0) {
                ?><a class="adm-submenu-item-name-link<?php echo isset($aMenu['readonly']) && true === $aMenu['readonly'] ? ' menutext-readonly' : ''; ?>"<?php echo $level > 0 ? ' style="padding-left:'.$this->_get_menu_item_padding($level).'px;"' : ''; ?> href="javascript:void(0)" onclick="BX.adminMenu.toggleSection(this.parentNode.parentNode, '<?php echo htmlspecialcharsbx(CUtil::JSEscape($aMenu['items_id'])); ?>', '<?php echo $level + 1; ?>')"><?php echo $icon; ?><span class="adm-submenu-item-name-link-text"><?php echo $aMenu['text']; ?></span></a><?php
            }
        } else {
            ?><span class="adm-submenu-item-name-link<?php echo isset($aMenu['readonly']) && true === $aMenu['readonly'] ? ' menutext-readonly' : ''; ?>"<?php echo $level > 0 ? ' style="padding-left:'.$this->_get_menu_item_padding($level).'px"' : ''; ?>><?php echo $icon; ?><span class="adm-submenu-item-name-link-text"><?php echo $aMenu['text']; ?></span></span><?php
        }
        ?></div><?php

        if (($bSubmenu || (isset($aMenu['dynamic']) && true === $aMenu['dynamic'])) && is_array($aMenu['items'])) {
            echo '<div class="adm-sub-submenu-block-children">';
            foreach ($aMenu['items'] as $submenu) {
                if ($submenu) {
                    $scripts .= $this->Show($submenu, $level + 1);
                }
            }
            echo '</div>';
        } else {
            echo '<div class="adm-sub-submenu-block-children"></div>';
        }
        ?></div><?php
        if (isset($aMenu['fav_id'])) {
            $scripts .= "BX.adminMenu.registerItem('".$id."', {FAV_ID:'".CUtil::JSEscape($aMenu['fav_id'])."'});";
        } elseif (isset($aMenu['items_id']) && $aMenu['url']) {
            $scripts .= "BX.adminMenu.registerItem('".$id."', {ID:'".CUtil::JSEscape($aMenu['items_id'])."', URL:'".CUtil::JSEscape(htmlspecialcharsback($aMenu['url']))."', MODULE_ID:'".$aMenu['module_id']."'});";
        } elseif (isset($aMenu['items_id'])) {
            $scripts .= "BX.adminMenu.registerItem('".$id."', {ID:'".CUtil::JSEscape($aMenu['items_id'])."', MODULE_ID:'".$aMenu['module_id']."'});";
        } elseif ($aMenu['url']) {
            $scripts .= "BX.adminMenu.registerItem('".$id."', {URL:'".CUtil::JSEscape(htmlspecialcharsback($aMenu['url']))."'});";
        }

        return $scripts;
    }

    /**
     * @param string $mode
     * @param string $urlBack
     */
    public function ShowSubmenu($menu_id, $mode = 'menu', $urlBack = '')
    {
        foreach ($this->aGlobalMenu as $key => $menu) {
            if ($this->_ShowSubmenu($this->aGlobalMenu[$key], $menu_id, $mode, 0, $urlBack)) {
                break;
            }
        }
    }

    /**
     * @param int $urlBack
     * @param int $level
     *
     * @return bool
     */
    public function _ShowSubmenu(&$aMenu, $menu_id, $mode, $level = 0, $urlBack = '')
    {
        $bSubmenu = (is_array($aMenu['items']) && count($aMenu['items']) > 0);

        if ($bSubmenu) {
            if ($aMenu['items_id'] === $menu_id) {
                if ('menu' === $mode) {
                    $menuScripts = '';
                    foreach ($aMenu['items'] as $submenu) {
                        if (is_array($submenu)) {
                            if ($level >= 3) {
                                $level -= 3;
                            }
                            if ($urlBack) {
                                $submenu = self::fReplaceUrl($submenu, $urlBack);
                            }
                            $menuScripts .= $this->Show($submenu, $level, $urlBack);
                        }
                    }
                    if ('' !== $menuScripts) {
                        echo '<script type="text/javascript">'.$menuScripts.'</script>';
                    }
                }

                return true;
            }

            foreach ($aMenu['items'] as $submenu) {
                if ($this->_ShowSubmenu($submenu, $menu_id, $mode, $level + 1, $urlBack)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return mixed
     */
    public static function fReplaceUrl($submenu, $urlCurrent)
    {
        $urlCurrentDefault = $urlCurrent;

        $arUrlAdd = ['set_filter' => 'Y'];

        $url = $submenu['url'];
        $urlParse = parse_url($url);
        $arUrlTag = explode('&', $urlParse['query']);

        foreach ($arUrlTag as $tag) {
            $tmp = explode('=', $tag);
            if ('IBLOCK_ID' === $tmp[0] || 'find_section_section' === $tmp[0]) {
                if ('find_section_section' === $tmp[0]) {
                    $tmp[0] = 'filter_section';
                }

                $urlCurrent = CHTTP::urlDeleteParams($urlCurrent, [$tmp[0]]);
                $arUrlAdd[$tmp[0]] = $tmp[1];
            }
        }

        $url = CHTTP::urlAddParams($urlCurrent, $arUrlAdd, ['encode', 'skip_empty']);
        $submenu['url'] = $url;

        if (isset($submenu['items']) && count($submenu['items']) > 0) {
            $subCatalog = self::fReplaceUrl($submenu['items'], $urlCurrentDefault);
            $submenu['items'] = $subCatalog;
        }

        return $submenu;
    }

    /**
     * @return mixed
     */
    private function _get_menu_item_width($level)
    {
        static $START_MAGIC_NUMBER = 30, $STEP_MAGIC_NUMBER = 21;

        return $START_MAGIC_NUMBER + $level * $STEP_MAGIC_NUMBER;
    }

    /**
     * @return mixed
     */
    private function _get_menu_item_padding($level)
    {
        static $ADDED_MAGIC_NUMBER = 8;

        return $this->_get_menu_item_width($level) + $ADDED_MAGIC_NUMBER;
    }
}
