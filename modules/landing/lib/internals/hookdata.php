<?php

namespace Bitrix\Landing\Internals;

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields;

Loc::loadMessages(__FILE__);

/**
 * Class HookDataTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_HookData_Query query()
 * @method static EO_HookData_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_HookData_Result getById($id)
 * @method static EO_HookData_Result getList(array $parameters = [])
 * @method static EO_HookData_Entity getEntity()
 * @method static \Bitrix\Landing\Internals\EO_HookData createObject($setDefaultValues = true)
 * @method static \Bitrix\Landing\Internals\EO_HookData_Collection createCollection()
 * @method static \Bitrix\Landing\Internals\EO_HookData wakeUpObject($row)
 * @method static \Bitrix\Landing\Internals\EO_HookData_Collection wakeUpCollection($rows)
 */
class HookDataTable extends DataManager
{
	/**
	 * Returns DB table name for entity.
	 * @return string
	 */
	public static function getTableName()
	{
		return 'b_landing_hook_data';
	}

	/**
	 * Returns entity map definition.
	 * @return array
	 */
	public static function getMap()
	{
		return [
			'ID' => new Fields\IntegerField('ID', [
				'primary' => true,
				'autocomplete' => true,
				'title' => 'ID',
			]),
			'ENTITY_ID' => new Fields\IntegerField('ENTITY_ID', [
				'title' => Loc::getMessage('LANDING_TABLE_FIELD_ENTITY_ID'),
				'required' => true,
			]),
			'ENTITY_TYPE' => new Fields\StringField('ENTITY_TYPE', [
				'title' => Loc::getMessage('LANDING_TABLE_FIELD_ENTITY_TYPE'),
				'required' => true,
			]),
			'HOOK' => new Fields\StringField('HOOK', [
				'title' => Loc::getMessage('LANDING_TABLE_FIELD_HOOK'),
				'required' => true,
			]),
			'CODE' => new Fields\StringField('CODE', [
				'title' => Loc::getMessage('LANDING_TABLE_FIELD_CODE'),
				'required' => true,
			]),
			'VALUE' => new Fields\StringField('VALUE', [
				'title' => Loc::getMessage('LANDING_TABLE_FIELD_VALUE'),
				'save_data_modification' => ['\Bitrix\Main\Text\Emoji', 'getSaveModificator'],
				'fetch_data_modification' => ['\Bitrix\Main\Text\Emoji', 'getFetchModificator'],
			]),
			'PUBLIC' => new Fields\StringField('PUBLIC', [
				'title' => Loc::getMessage('LANDING_TABLE_FIELD_PUBLIC'),
				'default_value' => 'N',
			]),
		];
	}
}
