<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Model;

use Bitrix\Main\ORM\Data\AddStrategy\Contract\AddStrategy;
use Bitrix\Main\ORM\Data\AddStrategy\InsertIgnore;
use Bitrix\Main\ORM\Data\AddStrategy\Trait\AddInsertIgnoreTrait;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;
use Bitrix\Main\SystemException;

/**
 * Class SyncAccessSnapshotTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_SyncAccessSnapshot_Query query()
 * @method static EO_SyncAccessSnapshot_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_SyncAccessSnapshot_Result getById($id)
 * @method static EO_SyncAccessSnapshot_Result getList(array $parameters = [])
 * @method static EO_SyncAccessSnapshot_Entity getEntity()
 * @method static \Bitrix\Disk\Internal\Model\EO_SyncAccessSnapshot createObject($setDefaultValues = true)
 * @method static \Bitrix\Disk\Internal\Model\EO_SyncAccessSnapshot_Collection createCollection()
 * @method static \Bitrix\Disk\Internal\Model\EO_SyncAccessSnapshot wakeUpObject($row)
 * @method static \Bitrix\Disk\Internal\Model\EO_SyncAccessSnapshot_Collection wakeUpCollection($rows)
 */
class SyncAccessSnapshotTable extends DataManager
{
	use AddInsertIgnoreTrait;

	/**
	 * The snapshot of a pair outlives its sessions and carries a revision already handed out, so a write
	 * that loses the race to a parallel first read has to leave the winner alone instead of merging over
	 * it. The conflict target is the pair, not the autoincrement primary key.
	 */
	protected static function getInsertIgnoreStrategy(): AddStrategy
	{
		return new InsertIgnore(static::getEntity(), ['USER_ID', 'APP_ID']);
	}

	public static function getTableName(): string
	{
		return 'b_disk_sync_access_snapshot';
	}

	/**
	 * @return array
	 * @throws SystemException
	 */
	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,
			(new IntegerField('USER_ID'))
				->configureRequired()
			,
			(new StringField('APP_ID'))
				->configureRequired()
				->configureSize(128)
			,
			/**
			 * The conditional transition of parallel reads rests on this counter, so it is written only
			 * by a compare-and-set and never by a blind update.
			 */
			(new IntegerField('REVISION'))
				->configureRequired()
				->configureDefaultValue(0)
			,
			(new StringField('CODES_HASH'))
				->configureRequired()
				->configureSize(64)
			,
			new TextField('CODES'),
			(new DatetimeField('UPDATED_AT'))
				->configureRequired()
			,
		];
	}
}
