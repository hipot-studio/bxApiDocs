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
 * Class FolderChatTable
 *
 * Fields:
 * <ul>
 * <li> ID int mandatory
 * <li> FOLDER_ID int mandatory
 * <li> CHAT_ID int mandatory
 * </ul>
 *
 * @package Bitrix\Im
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_FolderChat_Query query()
 * @method static EO_FolderChat_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_FolderChat_Result getById($id)
 * @method static EO_FolderChat_Result getList(array $parameters = [])
 * @method static EO_FolderChat_Entity getEntity()
 * @method static \Bitrix\Im\Model\EO_FolderChat createObject($setDefaultValues = true)
 * @method static \Bitrix\Im\Model\EO_FolderChat_Collection createCollection()
 * @method static \Bitrix\Im\Model\EO_FolderChat wakeUpObject($row)
 * @method static \Bitrix\Im\Model\EO_FolderChat_Collection wakeUpCollection($rows)
 */
class FolderChatTable extends DataManager
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
		return 'b_im_folder_chat';
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
