<?php

namespace Bitrix\StaffTrack\Internal\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\Type\DateTime;

/**
 * Class OfficePhotoTable
 *
 * One row per photo of an office. SORT only orders the photos of one office relative to each other:
 * values may repeat and may leave gaps, so the identifier settles the order of a tie.
 */
class OfficePhotoTable extends DataManager
{
	use DeleteByFilterTrait;

	public static function getTableName()
	{
		return 'b_stafftrack_office_photo';
	}

	public static function getObjectClass(): string
	{
		return OfficePhoto::class;
	}

	public static function getCollectionClass(): string
	{
		return OfficePhotoCollection::class;
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
			(new IntegerField('FILE_ID'))
				->configureRequired(true)
			,
			(new IntegerField('SORT'))
				->configureRequired(true)
			,
			(new IntegerField('CREATED_BY'))
				->configureRequired(true)
			,
			(new DatetimeField('DATE_CREATE'))
				->configureDefaultValue(static fn() => new DateTime())
			,
		];
	}
}
