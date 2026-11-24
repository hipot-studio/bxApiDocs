<?php

namespace Bitrix\Im\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;

/**
 * Class FileUploadSessionTable
 *
 * Fields:
 * <ul>
 * <li> ID int mandatory
 * <li> GUID string(36) mandatory
 * <li> CHAT_ID int mandatory
 * <li> CREATED_BY int mandatory
 * <li> FILE_NAME string(255) mandatory
 * <li> FILE_SIZE int mandatory
 * <li> MIME_TYPE string(255) optional
 * <li> UPLOAD_TOKEN string optional
 * <li> DISK_FILE_ID int optional
 * <li> MESSAGE_ID int optional
 * <li> CREATED_AT datetime mandatory
 * <li> EXPIRES_AT datetime mandatory
 * </ul>
 *
 * @package Bitrix\Im
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_FileUploadSession_Query query()
 * @method static EO_FileUploadSession_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_FileUploadSession_Result getById($id)
 * @method static EO_FileUploadSession_Result getList(array $parameters = [])
 * @method static EO_FileUploadSession_Entity getEntity()
 * @method static \Bitrix\Im\Model\EO_FileUploadSession createObject($setDefaultValues = true)
 * @method static \Bitrix\Im\Model\EO_FileUploadSession_Collection createCollection()
 * @method static \Bitrix\Im\Model\EO_FileUploadSession wakeUpObject($row)
 * @method static \Bitrix\Im\Model\EO_FileUploadSession_Collection wakeUpCollection($rows)
 */
class FileUploadSessionTable extends DataManager
{
	// The cleanup of the door removes a whole pack of expired sessions in one statement.
	use DeleteByFilterTrait;

	/**
	 * Returns DB table name for entity.
	 *
	 * @return string
	 */
	public static function getTableName(): string
	{
		return 'b_im_file_upload_session';
	}

	/**
	 * Returns entity map definition.
	 *
	 * @return array
	 */
	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),

			(new StringField('GUID'))
				->configureRequired()
				->configureUnique(true)
				->configureSize(36),

			(new IntegerField('CHAT_ID'))
				->configureRequired(),

			(new IntegerField('CREATED_BY'))
				->configureRequired(),

			(new StringField('FILE_NAME'))
				->configureRequired()
				->configureSize(255),

			(new IntegerField('FILE_SIZE'))
				->configureRequired()
				->configureSize(8),

			(new StringField('MIME_TYPE'))
				->configureSize(255),

			// Token of the ui uploader circuit. Never leaves the server: the caller gets GUID only.
			new TextField('UPLOAD_TOKEN'),

			new IntegerField('DISK_FILE_ID'),

			new IntegerField('MESSAGE_ID'),

			(new DatetimeField('CREATED_AT'))
				->configureRequired(),

			(new DatetimeField('EXPIRES_AT'))
				->configureRequired(),
		];
	}
}
