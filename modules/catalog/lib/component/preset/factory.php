<?php

namespace Bitrix\Catalog\Component\Preset;

class factory
{
    /**
     * @return null|Crm|Material|Menu|Store
     */
    public static function create($type)
    {
        if (Enum::TYPE_CRM === $type) {
            return new Crm();
        }
        if (Enum::TYPE_MATERIAL === $type) {
            return new Material();
        }
        if (Enum::TYPE_MENU === $type) {
            return new Menu();
        }
        if (Enum::TYPE_STORE === $type) {
            return new Store();
        }

        return null;
    }
}
