<?php

namespace Bitrix\Catalog\Component\Preset;

class enum
{
    public const TYPE_CRM = 'crm';
    public const TYPE_MENU = 'menu';
    public const TYPE_STORE = 'store';
    public const TYPE_MATERIAL = 'material';

    public static function getAllType(): array
    {
        return [
            self::TYPE_CRM,
            self::TYPE_MENU,
            self::TYPE_STORE,
            self::TYPE_MATERIAL,
        ];
    }

    public static function getUseAllType(): array
    {
        return [
            self::TYPE_CRM,
            self::TYPE_MENU,
            self::TYPE_STORE,
        ];
    }
}
