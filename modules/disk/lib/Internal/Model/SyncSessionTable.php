<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Model;

use Bitrix\Main\ORM\Data\AddStrategy\Contract\AddStrategy;
use Bitrix\Main\ORM\Data\AddStrategy\Merge;
use Bitrix\Main\ORM\Data\AddStrategy\Trait\AddMergeTrait;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;
use Bitrix\Main\SystemException;

/**
 * Class SyncSessionTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_SyncSession_Query query()
 * @method static EO_SyncSession_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_SyncSession_Result getById($id)
 * @method static EO_SyncSession_Result getList(array $parameters = [])
 * @method static EO_SyncSession_Entity getEntity()
 * @method static \Bitrix\Disk\Internal\Model\EO_SyncSession createObject($setDefaultValues = true)
 * @method static \Bitrix\Disk\Internal\Model\EO_SyncSession_Collection createCollection()
 * @method static \Bitrix\Disk\Internal\Model\EO_SyncSession wakeUpObject($row)
 * @method static \Bitrix\Disk\Internal\Model\EO_SyncSession_Collection wakeUpCollection($rows)
 */
class SyncSessionTable extends DataManager
{
	use AddMergeTrait;

	/**
	 * A pair of a user and an application owns at most one live session, so the conflict target is the
	 * pair and not the autoincrement primary key: writing a session over its predecessor is what makes
	 * the snapshot token of the previous cycle stop being accepted.
	 */
	protected static function getMergeStrategy(): AddStrategy
	{
		return new Merge(static::getEntity(), ['USER_ID', 'APP_ID']);
	}

	public static function getTableName(): string
	{
		return 'b_disk_sync_session';
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
			(new DatetimeField('CREATED_AT'))
				->configureRequired()
			,
			(new DatetimeField('SNAPSHOT_TOKEN_EXPIRES_AT'))
				->configureRequired()
			,
			/**
			 * The two phases of a cycle are bounded by different keys - the modification time of an
			 * object and the interaction time of the user - so one bound cannot describe both.
			 */
			(new DatetimeField('BOUND_MODIFIED_AT'))
				->configureRequired()
			,
			(new DatetimeField('BOUND_INTERACTED_AT'))
				->configureRequired()
			,
			(new IntegerField('EFFECTIVE_DEPTH_DAYS'))
				->configureRequired()
			,
			(new IntegerField('PLANNED_POLL_INTERVAL'))
				->configureRequired()
			,
			new TextField('STORAGE_IDS'),
			/**
			 * The checkpoints of visibility in storages of other people, grouped by storage rather than
			 * listed flat: the stream of what is shared needs both halves - the storages as the units it
			 * merges by, and the checkpoints inside each as what narrows the walk to their subtrees.
			 */
			new TextField('CHECKPOINTS_BY_STORAGE'),
		];
	}
}
