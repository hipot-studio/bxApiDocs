<?php

namespace Bitrix\Main\Security\W\Rules;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;

/**
 * Class RuleRecordTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_RuleRecord_Query query()
 * @method static EO_RuleRecord_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_RuleRecord_Result getById($id)
 * @method static EO_RuleRecord_Result getList(array $parameters = [])
 * @method static EO_RuleRecord_Entity getEntity()
 * @method static \Bitrix\Main\Security\W\Rules\EO_RuleRecord createObject($setDefaultValues = true)
 * @method static \Bitrix\Main\Security\W\Rules\EO_RuleRecord_Collection createCollection()
 * @method static \Bitrix\Main\Security\W\Rules\EO_RuleRecord wakeUpObject($row)
 * @method static \Bitrix\Main\Security\W\Rules\EO_RuleRecord_Collection wakeUpCollection($rows)
 */
class RuleRecordTable extends DataManager
{
	public static function getTableName()
	{
		return 'b_sec_wwall_rules';
	}

	public static function getMap()
	{
		return [
			(new IntegerField('ID'))
				->configureAutocomplete()
				->configurePrimary(),
			(new TextField('DATA')),
			new StringField('MODULE'),
			new StringField('MODULE_VERSION'),
		];
	}
}