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
use Bitrix\Main\ORM\Query\Join;

/**
 * Class FolderPinTable
 *
 * Fields:
 * <ul>
 * <li> ID int mandatory
 * <li> FOLDER_ID int mandatory
 * <li> CHAT_ID int mandatory
 * <li> PIN_SORT int mandatory default 0
 * <li> USER_ID int mandatory (denormalized from b_im_folder)
 * <li> FOLDER_PARENT_ID int mandatory default 0 (denormalized from b_im_folder)
 * </ul>
 *
 * Note: USER_ID and FOLDER_PARENT_ID are denormalized columns that duplicate
 * values from the corresponding b_im_folder row. They are populated by
 * FolderService (Task 1.3), not in this schema layer.
 *
 * @package Bitrix\Im
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_FolderPin_Query query()
 * @method static EO_FolderPin_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_FolderPin_Result getById($id)
 * @method static EO_FolderPin_Result getList(array $parameters = [])
 * @method static EO_FolderPin_Entity getEntity()
 * @method static \Bitrix\Im\Model\EO_FolderPin createObject($setDefaultValues = true)
 * @method static \Bitrix\Im\Model\EO_FolderPin_Collection createCollection()
 * @method static \Bitrix\Im\Model\EO_FolderPin wakeUpObject($row)
 * @method static \Bitrix\Im\Model\EO_FolderPin_Collection wakeUpCollection($rows)
 */
class FolderPinTable extends DataManager
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
		return 'b_im_folder_pin';
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

			(new IntegerField('CHAT_ID'))
				->configureRequired(),

			(new IntegerField('PIN_SORT'))
				->configureRequired()
				->configureDefaultValue(0),

			(new IntegerField('USER_ID'))
				->configureRequired(),

			(new IntegerField('FOLDER_PARENT_ID'))
				->configureRequired()
				->configureDefaultValue(0),

			'FOLDER' => (new Reference(
				'FOLDER',
				FolderTable::class,
				Join::on('this.FOLDER_ID', 'ref.ID')
			))->configureJoinType(Join::TYPE_INNER),

			'CHAT' => (new Reference(
				'CHAT',
				ChatTable::class,
				Join::on('this.CHAT_ID', 'ref.ID')
			))->configureJoinType(Join::TYPE_INNER),
		];
	}
}
