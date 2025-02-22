<?php

namespace Bitrix\Crm\Activity\Provider;

use Bitrix\Crm\Activity\TodoPingSettingsProvider;
use Bitrix\Crm\Model\ActivityPingOffsetsTable;
use Bitrix\Crm\Settings\Crm;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

class todo extends Base
{
    public const PROVIDER_ID = 'CRM_TODO';
    public const PROVIDER_TYPE_ID_DEFAULT = 'TODO';

    public static function getId(): string
    {
        return self::PROVIDER_ID;
    }

    public static function getName()
    {
        return Loc::getMessage('CRM_ACTIVITY_TODO_NAME');
    }

    public static function isTypeEditable($providerTypeId = null, $direction = \CCrmActivityDirection::Undefined): bool
    {
        return false;
    }

    public static function getTypes()
    {
        return [
            [
                'NAME' => Loc::getMessage('CRM_ACTIVITY_TODO_NAME'),
                'PROVIDER_ID' => self::getId(),
                'PROVIDER_TYPE_ID' => self::PROVIDER_TYPE_ID_DEFAULT,
            ],
        ];
    }

    public static function hasPlanner(array $activity): bool
    {
        return false;
    }

    public static function getAdditionalFieldsForEdit(array $activity)
    {
        return [
            ['TYPE' => 'DESCRIPTION'],
            ['TYPE' => 'FILE'],
        ];
    }

    public static function checkFields($action, &$fields, $id, $params = null)
    {
        $isInitiatedByCalendar = (
            !empty($params['INITIATED_BY_CALENDAR'])
            || !empty($fields['CALENDAR_EVENT_ID'])
        );

        if (isset($fields['END_TIME']) && '' !== (string) $fields['END_TIME']) {
            $fields['DEADLINE'] = $fields['END_TIME'];
        }

        if (self::ACTION_UPDATE === $action && $isInitiatedByCalendar) {
            if (isset($fields['START_TIME']) && '' !== (string) $fields['START_TIME']) {
                $fields['DEADLINE'] = $fields['START_TIME'];
            } elseif (isset($fields['~START_TIME']) && '' !== (string) $fields['~START_TIME']) {
                $fields['~DEADLINE'] = $fields['~START_TIME'];
            }
        }

        if (
            self::ACTION_UPDATE === $action
            && isset($fields['SUBJECT'])
            && (empty($fields['DESCRIPTION']) || $isInitiatedByCalendar)
        ) {
            $fields['DESCRIPTION'] = $fields['SUBJECT'];
        }

        return new Result();
    }

    public static function getDefaultPingOffsets(array $params = []): array
    {
        if (isset($params['entityTypeId'], $params['categoryId'])) {
            return (new TodoPingSettingsProvider($params['entityTypeId'], $params['categoryId']))
                ->getCurrentOffsets()
            ;
        }

        return TodoPingSettingsProvider::DEFAULT_OFFSETS;
    }

    public static function getPingOffsets(?int $activityId): array
    {
        if (isset($activityId)) {
            return ActivityPingOffsetsTable::getOffsetsByActivityId($activityId);
        }

        return static::getDefaultPingOffsets();
    }

    public static function canUseCalendarEvents($providerTypeId = null): bool
    {
        return Crm::isTimelineToDoCalendarSyncEnabled();
    }

    public static function skipCalendarSync(array $activityFields, array $options = []): bool
    {
        if (!empty($activityFields['CALENDAR_EVENT_ID'])) {
            return false;
        }

        return (bool) ($options['SKIP_CURRENT_CALENDAR_EVENT'] ?? true);
    }

    public static function getTypesFilterPresets()
    {
        return [
            [
                'NAME' => self::getName(),
            ],
        ];
    }
}
