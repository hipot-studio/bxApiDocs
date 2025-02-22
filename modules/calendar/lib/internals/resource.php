<?php

namespace Bitrix\Calendar\Internals;

use Bitrix\Main;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/**
 * Class ResourceTable.
 *
 * Fields:
 * <ul>
 * <li> ID int mandatory
 * <li> EVENT_ID int optional
 * <li> CAL_TYPE string(100) optional
 * <li> RESOURCE_ID int mandatory
 * <li> PARENT_TYPE string(100) optional
 * <li> PARENT_ID int mandatory
 * <li> UF_ID int optional
 * <li> DATE_FROM_UTC datetime optional
 * <li> DATE_TO_UTC datetime optional
 * <li> DATE_FROM datetime optional
 * <li> DATE_TO datetime optional
 * <li> DURATION int optional
 * <li> SKIP_TIME string(1) optional
 * <li> TZ_FROM string(50) optional
 * <li> TZ_TO string(50) optional
 * <li> TZ_OFFSET_FROM int optional
 * <li> TZ_OFFSET_TO int optional
 * <li> CREATED_BY int mandatory
 * <li> DATE_CREATE datetime optional
 * <li> TIMESTAMP_X datetime optional
 * <li> SERVICE_NAME string(200) optional
 * </ul>
 *
 * @method static EO_Resource_Query                                 query()
 * @method static EO_Resource_Result                                getByPrimary($primary, array $parameters = [])
 * @method static EO_Resource_Result                                getById($id)
 * @method static EO_Resource_Result                                getList(array $parameters = [])
 * @method static EO_Resource_Entity                                getEntity()
 * @method static \Bitrix\Calendar\Internals\EO_Resource            createObject($setDefaultValues = true)
 * @method static \Bitrix\Calendar\Internals\EO_Resource_Collection createCollection()
 * @method static \Bitrix\Calendar\Internals\EO_Resource            wakeUpObject($row)
 * @method static \Bitrix\Calendar\Internals\EO_Resource_Collection wakeUpCollection($rows)
 */
class resource extends Main\Entity\DataManager
{
    /**
     * Returns DB table name for entity.
     *
     * @return string
     */
    public static function getTableName()
    {
        return 'b_calendar_resource';
    }

    /**
     * Returns entity map definition.
     *
     * @return array
     */
    public static function getMap()
    {
        return [
            'ID' => [
                'data_type' => 'integer',
                'primary' => true,
                'autocomplete' => true,
                'title' => Loc::getMessage('RESOURCE_ENTITY_ID_FIELD'),
            ],
            'EVENT_ID' => [
                'data_type' => 'integer',
                'title' => Loc::getMessage('RESOURCE_ENTITY_EVENT_ID_FIELD'),
            ],
            'CAL_TYPE' => [
                'data_type' => 'string',
                'validation' => [__CLASS__, 'validateCalType'],
                'title' => Loc::getMessage('RESOURCE_ENTITY_CAL_TYPE_FIELD'),
            ],
            'RESOURCE_ID' => [
                'data_type' => 'integer',
                'required' => true,
                'title' => Loc::getMessage('RESOURCE_ENTITY_RESOURCE_ID_FIELD'),
            ],
            'PARENT_TYPE' => [
                'data_type' => 'string',
                'validation' => [__CLASS__, 'validateParentType'],
                'title' => Loc::getMessage('RESOURCE_ENTITY_PARENT_TYPE_FIELD'),
            ],
            'PARENT_ID' => [
                'data_type' => 'integer',
                'required' => true,
                'title' => Loc::getMessage('RESOURCE_ENTITY_PARENT_ID_FIELD'),
            ],
            'UF_ID' => [
                'data_type' => 'integer',
                'required' => false,
                'title' => Loc::getMessage('RESOURCE_ENTITY_UF_ID_FIELD'),
            ],
            'DATE_FROM_UTC' => [
                'data_type' => 'datetime',
                'title' => Loc::getMessage('RESOURCE_ENTITY_DATE_FROM_UTC_FIELD'),
            ],
            'DATE_TO_UTC' => [
                'data_type' => 'datetime',
                'title' => Loc::getMessage('RESOURCE_ENTITY_DATE_TO_UTC_FIELD'),
            ],
            'DATE_FROM' => [
                'data_type' => 'datetime',
                'title' => Loc::getMessage('RESOURCE_ENTITY_DATE_FROM_FIELD'),
            ],
            'DATE_TO' => [
                'data_type' => 'datetime',
                'title' => Loc::getMessage('RESOURCE_ENTITY_DATE_TO_FIELD'),
            ],
            'DURATION' => [
                'data_type' => 'integer',
                'title' => Loc::getMessage('RESOURCE_ENTITY_DURATION_FIELD'),
            ],
            'SKIP_TIME' => [
                'data_type' => 'string',
                'validation' => [__CLASS__, 'validateSkipTime'],
                'title' => Loc::getMessage('RESOURCE_ENTITY_SKIP_TIME_FIELD'),
            ],
            'TZ_FROM' => [
                'data_type' => 'string',
                'validation' => [__CLASS__, 'validateTzFrom'],
                'title' => Loc::getMessage('RESOURCE_ENTITY_TZ_FROM_FIELD'),
            ],
            'TZ_TO' => [
                'data_type' => 'string',
                'validation' => [__CLASS__, 'validateTzTo'],
                'title' => Loc::getMessage('RESOURCE_ENTITY_TZ_TO_FIELD'),
            ],
            'TZ_OFFSET_FROM' => [
                'data_type' => 'integer',
                'title' => Loc::getMessage('RESOURCE_ENTITY_TZ_OFFSET_FROM_FIELD'),
            ],
            'TZ_OFFSET_TO' => [
                'data_type' => 'integer',
                'title' => Loc::getMessage('RESOURCE_ENTITY_TZ_OFFSET_TO_FIELD'),
            ],
            'CREATED_BY' => [
                'data_type' => 'integer',
                'required' => true,
                'title' => Loc::getMessage('RESOURCE_ENTITY_CREATED_BY_FIELD'),
            ],
            'DATE_CREATE' => [
                'data_type' => 'datetime',
                'title' => Loc::getMessage('RESOURCE_ENTITY_DATE_CREATE_FIELD'),
            ],
            'TIMESTAMP_X' => [
                'data_type' => 'datetime',
                'title' => Loc::getMessage('RESOURCE_ENTITY_TIMESTAMP_X_FIELD'),
            ],
            'SERVICE_NAME' => [
                'data_type' => 'string',
                'validation' => [__CLASS__, 'validateServiceName'],
                'title' => Loc::getMessage('RESOURCE_ENTITY_SERVICE_NAME_FIELD'),
            ],
        ];
    }

    /**
     * Returns validators for CAL_TYPE field.
     *
     * @return array
     */
    public static function validateCalType()
    {
        return [
            new Main\Entity\Validator\Length(null, 100),
        ];
    }

    /**
     * Returns validators for PARENT_TYPE field.
     *
     * @return array
     */
    public static function validateParentType()
    {
        return [
            new Main\Entity\Validator\Length(null, 100),
        ];
    }

    /**
     * Returns validators for SKIP_TIME field.
     *
     * @return array
     */
    public static function validateSkipTime()
    {
        return [
            new Main\Entity\Validator\Length(null, 1),
        ];
    }

    /**
     * Returns validators for TZ_FROM field.
     *
     * @return array
     */
    public static function validateTzFrom()
    {
        return [
            new Main\Entity\Validator\Length(null, 50),
        ];
    }

    /**
     * Returns validators for TZ_TO field.
     *
     * @return array
     */
    public static function validateTzTo()
    {
        return [
            new Main\Entity\Validator\Length(null, 50),
        ];
    }

    /**
     * Returns validators for SERVICE_NAME field.
     *
     * @return array
     */
    public static function validateServiceName()
    {
        return [
            new Main\Entity\Validator\Length(null, 200),
        ];
    }
}
