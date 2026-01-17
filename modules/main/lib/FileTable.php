<?php

namespace Bitrix\Main;

use Bitrix\Main\ORM\Fields;
use Bitrix\Main\ORM\Query;
use Bitrix\Main\File\Internal;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;

/**
 * Class FileTable
 *
 * Fields:
 * <ul>
 * <li> ID int mandatory
 * <li> TIMESTAMP_X datetime mandatory default 'CURRENT_TIMESTAMP'
 * <li> MODULE_ID string(50) optional
 * <li> HEIGHT int optional
 * <li> WIDTH int optional
 * <li> FILE_SIZE int optional
 * <li> CONTENT_TYPE string(255) optional default 'IMAGE'
 * <li> SUBDIR string(255) optional
 * <li> FILE_NAME string(255) mandatory
 * <li> ORIGINAL_NAME string(255) optional
 * <li> DESCRIPTION string(255) optional
 * <li> HANDLER_ID string(50) optional
 * <li> EXTERNAL_ID string(50) optional
 * </ul>
 *
 * @package Bitrix\File
 * @internal
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_File_Query query()
 * @method static EO_File_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_File_Result getById($id)
 * @method static EO_File_Result getList(array $parameters = [])
 * @method static EO_File_Entity getEntity()
 * @method static \Bitrix\Main\EO_File createObject($setDefaultValues = true)
 * @method static \Bitrix\Main\EO_File_Collection createCollection()
 * @method static \Bitrix\Main\EO_File wakeUpObject($row)
 * @method static \Bitrix\Main\EO_File_Collection wakeUpCollection($rows)
 */
class FileTable extends DataManager
{
	/**
	 * Returns DB table name for entity.
	 *
	 * @return string
	 */
	public static function getTableName()
	{
		return 'b_file';
	}

	/**
	 * Returns entity map definition.
	 *
	 * @return array
	 */
	public static function getMap()
	{
		return array(
			'ID' => new Fields\IntegerField('ID', array(
				'primary' => true,
				'autocomplete' => true,
			)),
			'TIMESTAMP_X' => new Fields\DatetimeField('TIMESTAMP_X', array(
				'default_value' => new Type\DateTime
			)),
			'MODULE_ID' => new Fields\StringField('MODULE_ID', array(
				'validation' => array(__CLASS__, 'validateModuleId'),
			)),
			'HEIGHT' => new Fields\IntegerField('HEIGHT'),
			'WIDTH' => new Fields\IntegerField('WIDTH'),
			'FILE_SIZE' => new Fields\IntegerField('FILE_SIZE'),
			'CONTENT_TYPE' => new Fields\StringField('CONTENT_TYPE', array(
				'validation' => array(__CLASS__, 'validateContentType'),
			)),
			'SUBDIR' => new Fields\StringField('SUBDIR', array(
				'validation' => array(__CLASS__, 'validateSubdir'),
			)),
			'FILE_NAME' => new Fields\StringField('FILE_NAME', array(
				'validation' => array(__CLASS__, 'validateFileName'),
				'required' => true,
			)),
			'ORIGINAL_NAME' => new Fields\StringField('ORIGINAL_NAME', array(
				'validation' => array(__CLASS__, 'validateOriginalName'),
			)),
			'DESCRIPTION' => new Fields\StringField('DESCRIPTION', array(
				'validation' => array(__CLASS__, 'validateDescription'),
			)),
			'HANDLER_ID' => new Fields\StringField('HANDLER_ID', array(
				'validation' => array(__CLASS__, 'validateHandlerId'),
			)),
			'EXTERNAL_ID' => new Fields\StringField('EXTERNAL_ID', array(
				'validation' => array(__CLASS__, 'validateExternalId'),
			)),
			(new Fields\Relations\Reference(
				'HASH',
				Internal\FileHashTable::class,
				Query\Join::on('this.ID', 'ref.FILE_ID')
			))
				->configureJoinType(Query\Join::TYPE_LEFT),
		);
	}

	/**
	 * Returns validators for MODULE_ID field.
	 *
	 * @return array
	 */
	public static function validateModuleId()
	{
		return array(
			new LengthValidator(null, 50),
		);
	}

	/**
	 * Returns validators for CONTENT_TYPE field.
	 *
	 * @return array
	 */
	public static function validateContentType()
	{
		return array(
			new LengthValidator(null, 255),
		);
	}

	/**
	 * Returns validators for SUBDIR field.
	 *
	 * @return array
	 */
	public static function validateSubdir()
	{
		return array(
			new LengthValidator(null, 255),
		);
	}

	/**
	 * Returns validators for FILE_NAME field.
	 *
	 * @return array
	 */
	public static function validateFileName()
	{
		return array(
			new LengthValidator(null, 255),
		);
	}

	/**
	 * Returns validators for ORIGINAL_NAME field.
	 *
	 * @return array
	 */
	public static function validateOriginalName()
	{
		return array(
			new LengthValidator(null, 255),
		);
	}

	/**
	 * Returns validators for DESCRIPTION field.
	 *
	 * @return array
	 */
	public static function validateDescription()
	{
		return array(
			new LengthValidator(null, 255),
		);
	}

	/**
	 * Returns validators for HANDLER_ID field.
	 *
	 * @return array
	 */
	public static function validateHandlerId()
	{
		return array(
			new LengthValidator(null, 50),
		);
	}

	/**
	 * Returns validators for EXTERNAL_ID field.
	 *
	 * @return array
	 */
	public static function validateExternalId()
	{
		return array(
			new LengthValidator(null, 50),
		);
	}

	public static function add(array $data)
	{
		throw new NotImplementedException("Use CFile class.");
	}

	public static function update($primary, array $data)
	{
		throw new NotImplementedException("Use CFile class.");
	}

	public static function delete($primary)
	{
		throw new NotImplementedException("Use CFile class.");
	}
}
