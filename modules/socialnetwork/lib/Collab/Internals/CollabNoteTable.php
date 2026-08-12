<?php

namespace Bitrix\Socialnetwork\Collab\Internals;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Data\Internal\MergeTrait;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\Type\DateTime;

/**
 * Class CollabNoteTable
 *
 * Binds a collab to exactly one note collection (knowledge base).
 *
 * Fields:
 * <ul>
 * <li> ID int mandatory
 * <li> COLLAB_ID int mandatory (unique)
 * <li> COLLECTION_ID int mandatory
 * <li> CREATED_BY int mandatory
 * <li> CREATED_AT datetime
 * </ul>
 *
 * @package Bitrix\Socialnetwork
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_CollabNote_Query query()
 * @method static EO_CollabNote_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_CollabNote_Result getById($id)
 * @method static EO_CollabNote_Result getList(array $parameters = [])
 * @method static EO_CollabNote_Entity getEntity()
 * @method static \Bitrix\Socialnetwork\Collab\Internals\EO_CollabNote createObject($setDefaultValues = true)
 * @method static \Bitrix\Socialnetwork\Collab\Internals\EO_CollabNote_Collection createCollection()
 * @method static \Bitrix\Socialnetwork\Collab\Internals\EO_CollabNote wakeUpObject($row)
 * @method static \Bitrix\Socialnetwork\Collab\Internals\EO_CollabNote_Collection wakeUpCollection($rows)
 */

class CollabNoteTable extends DataManager
{
	use MergeTrait;
	use DeleteByFilterTrait;

	/**
	 * Returns DB table name for entity.
	 *
	 * @return string
	 */
	public static function getTableName()
	{
		return 'b_sonet_collab_note';
	}

	/**
	 * Returns entity map definition.
	 *
	 * @return array
	 */
	public static function getMap()
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),

			(new IntegerField('COLLAB_ID'))
				->configureRequired(),

			(new IntegerField('COLLECTION_ID'))
				->configureRequired(),

			(new IntegerField('CREATED_BY'))
				->configureRequired(),

			(new DatetimeField('CREATED_AT'))
				->configureDefaultValue(static fn() => new DateTime()),
		];
	}
}
