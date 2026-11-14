<?php

namespace Bitrix\StaffTrack\Internal\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\Type\DateTime;

/**
 * Class OfficeAccessRequestTable
 *
 * One row per pair of an office and a requester: the current request to see the staff of the office
 * and, once approved, the granted access itself. The unique key on the pair holds "one request until the
 * decision"; a request repeated after a refusal or an annulment moves the same row back to pending under
 * the next revision. STATUS holds the values of {@see \Bitrix\StaffTrack\Dictionary\OfficeAccessRequestStatus}.
 *
 * No object class and no collection: the storage reads and writes flat arrays.
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_OfficeAccessRequest_Query query()
 * @method static EO_OfficeAccessRequest_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_OfficeAccessRequest_Result getById($id)
 * @method static EO_OfficeAccessRequest_Result getList(array $parameters = [])
 * @method static EO_OfficeAccessRequest_Entity getEntity()
 * @method static \Bitrix\StaffTrack\Internal\Model\EO_OfficeAccessRequest createObject($setDefaultValues = true)
 * @method static \Bitrix\StaffTrack\Internal\Model\EO_OfficeAccessRequest_Collection createCollection()
 * @method static \Bitrix\StaffTrack\Internal\Model\EO_OfficeAccessRequest wakeUpObject($row)
 * @method static \Bitrix\StaffTrack\Internal\Model\EO_OfficeAccessRequest_Collection wakeUpCollection($rows)
 */
class OfficeAccessRequestTable extends DataManager
{
	use DeleteByFilterTrait;

	public static function getTableName()
	{
		return 'b_stafftrack_office_access_request';
	}

	public static function getMap()
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary(true)
				->configureAutocomplete(true)
			,
			(new IntegerField('OFFICE_ID'))
				->configureRequired(true)
			,
			(new IntegerField('USER_ID'))
				->configureRequired(true)
			,
			(new StringField('STATUS'))
				->configureRequired(true)
				->configureSize(16)
			,
			(new IntegerField('REVISION'))
				->configureRequired(true)
				->configureDefaultValue(1)
			,
			(new DatetimeField('REQUESTED_AT'))
				->configureRequired(true)
				->configureDefaultValue(static fn() => new DateTime())
			,
			(new IntegerField('DECIDED_BY'))
				->configureNullable()
			,
			(new DatetimeField('DECIDED_AT'))
				->configureNullable()
			,
		];
	}
}
