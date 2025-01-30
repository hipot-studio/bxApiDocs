<?php

namespace Bitrix\Catalog\Component\Preset;

use Bitrix\Intranet\Composite\CacheProvider;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

class menu implements Preset
{
    public function enable()
    {
        Option::set('intranet', 'left_menu_crm_store_menu', 'Y');

        $this->clearCache();
    }

    public function disable()
    {
        Option::set('intranet', 'left_menu_crm_store_menu', 'N');

        $this->clearCache();
    }

    public function isOn(): bool
    {
        return 'Y' === Option::get('intranet', 'left_menu_crm_store_menu', 'N');
    }

    protected function clearCache()
    {
        \CBitrixComponent::clearComponentCache('bitrix:menu');
        $GLOBALS['CACHE_MANAGER']->CleanDir('menu');
        $GLOBALS['CACHE_MANAGER']->ClearByTag('bitrix24_left_menu');

        if (Loader::includeModule('intranet')) {
            CacheProvider::deleteUserCache();
        }
    }
}
