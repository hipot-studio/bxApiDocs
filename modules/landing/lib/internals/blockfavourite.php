<?php
namespace Bitrix\Landing\Internals;

use \Bitrix\Main\Entity;
use \Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/**
 * Class BlockFavouriteTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_BlockFavourite_Query query()
 * @method static EO_BlockFavourite_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_BlockFavourite_Result getById($id)
 * @method static EO_BlockFavourite_Result getList(array $parameters = [])
 * @method static EO_BlockFavourite_Entity getEntity()
 * @method static \Bitrix\Landing\Internals\EO_BlockFavourite createObject($setDefaultValues = true)
 * @method static \Bitrix\Landing\Internals\EO_BlockFavourite_Collection createCollection()
 * @method static \Bitrix\Landing\Internals\EO_BlockFavourite wakeUpObject($row)
 * @method static \Bitrix\Landing\Internals\EO_BlockFavourite_Collection wakeUpCollection($rows)
 */
class BlockFavouriteTable extends Entity\DataManager
{
	/**
	 * Returns DB table name for entity.
	 *
	 * @return string
	 */
	public static function getTableName(): string
	{
		return 'b_landing_block_favourite';
	}

	/**
	 * Returns entity map definition.
	 *
	 * @return array
	 */
	public static function getMap(): array
	{
		return array(
			'ID' => new Entity\IntegerField('ID', array(
				'title' => 'ID',
				'primary' => true,
				'autocomplete' => true,
			)),
			'USER_ID' => new Entity\IntegerField('USER_ID', array(
				'title' => Loc::getMessage('LANDING_TABLE_BLOCK_FAVOURITE_FIELD_USER_ID'),
				'required' => true
			)),
			'CODE' => new Entity\StringField('CODE', array(
				'title' => Loc::getMessage('LANDING_TABLE_BLOCK_FAVOURITE_FIELD_CODE'),
				'required' => true
			)),
			'DATE_CREATE' => new Entity\DatetimeField('DATE_CREATE', array(
				'title' => Loc::getMessage('LANDING_TABLE_BLOCK_FAVOURITE_FIELD_DATE_CREATE')
			))
		);
	}
}
