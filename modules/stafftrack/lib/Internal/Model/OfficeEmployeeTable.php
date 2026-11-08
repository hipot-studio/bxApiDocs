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
