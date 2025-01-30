<?php

namespace Bitrix\Landing\Zip;

use Bitrix\Landing\Manager;
use Bitrix\Main\ModuleManager;

class config
{
    /**
     * Enable or not main option.
     *
     * @return bool
     */
    public static function serviceEnabled()
    {
        if (ModuleManager::isModuleInstalled('bitrix24')) {
            return true;
        }

        return 'Y' === Manager::getOption('enable_mod_zip', 'N');
    }
}
