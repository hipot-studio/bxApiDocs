<?php

namespace Bitrix\Crm\Security\Role\UIAdapters\AccessRights\Utils;

use Bitrix\Crm\Traits\Singleton;
use Bitrix\Main\Access\Permission\PermissionDictionary;

class valuenormalizer
{
    use Singleton;

    public function fromPermsToUI(array $perm, ?string $controlType): null|array|string
    {
        switch ($controlType) {
            case PermissionDictionary::TYPE_TOGGLER:
                return !empty($perm['ATTR']) ? '1' : null;

            case PermissionDictionary::TYPE_VARIABLES:
                return empty($perm['ATTR']) ? '0' : $perm['ATTR'];

            case PermissionDictionary::TYPE_MULTIVARIABLES:
                return $perm['SETTINGS'];

            default:
                return $perm['ATTR'];
        }
    }

    public function fromUIToPerms(null|array|string $value, string $controlType): null|array|string
    {
        switch ($controlType) {
            case PermissionDictionary::TYPE_TOGGLER:
                return 1 === $value ? BX_CRM_PERM_ALL : BX_CRM_PERM_NONE;

            case PermissionDictionary::TYPE_VARIABLES:
            case PermissionDictionary::TYPE_MULTIVARIABLES:
                return '0' === $value ? BX_CRM_PERM_NONE : $value;

            default:
                return $value;
        }
    }
}
