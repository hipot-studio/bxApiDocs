<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Model;

use Bitrix\Disk\Internal\Enum\DockedUpload\ConflictPolicy;
use Bitrix\Disk\Internal\Enum\DockedUpload\ItemStatus;
use Bitrix\Disk\Internal\Enum\DockedUpload\ItemType;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\EnumField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\SystemException;

/**
 * Class DockedUploadItemTable
 *
 * Storage for a single docked upload element (a file or a service folder-item).
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_DockedUploadItem_Query query()
 * @method static EO_DockedUploadItem_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_DockedUploadItem_Result getById($id)
 * @method static EO_DockedUploadItem_Result getList(array $parameters = [])
 * @method static EO_DockedUploadItem_Entity getEntity()
 * @method static \Bitrix\Disk\Internal\Model\EO_DockedUploadItem createObject($setDefaultValues = true)
 * @method static \Bitrix\Disk\Internal\Model\EO_DockedUploadItem_Collection createCollection()
 * @method static \Bitrix\Disk\Internal\Model\EO_DockedUploadItem wakeUpObject($row)
 * @method static \Bitrix\Disk\Internal\Model\EO_DockedUploadItem_Collection wakeUpCollection($rows)
 */
class DockedUploadItemTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_disk_docked_upload_item';
	}

	/**
	 * Whether a value is wider than the column it is about to be written into.
	 *
	 * The write paths that go through the ORM object are already safe - StringField::cast()
	 * truncates on every setter - but the guarded CAS updates build their SQL by hand, and
	 * there an overlong value reaches the driver as it is: a silent truncation without
	 * strict mode, and an exception (MySQL 1406, PostgreSQL 22001) with it. The cap is read
	 * off the map so it cannot drift from the column; a field declared without a size has
	 * no cap to enforce.
	 *
	 * Measured in characters, like varchar(N) itself on both engines - counting bytes would
	 * refuse a Cyrillic name at half the real limit.
	 */
	public static function exceedsColumnWidth(string $fieldName, string $value): bool
	{
		$maxLength = (int)self::getEntity()->getField($fieldName)->getSize();

		return $maxLength > 0 && mb_strlen($value) > $maxLength;
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
			(new IntegerField('QUEUE_ID'))
				->configureRequired()
			,
			(new IntegerField('PARENT_ITEM_ID'))
				->configureNullable()
			,
			(new EnumField('ITEM_TYPE'))
				->configureRequired()
				->configureValues(array_column(ItemType::cases(), 'value'))
			,
			(new EnumField('STATUS'))
				->configureRequired()
				->configureValues(array_column(ItemStatus::cases(), 'value'))
			,
			(new StringField('NAME'))
				->configureRequired()
				->configureSize(255)
			,
			(new StringField('RELATIVE_PATH'))
				->configureNullable()
				->configureSize(1000)
			,
			(new IntegerField('SIZE'))
				->configureNullable()
			,
			(new IntegerField('TARGET_FOLDER_ID'))
				->configureNullable()
			,
			(new IntegerField('TARGET_FILE_ID'))
				->configureNullable()
			,
			(new IntegerField('STORAGE_ID'))
				->configureNullable()
			,
			(new EnumField('CONFLICT_POLICY'))
				->configureNullable()
				->configureValues(array_column(ConflictPolicy::cases(), 'value'))
			,
			(new StringField('TEMP_TOKEN'))
				->configureNullable()
				->configureSize(255)
			,
			(new StringField('INTENT_TOKEN'))
				->configureNullable()
				->configureSize(64)
			,
			(new IntegerField('DISK_OBJECT_ID'))
				->configureNullable()
			,
			(new StringField('ERROR_CODE'))
				->configureNullable()
				->configureSize(64)
			,
			(new StringField('OWNER_CLIENT_ID'))
				->configureNullable()
				->configureSize(64)
			,
			(new DatetimeField('OWNER_HEARTBEAT_AT'))
				->configureNullable()
			,
			(new DatetimeField('CREATE_TIME'))
				->configureRequired()
				->configureDefaultValueNow()
			,
			(new DatetimeField('UPDATE_TIME'))
				->configureRequired()
				->configureDefaultValueNow()
			,
			(new Reference(
				'QUEUE',
				DockedUploadQueueTable::class,
				Join::on('this.QUEUE_ID', 'ref.ID'),
			))->configureJoinType(Join::TYPE_INNER),
			(new Reference(
				'PARENT_ITEM',
				DockedUploadItemTable::class,
				Join::on('this.PARENT_ITEM_ID', 'ref.ID'),
			))->configureJoinType(Join::TYPE_LEFT),
		];
	}
}
