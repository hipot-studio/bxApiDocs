<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Model;

use Bitrix\Main\ORM\Data\AddStrategy\Contract\AddStrategy;
use Bitrix\Main\ORM\Data\AddStrategy\InsertIgnore;
use Bitrix\Main\ORM\Data\AddStrategy\Trait\AddInsertIgnoreTrait;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\SystemException;

/**
 * Class SyncLogCodeTable
 *
 * An access code a record of the journal was published to. The set of codes of a record is fixed at
 * publication and is never rewritten afterwards.
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_SyncLogCode_Query query()
 * @method static EO_SyncLogCode_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_SyncLogCode_Result getById($id)
 * @method static EO_SyncLogCode_Result getList(array $parameters = [])
 * @method static EO_SyncLogCode_Entity getEntity()
 * @method static \Bitrix\Disk\Internal\Model\EO_SyncLogCode createObject($setDefaultValues = true)
 * @method static \Bitrix\Disk\Internal\Model\EO_SyncLogCode_Collection createCollection()
 * @method static \Bitrix\Disk\Internal\Model\EO_SyncLogCode wakeUpObject($row)
 * @method static \Bitrix\Disk\Internal\Model\EO_SyncLogCode_Collection wakeUpCollection($rows)
 */
class SyncLogCodeTable extends DataManager
{
	use AddInsertIgnoreTrait;

	public const ACCESS_CODE_LENGTH = 100;

	/**
	 * A code of a record is written once. Attaching the same code again - a retry of a publication that
	 * failed halfway through its codes - has to leave the row alone instead of multiplying it, so the
	 * conflict target is the pair and not the autoincrement primary key.
	 */
	protected static function getInsertIgnoreStrategy(): AddStrategy
	{
		return new InsertIgnore(static::getEntity(), ['LOG_ID', 'ACCESS_CODE']);
	}

	public static function getTableName(): string
	{
		return 'b_disk_sync_log_code';
	}

	/**
	 * @return array
	 * @throws SystemException
	 */
	public static function getMap(): array
	{
		return [
			// Both columns are wide ones, and the size has to be said out loud: an integer field
			// declared without it is a four-byte one, and the platform clamps what it writes to the
			// range of that size - so a journal that outgrew a signed int would go on storing the same
			// last value for every record instead of failing.
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
				->configureSize(8)
			,
			(new IntegerField('LOG_ID'))
				->configureRequired()
				->configureSize(8)
			,
			(new StringField('ACCESS_CODE'))
				->configureRequired()
				->configureSize(self::ACCESS_CODE_LENGTH)
			,
		];
	}
}
