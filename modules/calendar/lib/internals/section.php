<?php

namespace Bitrix\Calendar\Internals;

use Bitrix\Main;
use Bitrix\Main\Localization\Loc;

/**
 * Class SectionTable.
 *
 * Fields:
 * <ul>
 * <li> ID int mandatory
 * <li> NAME string(255) optional
 * <li> XML_ID string(100) optional
 * <li> EXTERNAL_ID string(100) optional
 * <li> ACTIVE bool optional default 'Y'
 * <li> DESCRIPTION string optional
 * <li> COLOR string(10) optional
 * <li> TEXT_COLOR string(10) optional
 * <li> EXPORT string(255) optional
 * <li> SORT int optional default 100
 * <li> CAL_TYPE string(100) optional
 * <li> OWNER_ID int optional
 * <li> CREATED_BY int mandatory
 * <li> PARENT_ID int optional
 * <li> DATE_CREATE datetime optional
 * <li> TIMESTAMP_X datetime optional
 * <li> DAV_EXCH_CAL string(255) optional
 * <li> DAV_EXCH_MOD string(255) optional
 * <li> CAL_DAV_CON string(255) optional
 * <li> CAL_DAV_CAL string(255) optional
 * <li> CAL_DAV_MOD string(255) optional
 * <li> IS_EXCHANGE string(1) optional
 * <li> GAPI_CALENDAR_ID string(255) optional
 * <li> SYNC_TOKEN string(255) optional
 * <li> PAGE_TOKEN string(255) optional
 * <li> EXTERNAL_TYPE string(20) optional
 * </ul>
 *
 * @method static EO_Section_Query                                 query()
 * @method static EO_Section_Result                                getByPrimary($primary, array $parameters = [])
 * @method static EO_Section_Result                                getById($id)
 * @method static EO_Section_Result                                getList(array $parameters = [])
 * @method static EO_Section_Entity                                getEntity()
 * @method static \Bitrix\Calendar\Internals\EO_Section            createObject($setDefaultValues = true)
 * @method static \Bitrix\Calendar\Internals\EO_Section_Collection createCollection()
 * @method static \Bitrix\Calendar\Internals\EO_Section            wakeUpObject($row)
 * @method static \Bitrix\Calendar\Internals\EO_Section_Collection wakeUpCollection($rows)
 */
class section extends Main\Entity\DataManager
{
    /**
     * Returns DB table name for entity.
     *
     * @return string
     */
    public static function getTableName()
    {
        return 'b_calendar_section';
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
                'title' => Loc::getMessage('SECTION_ENTITY_ID_FIELD'),
            ],
            'NAME' => [
                'data_type' => 'string',
                'validation' => [__CLASS__, 'validateName'],
                'title' => Loc::getMessage('SECTION_ENTITY_NAME_FIELD'),
            ],
            'XML_ID' => [
                'data_type' => 'string',
                'validation' => [__CLASS__, 'validateXmlId'],
                'title' => Loc::getMessage('SECTION_ENTITY_XML_ID_FIELD'),
            ],
            'EXTERNAL_ID' => [
                'data_type' => 'string',
                'validation' => [__CLASS__, 'validateExternalId'],
                'title' => Loc::getMessage('SECTION_ENTITY_EXTERNAL_ID_FIELD'),
            ],
            'ACTIVE' => [
                'data_type' => 'boolean',
                'values' => ['N', 'Y'],
                'title' => Loc::getMessage('SECTION_ENTITY_ACTIVE_FIELD'),
            ],
            'DESCRIPTION' => [
                'data_type' => 'text',
                'title' => Loc::getMessage('SECTION_ENTITY_DESCRIPTION_FIELD'),
            ],
            'COLOR' => [
                'data_type' => 'string',
                'validation' => [__CLASS__, 'validateColor'],
                'title' => Loc::getMessage('SECTION_ENTITY_COLOR_FIELD'),
            ],
            'TEXT_COLOR' => [
                'data_type' => 'string',
                'validation' => [__CLASS__, 'validateTextColor'],
                'title' => Loc::getMessage('SECTION_ENTITY_TEXT_COLOR_FIELD'),
            ],
            'EXPORT' => [
                'data_type' => 'string',
                'validation' => [__CLASS__, 'validateExport'],
                'title' => Loc::getMessage('SECTION_ENTITY_EXPORT_FIELD'),
            ],
            'SORT' => [
                'data_type' => 'integer',
                'title' => Loc::getMessage('SECTION_ENTITY_SORT_FIELD'),
            ],
            'CAL_TYPE' => [
                'data_type' => 'string',
                'validation' => [__CLASS__, 'validateCalType'],
                'title' => Loc::getMessage('SECTION_ENTITY_CAL_TYPE_FIELD'),
            ],
            'OWNER_ID' => [
                'data_type' => 'integer',
                'title' => Loc::getMessage('SECTION_ENTITY_OWNER_ID_FIELD'),
            ],
            'CREATED_BY' => [
                'data_type' => 'integer',
                'required' => true,
                'title' => Loc::getMessage('SECTION_ENTITY_CREATED_BY_FIELD'),
            ],
            'PARENT_ID' => [
                'data_type' => 'integer',
                'title' => Loc::getMessage('SECTION_ENTITY_PARENT_ID_FIELD'),
            ],
            'DATE_CREATE' => [
                'data_type' => 'datetime',
                'title' => Loc::getMessage('SECTION_ENTITY_DATE_CREATE_FIELD'),
            ],
            'TIMESTAMP_X' => [
                'data_type' => 'datetime',
                'title' => Loc::getMessage('SECTION_ENTITY_TIMESTAMP_X_FIELD'),
            ],
            'DAV_EXCH_CAL' => [
                'data_type' => 'string',
                'validation' => [__CLASS__, 'validateDavExchCal'],
                'title' => Loc::getMessage('SECTION_ENTITY_DAV_EXCH_CAL_FIELD'),
            ],
            'DAV_EXCH_MOD' => [
                'data_type' => 'string',
                'validation' => [__CLASS__, 'validateDavExchMod'],
                'title' => Loc::getMessage('SECTION_ENTITY_DAV_EXCH_MOD_FIELD'),
            ],
            'CAL_DAV_CON' => [
                'data_type' => 'string',
                'validation' => [__CLASS__, 'validateCalDavCon'],
                'title' => Loc::getMessage('SECTION_ENTITY_CAL_DAV_CON_FIELD'),
            ],
            'CAL_DAV_CAL' => [
                'data_type' => 'string',
                'validation' => [__CLASS__, 'validateCalDavCal'],
                'title' => Loc::getMessage('SECTION_ENTITY_CAL_DAV_CAL_FIELD'),
            ],
            'CAL_DAV_MOD' => [
                'data_type' => 'string',
                'validation' => [__CLASS__, 'validateCalDavMod'],
                'title' => Loc::getMessage('SECTION_ENTITY_CAL_DAV_MOD_FIELD'),
            ],
            'IS_EXCHANGE' => [
                'data_type' => 'string',
                'validation' => [__CLASS__, 'validateIsExchange'],
                'title' => Loc::getMessage('SECTION_ENTITY_IS_EXCHANGE_FIELD'),
            ],
            'GAPI_CALENDAR_ID' => [
                'data_type' => 'string',
                'validation' => [__CLASS__, 'validateGapiCalendarId'],
                'title' => Loc::getMessage('SECTION_ENTITY_GAPI_CALENDAR_ID_FIELD'),
            ],
            'SYNC_TOKEN' => [
                'data_type' => 'string',
                'validation' => [__CLASS__, 'validateSyncToken'],
                'title' => Loc::getMessage('SECTION_ENTITY_SYNC_TOKEN_FIELD'),
            ],
            'PAGE_TOKEN' => [
                'data_type' => 'string',
                'validation' => [__CLASS__, 'validatePageToken'],
                'title' => Loc::getMessage('SECTION_ENTITY_PAGE_TOKEN_FIELD'),
            ],
            'EXTERNAL_TYPE' => [
                'data_type' => 'string',
                'validation' => [__CLASS__, 'validateExternalType'],
                'title' => Loc::getMessage('SECTION_ENTITY_EXTERNAL_TYPE_FIELD'),
            ],
        ];
    }

    /**
     * Returns validators for NAME field.
     *
     * @return array
     */
    public static function validateName()
    {
        return [
            new Main\Entity\Validator\Length(null, 255),
        ];
    }

    /**
     * Returns validators for XML_ID field.
     *
     * @return array
     */
    public static function validateXmlId()
    {
        return [
            new Main\Entity\Validator\Length(null, 100),
        ];
    }

    /**
     * Returns validators for EXTERNAL_ID field.
     *
     * @return array
     */
    public static function validateExternalId()
    {
        return [
            new Main\Entity\Validator\Length(null, 100),
        ];
    }

    /**
     * Returns validators for COLOR field.
     *
     * @return array
     */
    public static function validateColor()
    {
        return [
            new Main\Entity\Validator\Length(null, 10),
        ];
    }

    /**
     * Returns validators for TEXT_COLOR field.
     *
     * @return array
     */
    public static function validateTextColor()
    {
        return [
            new Main\Entity\Validator\Length(null, 10),
        ];
    }

    /**
     * Returns validators for EXPORT field.
     *
     * @return array
     */
    public static function validateExport()
    {
        return [
            new Main\Entity\Validator\Length(null, 255),
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
     * Returns validators for DAV_EXCH_CAL field.
     *
     * @return array
     */
    public static function validateDavExchCal()
    {
        return [
            new Main\Entity\Validator\Length(null, 255),
        ];
    }

    /**
     * Returns validators for DAV_EXCH_MOD field.
     *
     * @return array
     */
    public static function validateDavExchMod()
    {
        return [
            new Main\Entity\Validator\Length(null, 255),
        ];
    }

    /**
     * Returns validators for CAL_DAV_CON field.
     *
     * @return array
     */
    public static function validateCalDavCon()
    {
        return [
            new Main\Entity\Validator\Length(null, 255),
        ];
    }

    /**
     * Returns validators for CAL_DAV_CAL field.
     *
     * @return array
     */
    public static function validateCalDavCal()
    {
        return [
            new Main\Entity\Validator\Length(null, 255),
        ];
    }

    /**
     * Returns validators for CAL_DAV_MOD field.
     *
     * @return array
     */
    public static function validateCalDavMod()
    {
        return [
            new Main\Entity\Validator\Length(null, 255),
        ];
    }

    /**
     * Returns validators for IS_EXCHANGE field.
     *
     * @return array
     */
    public static function validateIsExchange()
    {
        return [
            new Main\Entity\Validator\Length(null, 1),
        ];
    }

    /**
     * Returns validators for GAPI_CALENDAR_ID field.
     *
     * @return array
     */
    public static function validateGapiCalendarId()
    {
        return [
            new Main\Entity\Validator\Length(null, 255),
        ];
    }

    /**
     * Returns validators for SYNC_TOKEN field.
     *
     * @return array
     */
    public static function validateSyncToken()
    {
        return [
            new Main\Entity\Validator\Length(null, 100),
        ];
    }

    /**
     * @return Main\Entity\Validator\Length[]
     *
     * @throws Main\ArgumentTypeException
     */
    public static function validatePageToken()
    {
        return [
            new Main\Entity\Validator\Length(null, 100),
        ];
    }

    /**
     * Returns validators for SYNC_TOKEN field.
     *
     * @return array
     */
    public static function validateExternalType()
    {
        return [
            new Main\Entity\Validator\Length(null, 20),
        ];
    }
}
