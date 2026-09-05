<?php

namespace Bitrix\UI\FileUploader;

use Bitrix\Main\ORM\Data;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\Type\DateTime;

/**
 * Class TempFilePartTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_TempFilePart_Query query()
 * @method static EO_TempFilePart_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_TempFilePart_Result getById($id)
 * @method static EO_TempFilePart_Result getList(array $parameters = [])
 * @method static EO_TempFilePart_Entity getEntity()
 * @method static \Bitrix\UI\FileUploader\TempFilePart createObject($setDefaultValues = true)
 * @method static \Bitrix\UI\FileUploader\EO_TempFilePart_Collection createCollection()
 * @method static \Bitrix\UI\FileUploader\TempFilePart wakeUpObject($row)
 * @method static \Bitrix\UI\FileUploader\EO_TempFilePart_Collection wakeUpCollection($rows)
 */
class TempFilePartTable extends Data\DataManager
{
	use DeleteByFilterTrait;

	public static function getTableName()
	{
		return 'b_ui_file_uploader_temp_file_part';
	}

	public static function getObjectClass()
	{
		return TempFilePart::class;
	}

	public static function getMap()
	{
		return [
			(new Fields\IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,

			(new Fields\IntegerField('TEMP_FILE_ID'))
				->configureRequired()
			,

			(new Fields\IntegerField('PART_NO'))
				->configureRequired()
			,

			new Fields\StringField('ETAG'),

			(new Fields\DatetimeField('RECEIVED_AT'))
				->configureRequired()
				->configureDefaultValue(static function () {
					return new DateTime();
				})
			,

			(new Reference(
				'TEMP_FILE',
				TempFileTable::class,
				Join::on('this.TEMP_FILE_ID', 'ref.ID'),
				['join_type' => Join::TYPE_INNER]
			)),
		];
	}
}
