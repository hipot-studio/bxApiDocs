<?php

namespace Bitrix\StaffTrack\Internal\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\CryptoField;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\Type\DateTime;

/**
 * Class CheckInByDayTable
 *
 * Fields:
 * <ul>
 * <li> ID int mandatory
 * <li> USER_ID int mandatory
 * <li> DATE_CREATE datetime mandatory
 * <li> DATE_END datetime mandatory
 * <li> GEO_DATA text optional
 * <li> CNT int optional
 * </ul>
 *
 * @package Bitrix\StaffTrack\Internal\Model
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_CheckInByDay_Query query()
 * @method static EO_CheckInByDay_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_CheckInByDay_Result getById($id)
 * @method static EO_CheckInByDay_Result getList(array $parameters = [])
 * @method static EO_CheckInByDay_Entity getEntity()
 * @method static \Bitrix\StaffTrack\Internal\Model\EO_CheckInByDay createObject($setDefaultValues = true)
 * @method static \Bitrix\StaffTrack\Internal\Model\EO_CheckInByDay_Collection createCollection()
 * @method static \Bitrix\StaffTrack\Internal\Model\EO_CheckInByDay wakeUpObject($row)
 * @method static \Bitrix\StaffTrack\Internal\Model\EO_CheckInByDay_Collection wakeUpCollection($rows)
 */
class CheckInByDayTable extends DataManager
{
	public static function getTableName()
	{
		return 'b_stafftrack_check_in_by_day';
	}

	public static function getMap()
	{
		return [
			new IntegerField(
				'ID',
				[
					'primary' => true,
					'autocomplete' => true,
				],
			),
			new IntegerField(
				'USER_ID',
				[
					'required' => true,
				],
			),
			new DatetimeField(
				'DATE_CREATE',
				[
					'required' => true,
					'default_value' => function () {
						return new DateTime();
					},
				],
			),
			new DatetimeField(
				'DATE_END',
				[
					'required' => true,
					'default_value' => function () {
						return new DateTime();
					},
				],
			),
			new CryptoField(
				'GEO_DATA',
				['crypto_enabled' => CryptoField::cryptoAvailable()],
			),
			new IntegerField(
				'CNT',
				[
					'default_value' => 0,
				],
			),
		];
	}
}
