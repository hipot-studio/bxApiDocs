<?php

namespace Bitrix\Catalog\Component\Preset;

use Bitrix\Main\Config\Option;

class crm implements Preset
{
    public function enable()
    {
        Option::set('catalog', 'preset_crm_store', 'Y');
    }

    public function disable()
    {
        Option::delete('catalog', ['name' => 'preset_crm_store']);
    }

    public function isOn(): bool
    {
        return 'Y' === Option::get('catalog', 'preset_crm_store', 'N');
    }
}
