<?php

namespace Bitrix\Location\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;
use Bitrix\Main\ORM\Fields;

/**
 * Class AreaTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_Area_Query query()
 * @method static EO_Area_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_Area_Result getById($id)
 * @method static EO_Area_Result getList(array $parameters = [])
 * @method static EO_Area_Entity getEntity()
 * @method static \Bitrix\Location\Model\EO_Area createObject($setDefaultValues = true)
 * @method static \Bitrix\Location\Model\EO_Area_Collection createCollection()
 * @method static \Bitrix\Location\Model\EO_Area wakeUpObject($row)
 * @method static \Bitrix\Location\Model\EO_Area_Collection wakeUpCollection($rows)
 */
class AreaTable extends DataManager
{
	/**
	 * @inheritDoc
	 */
	public static function getTableName()
	{
		return 'b_location_area';
	}

	/**
	 * @inheritDoc
	 */
	public static function getMap()
	{
		return [
			(new Fields\IntegerField('ID'))
				->configurePrimary(true)
				->configureAutocomplete(true),
			(new Fields\StringField('TYPE'))
				->configureRequired(true)
				->addValidator(new LengthValidator(1, 255)),
			(new Fields\StringField('CODE'))
				->addValidator(new LengthValidator(null, 255)),
			(new Fields\IntegerField('SORT'))
				->configureDefaultValue(100),
			(new Fields\StringField('GEOMETRY'))
				->configureRequired(true),
		];
	}
}
