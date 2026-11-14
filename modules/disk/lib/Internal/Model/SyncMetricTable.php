<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\SystemException;

/**
 * Aggregates of the metrics of the sync contour: one row per hour, name, label, application, outcome and
 * bucket of time. The row of the running total lives in the window TOTAL_WINDOW.
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_SyncMetric_Query query()
 * @method static EO_SyncMetric_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_SyncMetric_Result getList(array $parameters = [])
 * @method static EO_SyncMetric_Entity getEntity()
 * @method static \Bitrix\Disk\Internal\Model\EO_SyncMetric createObject($setDefaultValues = true)
 * @method static \Bitrix\Disk\Internal\Model\EO_SyncMetric_Collection createCollection()
 * @method static \Bitrix\Disk\Internal\Model\EO_SyncMetric wakeUpObject($row)
 * @method static \Bitrix\Disk\Internal\Model\EO_SyncMetric_Collection wakeUpCollection($rows)
 */
class SyncMetricTable extends DataManager
{
	public const TOTAL_WINDOW = '2000-01-01 00:00:00';

	public static function getTableName(): string
	{
		return 'b_disk_sync_metric';
	}

	/**
	 * @return array
	 * @throws SystemException
	 */
	public static function getMap(): array
	{
		return [
			(new DatetimeField('WINDOW_START'))
				->configurePrimary()
			,
			(new StringField('NAME'))
				->configurePrimary()
				->configureSize(16)
			,
			(new StringField('LABEL'))
				->configurePrimary()
				->configureSize(48)
			,
			/**
			 * md5 of APP_ID: the application itself is too wide for the key.
			 */
			(new StringField('APP_HASH'))
				->configurePrimary()
				->configureSize(32)
			,
			(new StringField('OUTCOME'))
				->configurePrimary()
				->configureSize(48)
			,
			(new IntegerField('BUCKET_MS'))
				->configurePrimary()
			,
			(new StringField('APP_ID'))
				->configureSize(128)
				->configureDefaultValue('')
			,
			(new IntegerField('CNT'))
				->configureSize(8)
				->configureDefaultValue(0)
			,
			(new IntegerField('SUM_MS'))
				->configureSize(8)
				->configureDefaultValue(0)
			,
			(new IntegerField('SUM_BYTES'))
				->configureSize(8)
				->configureDefaultValue(0)
			,
			(new IntegerField('VALUE'))
				->configureSize(8)
				->configureNullable()
			,
		];
	}
}
