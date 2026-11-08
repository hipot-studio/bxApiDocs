<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Model;

use Bitrix\Main\ORM\Data\AddStrategy\Contract\AddStrategy;
use Bitrix\Main\ORM\Data\AddStrategy\InsertIgnore;
use Bitrix\Main\ORM\Data\AddStrategy\Trait\AddInsertIgnoreTrait;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\TextField;
use Bitrix\Main\SystemException;

/**
 * Class SyncRightsSnapshotTable
 *
 * The access codes the root of an edit of rights carried before that edit, kept beside the session that
 * recalculates the materialized rights.
 *
 * It is a different entity from the snapshot of a pair {@see SyncAccessSnapshotTable}, with a different
 * life: this one belongs to a single recalculation and leaves with it, while an orphan left by an
 * interrupted recalculation is taken by age.
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_SyncRightsSnapshot_Query query()
 * @method static EO_SyncRightsSnapshot_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_SyncRightsSnapshot_Result getById($id)
 * @method static EO_SyncRightsSnapshot_Result getList(array $parameters = [])
 * @method static EO_SyncRightsSnapshot_Entity getEntity()
 * @method static \Bitrix\Disk\Internal\Model\EO_SyncRightsSnapshot createObject($setDefaultValues = true)
 * @method static \Bitrix\Disk\Internal\Model\EO_SyncRightsSnapshot_Collection createCollection()
 * @method static \Bitrix\Disk\Internal\Model\EO_SyncRightsSnapshot wakeUpObject($row)
 * @method static \Bitrix\Disk\Internal\Model\EO_SyncRightsSnapshot_Collection wakeUpCollection($rows)
 */
class SyncRightsSnapshotTable extends DataManager
{
	use AddInsertIgnoreTrait;

	/**
	 * The state before an edit is written once and is not restorable afterwards, so a repeated write for
	 * the same session has to leave the first one alone: by then the rights already hold the state after
	 * the edit, and an overwrite would store that as the state before it. The conflict target is the
	 * session and not the autoincrement primary key.
	 */
	protected static function getInsertIgnoreStrategy(): AddStrategy
	{
		return new InsertIgnore(static::getEntity(), ['SETUP_SESSION_ID']);
	}

	public static function getTableName(): string
	{
		return 'b_disk_sync_rights_snapshot';
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
			(new IntegerField('SETUP_SESSION_ID'))
				->configureRequired()
			,
			(new IntegerField('OBJECT_ID'))
				->configureRequired()
			,
			new TextField('CODES'),
			/**
			 * The age an orphan is taken by: a recalculation that never finished leaves its snapshot
			 * behind, and nothing else marks it as abandoned.
			 */
			(new DatetimeField('CREATED_AT'))
				->configureRequired()
			,
		];
	}
}
