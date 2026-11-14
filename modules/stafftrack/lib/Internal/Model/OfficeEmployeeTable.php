<?php

namespace Bitrix\StaffTrack\Internal\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\Relations\CascadePolicy;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserTable;

/**
 * Class OfficeEmployeeTable
 *
 * One row per employee assigned to an office. An employee belongs to exactly one office: the unique key
 * on USER_ID alone holds that, and assigning the same person elsewhere moves the row instead of adding
 * one. USER points at the record of the person, which is how a read tells a working employee from a
 * dismissed one without a join spelled out in every query.
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_OfficeEmployee_Query query()
 * @method static EO_OfficeEmployee_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_OfficeEmployee_Result getById($id)
 * @method static EO_OfficeEmployee_Result getList(array $parameters = [])
 * @method static EO_OfficeEmployee_Entity getEntity()
 * @method static \Bitrix\StaffTrack\Internal\Model\OfficeEmployee createObject($setDefaultValues = true)
 * @method static \Bitrix\StaffTrack\Internal\Model\OfficeEmployeeCollection createCollection()
 * @method static \Bitrix\StaffTrack\Internal\Model\OfficeEmployee wakeUpObject($row)
 * @method static \Bitrix\StaffTrack\Internal\Model\OfficeEmployeeCollection wakeUpCollection($rows)
 */
class OfficeEmployeeTable extends DataManager
{
	use DeleteByFilterTrait;

	public static function getTableName()
	{
		return 'b_stafftrack_office_employee';
	}

	public static function getObjectClass(): string
	{
		return OfficeEmployee::class;
	}

	public static function getCollectionClass(): string
	{
		return OfficeEmployeeCollection::class;
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
			(new IntegerField('CREATED_BY'))
				->configureRequired(true)
			,
			(new DatetimeField('DATE_CREATE'))
				->configureDefaultValue(static fn() => new DateTime())
			,
			(new Reference(
				'USER',
				UserTable::class,
				Join::on('this.USER_ID', 'ref.ID')
			))
				->configureJoinType(Join::TYPE_LEFT)
				->configureCascadeDeletePolicy(CascadePolicy::NO_ACTION)
			,
		];
	}
}
