<?php

namespace Bitrix\Landing\Internals;

use Bitrix\Main\Entity;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

class manifest extends Entity\DataManager
{
    /**
     * Returns DB table name for entity.
     *
     * @return string
     */
    public static function getTableName()
    {
        return 'b_landing_manifest';
    }

    /**
     * Returns entity map definition.
     *
     * @return array
     */
    public static function getMap()
    {
        return [
            'ID' => new Entity\IntegerField('ID', [
                'primary' => true,
                'autocomplete' => true,
                'title' => 'ID',
            ]),
            'CODE' => new Entity\StringField('CODE', [
                'title' => Loc::getMessage('LANDING_TABLE_FIELD_BLOCK_CODE'),
                'required' => true,
            ]),
            'MANIFEST' => new Entity\StringField('MANIFEST', [
                'title' => Loc::getMessage('LANDING_TABLE_FIELD_MANIFEST'),
                'serialized' => true,
                'required' => true,
            ]),
            'CONTENT' => new Entity\StringField('CONTENT', [
                'title' => Loc::getMessage('LANDING_TABLE_FIELD_CONTENT'),
                'required' => true,
            ]),
            'CREATED_BY_ID' => new Entity\IntegerField('CREATED_BY_ID', [
                'title' => Loc::getMessage('LANDING_TABLE_FIELD_CREATED_BY_ID'),
                'required' => true,
            ]),
            'MODIFIED_BY_ID' => new Entity\IntegerField('MODIFIED_BY_ID', [
                'title' => Loc::getMessage('LANDING_TABLE_FIELD_MODIFIED_BY_ID'),
                'required' => true,
            ]),
            'DATE_CREATE' => new Entity\DatetimeField('DATE_CREATE', [
                'title' => Loc::getMessage('LANDING_TABLE_FIELD_DATE_CREATE'),
                'required' => true,
            ]),
            'DATE_MODIFY' => new Entity\DatetimeField('DATE_MODIFY', [
                'title' => Loc::getMessage('LANDING_TABLE_FIELD_DATE_MODIFY'),
                'required' => true,
            ]),
        ];
    }
}
