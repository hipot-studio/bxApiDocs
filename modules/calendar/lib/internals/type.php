<?php

namespace Bitrix\Calendar\Internals;

use Bitrix\Main;
use Bitrix\Main\Localization\Loc;

/**
 * Class TypeTable.
 *
 * Fields:
 * <ul>
 * <li> XML_ID string(255) mandatory
 * <li> NAME string(255) optional
 * <li> DESCRIPTION string optional
 * <li> EXTERNAL_ID string(100) optional
 * <li> ACTIVE bool optional default 'Y'
 * </ul>
 *
 * @method static EO_Type_Query                                 query()
 * @method static EO_Type_Result                                getByPrimary($primary, array $parameters = [])
 * @method static EO_Type_Result                                getById($id)
 * @method static EO_Type_Result                                getList(array $parameters = [])
 * @method static EO_Type_Entity                                getEntity()
 * @method static \Bitrix\Calendar\Internals\EO_Type            createObject($setDefaultValues = true)
 * @method static \Bitrix\Calendar\Internals\EO_Type_Collection createCollection()
 * @method static \Bitrix\Calendar\Internals\EO_Type            wakeUpObject($row)
 * @method static \Bitrix\Calendar\Internals\EO_Type_Collection wakeUpCollection($rows)
 */
class type extends Main\Entity\DataManager
{
    /**
     * Returns DB table name for entity.
     *
     * @return string
     */
    public static function getTableName()
    {
        return 'b_calendar_type';
    }

    /**
     * Returns entity map definition.
     *
     * @return array
     */
    public static function getMap()
    {
        return [
            'XML_ID' => [
                'data_type' => 'string',
                'primary' => true,
                'validation' => [__CLASS__, 'validateXmlId'],
                'title' => Loc::getMessage('TYPE_ENTITY_XML_ID_FIELD'),
            ],
            'NAME' => [
                'data_type' => 'string',
                'validation' => [__CLASS__, 'validateName'],
                'title' => Loc::getMessage('TYPE_ENTITY_NAME_FIELD'),
            ],
            'DESCRIPTION' => [
                'data_type' => 'text',
                'title' => Loc::getMessage('TYPE_ENTITY_DESCRIPTION_FIELD'),
            ],
            'EXTERNAL_ID' => [
                'data_type' => 'string',
                'validation' => [__CLASS__, 'validateExternalId'],
                'title' => Loc::getMessage('TYPE_ENTITY_EXTERNAL_ID_FIELD'),
            ],
            'ACTIVE' => [
                'data_type' => 'boolean',
                'values' => ['N', 'Y'],
                'title' => Loc::getMessage('TYPE_ENTITY_ACTIVE_FIELD'),
            ],
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
            new Main\Entity\Validator\Length(null, 255),
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
}
