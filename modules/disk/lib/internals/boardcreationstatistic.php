<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internals;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DateField;
use Bitrix\Main\ORM\Fields\IntegerField;

/**
 * Class BoardCreationStatisticTable
 *
 * Monthly number of boards created by a user. One row per user and calendar month, the month stored as
 * its first day; the pair is unique and is the conflict target of the counter upsert.
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_BoardCreationStatistic_Query query()
 * @method static EO_BoardCreationStatistic_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_BoardCreationStatistic_Result getById($id)
 * @method static EO_BoardCreationStatistic_Result getList(array $parameters = [])
 * @method static EO_BoardCreationStatistic_Entity getEntity()
 * @method static \Bitrix\Disk\Internals\EO_BoardCreationStatistic createObject($setDefaultValues = true)
 * @method static \Bitrix\Disk\Internals\EO_BoardCreationStatistic_Collection createCollection()
 * @method static \Bitrix\Disk\Internals\EO_BoardCreationStatistic wakeUpObject($row)
 * @method static \Bitrix\Disk\Internals\EO_BoardCreationStatistic_Collection wakeUpCollection($rows)
 */
final class BoardCreationStatisticTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_disk_board_creation_stat';
	}

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
			(new DateField('CREATED_MONTH'))
				->configureRequired()
			,
			(new IntegerField('BOARD_COUNT'))
				->configureRequired()
				->configureDefaultValue(0)
			,
		];
	}
}
