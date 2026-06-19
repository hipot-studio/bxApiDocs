<?php

namespace Bitrix\Location\Model;

use Bitrix\Main;
use Bitrix\Main\ORM\Fields;

/**
 * Class StaticMapFileTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_StaticMapFile_Query query()
 * @method static EO_StaticMapFile_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_StaticMapFile_Result getById($id)
 * @method static EO_StaticMapFile_Result getList(array $parameters = [])
 * @method static EO_StaticMapFile_Entity getEntity()
 * @method static \Bitrix\Location\Model\EO_StaticMapFile createObject($setDefaultValues = true)
 * @method static \Bitrix\Location\Model\EO_StaticMapFile_Collection createCollection()
 * @method static \Bitrix\Location\Model\EO_StaticMapFile wakeUpObject($row)
 * @method static \Bitrix\Location\Model\EO_StaticMapFile_Collection wakeUpCollection($rows)
 */
class StaticMapFileTable extends Main\ORM\Data\DataManager
{
	use Main\ORM\Data\Internal\MergeTrait;

	public static function getTableName()
	{
		return 'b_location_static_map_file';
	}

	public static function getMap()
	{
		return array(
			(new Fields\StringField('HASH'))
				->addValidator(new Main\ORM\Fields\Validators\LengthValidator(1, 40))
				->configurePrimary(),
			new Fields\IntegerField('FILE_ID'),
		);
	}
}
