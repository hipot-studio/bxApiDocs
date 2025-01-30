<?php

namespace Bitrix\Crm\Activity\Settings;

use Bitrix\Crm\Activity\Settings\Section\Calendar;

final class factory
{
    public static function getInstance(
        string $name,
        array $data = [],
        array $activityData = []
    ): SettingsInterface {
        if (Calendar::TYPE_NAME === $name) {
            return new Calendar($data, $activityData);
        }

        throw new UnknownSettingsSectionException('Activity settings class: '.$name.' is not known');
    }
}
