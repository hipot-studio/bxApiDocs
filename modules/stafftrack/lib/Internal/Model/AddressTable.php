<?php

namespace Bitrix\StaffTrack\Internal\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\CryptoField;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\Type\DateTime;

/**
 * Class AddressTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_Address_Query query()
 * @method static EO_Address_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_Address_Result getById($id)
 * @method static EO_Address_Result getList(array $parameters = [])
 * @method static EO_Address_Entity getEntity()
 * @method static \Bitrix\StaffTrack\Internal\Model\EO_Address createObject($setDefaultValues = true)
 * @method static \Bitrix\StaffTrack\Internal\Model\EO_Address_Collection createCollection()
 * @method static \Bitrix\StaffTrack\Internal\Model\EO_Address wakeUpObject($row)
 * @method static \Bitrix\StaffTrack\Internal\Model\EO_Address_Collection wakeUpCollection($rows)
 */
class AddressTable extends DataManager
{
	use DeleteByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_stafftrack_address';
	}

	public static function getMap(): array
	{
		return [
			(new StringField('GEOHASH_KEY'))
				->configureSize(64)
				->configurePrimary(true)
			,
			(new CryptoField('ADDRESS', ['crypto_enabled' => CryptoField::cryptoAvailable()]))
				->configureNullable(true)
			,
			(new IntegerField('STATUS'))
				->configureDefaultValue(0)
			,
			(new DatetimeField('DATE_CREATE'))
				->configureDefaultValue(static fn() => new DateTime())
			,
			(new DatetimeField('DATE_RESOLVE'))
				->configureNullable(true)
			,
			(new DatetimeField('LAST_USED'))
				->configureDefaultValue(static fn() => new DateTime())
			,
		];
	}
}
