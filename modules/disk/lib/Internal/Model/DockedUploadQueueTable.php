<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Model;

use Bitrix\Disk\Internal\Enum\DockedUpload\QueueStatus;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\EnumField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\SystemException;

/**
 * Class DockedUploadQueueTable
 *
 * Storage for a docked upload session (one user upload queue).
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_DockedUploadQueue_Query query()
 * @method static EO_DockedUploadQueue_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_DockedUploadQueue_Result getById($id)
 * @method static EO_DockedUploadQueue_Result getList(array $parameters = [])
 * @method static EO_DockedUploadQueue_Entity getEntity()
 * @method static \Bitrix\Disk\Internal\Model\EO_DockedUploadQueue createObject($setDefaultValues = true)
 * @method static \Bitrix\Disk\Internal\Model\EO_DockedUploadQueue_Collection createCollection()
 * @method static \Bitrix\Disk\Internal\Model\EO_DockedUploadQueue wakeUpObject($row)
 * @method static \Bitrix\Disk\Internal\Model\EO_DockedUploadQueue_Collection wakeUpCollection($rows)
 */
class DockedUploadQueueTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_disk_docked_upload_queue';
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
			(new EnumField('STATUS'))
				->configureRequired()
				->configureValues(array_column(QueueStatus::cases(), 'value'))
			,
			(new DatetimeField('CREATE_TIME'))
				->configureRequired()
				->configureDefaultValueNow()
			,
			(new DatetimeField('UPDATE_TIME'))
				->configureRequired()
				->configureDefaultValueNow()
			,
			(new DatetimeField('EXPIRES_AT'))
				->configureRequired()
			,
			(new DatetimeField('NEXT_CLEANUP_AT')),
		];
	}
}
