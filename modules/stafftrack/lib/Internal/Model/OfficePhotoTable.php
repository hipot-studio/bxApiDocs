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
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_OfficePhoto_Query query()
 * @method static EO_OfficePhoto_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_OfficePhoto_Result getById($id)
 * @method static EO_OfficePhoto_Result getList(array $parameters = [])
 * @method static EO_OfficePhoto_Entity getEntity()
 * @method static \Bitrix\StaffTrack\Internal\Model\OfficePhoto createObject($setDefaultValues = true)
 * @method static \Bitrix\StaffTrack\Internal\Model\OfficePhotoCollection createCollection()
 * @method static \Bitrix\StaffTrack\Internal\Model\OfficePhoto wakeUpObject($row)
 * @method static \Bitrix\StaffTrack\Internal\Model\OfficePhotoCollection wakeUpCollection($rows)
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
