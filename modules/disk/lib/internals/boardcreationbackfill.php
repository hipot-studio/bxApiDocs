<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internals;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\EnumField;
use Bitrix\Main\ORM\Fields\IntegerField;

/**
 * Class BoardCreationBackfillTable
 *
 * The single row of the backfill of the board creation statistics: the fixed border between the backfill
 * and the live tracking (MAX_OBJECT_ID) with the moment it was fixed, the first object found not older
 * than the tracked period, the cursor of the next portion and the status of the run.
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_BoardCreationBackfill_Query query()
 * @method static EO_BoardCreationBackfill_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_BoardCreationBackfill_Result getById($id)
 * @method static EO_BoardCreationBackfill_Result getList(array $parameters = [])
 * @method static EO_BoardCreationBackfill_Entity getEntity()
 * @method static \Bitrix\Disk\Internals\EO_BoardCreationBackfill createObject($setDefaultValues = true)
 * @method static \Bitrix\Disk\Internals\EO_BoardCreationBackfill_Collection createCollection()
 * @method static \Bitrix\Disk\Internals\EO_BoardCreationBackfill wakeUpObject($row)
 * @method static \Bitrix\Disk\Internals\EO_BoardCreationBackfill_Collection wakeUpCollection($rows)
 */
final class BoardCreationBackfillTable extends DataManager
{
	public const STATE_ID = 1;

	public const STATUS_IN_PROGRESS = 'P';
	public const STATUS_COMPLETED = 'C';

	public static function getTableName(): string
	{
		return 'b_disk_board_creation_backfill';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
			,
			(new IntegerField('MAX_OBJECT_ID'))
				->configureRequired()
			,
			(new DatetimeField('MAX_OBJECT_FIXED_AT'))
				->configureNullable()
			,
			(new IntegerField('START_OBJECT_ID'))
				->configureNullable()
			,
			(new IntegerField('NEXT_OBJECT_ID'))
				->configureRequired()
				->configureDefaultValue(0)
			,
			(new EnumField('STATUS'))
				->configureRequired()
				->configureValues([self::STATUS_IN_PROGRESS, self::STATUS_COMPLETED])
				->configureDefaultValue(self::STATUS_IN_PROGRESS)
			,
		];
	}
}
