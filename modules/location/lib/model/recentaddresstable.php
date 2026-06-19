<?php

namespace Bitrix\Location\Model;

use Bitrix\Main;
use Bitrix\Main\ORM\Fields;

/**
 * Class RecentAddressTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_RecentAddress_Query query()
 * @method static EO_RecentAddress_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_RecentAddress_Result getById($id)
 * @method static EO_RecentAddress_Result getList(array $parameters = [])
 * @method static EO_RecentAddress_Entity getEntity()
 * @method static \Bitrix\Location\Model\EO_RecentAddress createObject($setDefaultValues = true)
 * @method static \Bitrix\Location\Model\EO_RecentAddress_Collection createCollection()
 * @method static \Bitrix\Location\Model\EO_RecentAddress wakeUpObject($row)
 * @method static \Bitrix\Location\Model\EO_RecentAddress_Collection wakeUpCollection($rows)
 */
class RecentAddressTable extends Main\ORM\Data\DataManager
{
	public static function getTableName()
	{
		return 'b_location_recent_address';
	}

	public static function getMap()
	{
		return array(
			(new Fields\IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),
			new Fields\IntegerField('USER_ID'),
			new Fields\TextField('ADDRESS'),
			new Fields\DatetimeField('USED_AT', [
				'default_value' => static fn() => new Main\Type\DateTime(),
			]),
		);
	}
}
