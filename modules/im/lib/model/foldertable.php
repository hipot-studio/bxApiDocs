<?php

namespace Bitrix\Im\Model;

use Bitrix\Im\V2\Common\DeleteTrait;
use Bitrix\Im\V2\Common\MultiplyInsertTrait;
use Bitrix\Im\V2\Common\UpdateByFilterTrait;
use Bitrix\Im\V2\Folder\FolderType;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Data\Internal\MergeTrait;
use Bitrix\Main\ORM\Fields\EnumField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\Text\Emoji;

/**
 * Class FolderTable
 *
 * Fields:
 * <ul>
 * <li> ID int mandatory
 * <li> USER_ID int mandatory
 * <li> PARENT_CHAT_ID int mandatory default 0
 * <li> TYPE enum mandatory default 'personal' (see {@see \Bitrix\Im\V2\Folder\FolderType})
 * <li> CODE string(50) optional
 * <li> TITLE string(255) optional
 * <li> SORT int mandatory default 0
 * </ul>
 *
 * @package Bitrix\Im
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_Folder_Query query()
 * @method static EO_Folder_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_Folder_Result getById($id)
 * @method static EO_Folder_Result getList(array $parameters = [])
 * @method static EO_Folder_Entity getEntity()
 * @method static \Bitrix\Im\Model\EO_Folder createObject($setDefaultValues = true)
 * @method static \Bitrix\Im\Model\EO_Folder_Collection createCollection()
 * @method static \Bitrix\Im\Model\EO_Folder wakeUpObject($row)
 * @method static \Bitrix\Im\Model\EO_Folder_Collection wakeUpCollection($rows)
 */
class FolderTable extends DataManager
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
		return 'b_im_folder';
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

			(new IntegerField('USER_ID'))
				->configureRequired(),

			(new IntegerField('PARENT_CHAT_ID'))
				->configureRequired()
				->configureDefaultValue(0),

			(new EnumField('TYPE'))
				->configureValues(array_column(FolderType::cases(), 'value'))
				->configureRequired()
				->configureDefaultValue(FolderType::Personal->value),

			(new StringField('CODE'))
				->configureNullable()
				->configureSize(50),

			(new StringField('TITLE'))
				->configureNullable()
				->configureSize(255)
				->addSaveDataModifier([Emoji::class, 'encode'])
				->addFetchDataModifier([Emoji::class, 'decode']),

			(new IntegerField('SORT'))
				->configureRequired()
				->configureDefaultValue(0),
		];
	}
}
