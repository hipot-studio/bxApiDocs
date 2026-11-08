<?php

namespace Bitrix\StaffTrack\Internal\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;

/**
 * Class OfficePresenceTable
 *
 * One row per employee: the office they are attached to and two moments in time - when they were last
 * inside its geo zone and when they were last heard from at all. The employee is the primary key, so
 * the uniqueness of "one person - one row" is held by the schema itself rather than by a key beside a
 * surrogate identifier, the way the neighbouring OfficeEmployeeTable holds it.
 *
 * Neither "is in the zone now" nor the coordinates that earned the confirmation are stored: the first
 * is derived by comparing the two moments on a read, and the second is of no use to anybody here.
 *
 * No object class and no collection, unlike the neighbours of the domain: both the write and the read
 * go through flat arrays, and an object named OfficePresence beside the enum
 * {@see \Bitrix\StaffTrack\Dictionary\OfficePresence} would only be read as that enum.
 */
class OfficePresenceTable extends DataManager
{
	public static function getTableName()
	{
		return 'b_stafftrack_office_presence';
	}

	public static function getMap()
	{
		return [
			(new IntegerField('USER_ID'))
				->configurePrimary(true)
			,
			(new IntegerField('OFFICE_ID'))
				->configureRequired(true)
			,
			(new DatetimeField('IN_ZONE_DATE'))
			,
			(new DatetimeField('LAST_POINT_DATE'))
				->configureRequired(true)
			,
		];
	}
}
