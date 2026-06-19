<?php

namespace Bitrix\StaffTrack\Internal\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DateField;
use Bitrix\Main\ORM\Fields\IntegerField;

/**
 * Class CheckInDailyStatsTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_CheckInDailyStats_Query query()
 * @method static EO_CheckInDailyStats_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_CheckInDailyStats_Result getById($id)
 * @method static EO_CheckInDailyStats_Result getList(array $parameters = [])
 * @method static EO_CheckInDailyStats_Entity getEntity()
 * @method static \Bitrix\StaffTrack\Internal\Model\EO_CheckInDailyStats createObject($setDefaultValues = true)
 * @method static \Bitrix\StaffTrack\Internal\Model\EO_CheckInDailyStats_Collection createCollection()
 * @method static \Bitrix\StaffTrack\Internal\Model\EO_CheckInDailyStats wakeUpObject($row)
 * @method static \Bitrix\StaffTrack\Internal\Model\EO_CheckInDailyStats_Collection wakeUpCollection($rows)
 */
class CheckInDailyStatsTable extends DataManager
{
	public static function getTableName()
	{
		return 'b_stafftrack_check_in_daily_stats';
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
			new DateField(
				'LOCAL_DATE',
				[
					'required' => true,
				],
			),
			new IntegerField(
				'CNT',
				[
					'default_value' => 0,
				],
			),
			new IntegerField(
				'HAS_OPEN_ENTER',
				[
					'default_value' => 0,
				],
			),
			new IntegerField(
				'LAST_CHECK_IN_ID',
				[
					'default_value' => 0,
				],
			),
		];
	}
}
