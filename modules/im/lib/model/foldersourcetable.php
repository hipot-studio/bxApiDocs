<?php

namespace Bitrix\Im\Model;

use Bitrix\Im\V2\Common\DeleteTrait;
use Bitrix\Im\V2\Common\MultiplyInsertTrait;
use Bitrix\Im\V2\Common\UpdateByFilterTrait;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Data\Internal\MergeTrait;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Query\Join;

/**
 * Class FolderSourceTable
 *
 * Fields:
 * <ul>
 * <li> ID int mandatory
 * <li> FOLDER_ID int mandatory
 * <li> USER_ID int mandatory
 * <li> SOURCE_FOLDER_CODE string(50) mandatory
 * </ul>
 *
 * @package Bitrix\Im
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_FolderSource_Query query()
 * @method static EO_FolderSource_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_FolderSource_Result getById($id)
 * @method static EO_FolderSource_Result getList(array $parameters = [])
 * @method static EO_FolderSource_Entity getEntity()
 * @method static \Bitrix\Im\Model\EO_FolderSource createObject($setDefaultValues = true)
 * @method static \Bitrix\Im\Model\EO_FolderSource_Collection createCollection()
 * @method static \Bitrix\Im\Model\EO_FolderSource wakeUpObject($row)
 * @method static \Bitrix\Im\Model\EO_FolderSource_Collection wakeUpCollection($rows)
 */
class FolderSourceTable extends DataManager
{
	use DeleteByFilterTrait;
	use DeleteTrait;
	use UpdateByFilterTrait;
	use MultiplyInsertTrait;
	use MergeTrait;

	/**
	 * Returns DB table name for entity.
	 *
	 * @return string
	 */
	public static function getTableName(): string
	{
		return 'b_im_folder_source';
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

			(new IntegerField('FOLDER_ID'))
				->configureRequired(),

			(new IntegerField('USER_ID'))
				->configureRequired(),

			(new StringField('SOURCE_FOLDER_CODE'))
				->configureRequired()
				->configureSize(50),

			'FOLDER' => (new Reference(
				'FOLDER',
				FolderTable::class,
				Join::on('this.FOLDER_ID', 'ref.ID')
			))->configureJoinType(Join::TYPE_INNER),
		];
	}
}
