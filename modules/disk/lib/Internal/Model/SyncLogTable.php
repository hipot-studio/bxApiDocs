<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\BooleanField;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;
use Bitrix\Main\SystemException;

/**
 * Class SyncLogTable
 *
 * A record of the journal of changes over real objects. Two shapes share the table.
 *
 * A change of an object carries an operation and the fields it touched. A change of access carries no
 * operation at all - the operation belongs to the records that report the state of an object, while this
 * one reports a boundary of visibility - and instead carries a scope and whether the descendants of the
 * scope are affected.
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_SyncLog_Query query()
 * @method static EO_SyncLog_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_SyncLog_Result getById($id)
 * @method static EO_SyncLog_Result getList(array $parameters = [])
 * @method static EO_SyncLog_Entity getEntity()
 * @method static \Bitrix\Disk\Internal\Model\EO_SyncLog createObject($setDefaultValues = true)
 * @method static \Bitrix\Disk\Internal\Model\EO_SyncLog_Collection createCollection()
 * @method static \Bitrix\Disk\Internal\Model\EO_SyncLog wakeUpObject($row)
 * @method static \Bitrix\Disk\Internal\Model\EO_SyncLog_Collection wakeUpCollection($rows)
 */
class SyncLogTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_disk_sync_log';
	}

	/**
	 * @return array
	 * @throws SystemException
	 */
	public static function getMap(): array
	{
		return [
			// A wide column, and the size has to be said out loud: an integer field declared without
			// it is a four-byte one, and the platform clamps to that range both what it writes and
			// what it puts into a condition - so past the limit of a signed int the journal would
			// answer every read of a position with the same last record instead of failing.
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
				->configureSize(8)
			,
			(new StringField('CHANGE_TYPE'))
				->configureRequired()
				->configureSize(32)
			,
			/**
			 * Null belongs to a change of access, which has no operation of its own.
			 */
			(new StringField('OPERATION'))
				->configureNullable()
				->configureSize(16)
			,
			(new StringField('RESOURCE_TYPE'))
				->configureNullable()
				->configureSize(16)
			,
			/**
			 * Null only when the whole access of a user narrowed and the change cannot be tied to a
			 * resource.
			 */
			(new IntegerField('RESOURCE_ID'))
				->configureNullable()
			,
			(new IntegerField('AUTHOR_ID'))
				->configureNullable()
			,
			/**
			 * Neither required nor defaulted here on purpose: the database stamps the time, so that a
			 * clock skew between the nodes writing the journal stays out of the order it is read by.
			 */
			new DatetimeField('CREATED_AT'),
			/**
			 * Written at publication. It is not restored from the current state of an object on read.
			 */
			(new TextField('CHANGED_FIELDS'))
				->configureNullable()
			,
			(new StringField('TOMBSTONE_REASON'))
				->configureNullable()
				->configureSize(32)
			,
			(new StringField('ACCESS_SCOPE'))
				->configureNullable()
				->configureSize(16)
			,
			(new IntegerField('SCOPE_OBJECT_ID'))
				->configureNullable()
			,
			(new BooleanField('AFFECTS_DESCENDANTS'))
				->configureDefaultValue(0)
				->configureStorageValues(0, 1)
			,
		];
	}
}
