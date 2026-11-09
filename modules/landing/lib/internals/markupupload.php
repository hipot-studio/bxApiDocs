<?php
declare(strict_types=1);

namespace Bitrix\Landing\Internals;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields;
use Bitrix\Main\Type\DateTime;

/**
 * Class MarkupUploadTable
 *
 * Registry of unfinished markup package uploads (DTO-03): who uploads, for which
 * target and which files and unfinished file parts are already in the buffer. Bytes live
 * in b_file with regular (not TEMP) bindings of the MARKUP_UPLOAD entity type; this table
 * holds identifiers only. The buffer lifetime is its own, counted by DATE_MODIFY.
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_MarkupUpload_Query query()
 * @method static EO_MarkupUpload_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_MarkupUpload_Result getById($id)
 * @method static EO_MarkupUpload_Result getList(array $parameters = [])
 * @method static EO_MarkupUpload_Entity getEntity()
 * @method static \Bitrix\Landing\Internals\EO_MarkupUpload createObject($setDefaultValues = true)
 * @method static \Bitrix\Landing\Internals\EO_MarkupUpload_Collection createCollection()
 * @method static \Bitrix\Landing\Internals\EO_MarkupUpload wakeUpObject($row)
 * @method static \Bitrix\Landing\Internals\EO_MarkupUpload_Collection wakeUpCollection($rows)
 */
class MarkupUploadTable extends DataManager
{
	/**
	 * @inheritdoc
	 */
	public static function getTableName(): string
	{
		return 'b_landing_markup_upload';
	}

	/**
	 * @inheritdoc
	 */
	public static function getMap(): array
	{
		return [
			(new Fields\IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,
			(new Fields\StringField('UID'))
				->configureRequired()
				->configureSize(32)
			,
			(new Fields\IntegerField('CREATED_BY_ID'))
				->configureRequired()
			,
			(new Fields\StringField('TARGET_MODE'))
				->configureRequired()
				->configureSize(10)
			,
			(new Fields\IntegerField('TARGET_ID'))
				->configureRequired()
			,
			(new Fields\ArrayField('FILES'))
				->configureSerializationJson()
				->configureNullable()
				->configureDefaultValue(null)
			,
			(new Fields\DatetimeField('DATE_CREATE'))
				->configureRequired()
				->configureDefaultValue(function ()
				{
					return new DateTime();
				})
			,
			(new Fields\DatetimeField('DATE_MODIFY'))
				->configureRequired()
				->configureDefaultValue(function ()
				{
					return new DateTime();
				})
			,
		];
	}
}
