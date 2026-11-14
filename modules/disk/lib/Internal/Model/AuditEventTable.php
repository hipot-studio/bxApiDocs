<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;

/**
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_AuditEvent_Query query()
 * @method static EO_AuditEvent_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_AuditEvent_Result getById($id)
 * @method static EO_AuditEvent_Result getList(array $parameters = [])
 * @method static EO_AuditEvent_Entity getEntity()
 * @method static \Bitrix\Disk\Internal\Model\EO_AuditEvent createObject($setDefaultValues = true)
 * @method static \Bitrix\Disk\Internal\Model\EO_AuditEvent_Collection createCollection()
 * @method static \Bitrix\Disk\Internal\Model\EO_AuditEvent wakeUpObject($row)
 * @method static \Bitrix\Disk\Internal\Model\EO_AuditEvent_Collection wakeUpCollection($rows)
 */
class AuditEventTable extends DataManager
{
	use DeleteByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_disk_audit_event';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configureSize(8)
				->configurePrimary()
				->configureAutocomplete()
			,
			(new StringField('IDEMPOTENCY_KEY'))
				->configureRequired()
				->configureUnique()
				->configureSize(64)
			,
			(new StringField('EVENT_CODE'))
				->configureRequired()
				->configureSize(100)
			,
			(new StringField('RESULT'))
				->configureRequired()
				->configureDefaultValue('success')
				->configureSize(20)
			,
			(new DatetimeField('OCCURRED_AT'))
				->configureRequired()
			,
			(new DatetimeField('CREATED_AT'))
				->configureRequired()
			,
			(new IntegerField('STORAGE_ID'))
				->configureNullable()
			,
			(new StringField('STORAGE_NAME'))
				->configureNullable()
				->configureSize(255)
			,
			(new StringField('INITIATOR_TYPE'))
				->configureRequired()
				->configureSize(50)
			,
			(new StringField('INITIATOR_ID'))
				->configureRequired()
				->configureSize(64)
			,
			(new StringField('INITIATOR_NAME'))
				->configureRequired()
				->configureSize(255)
			,
			(new StringField('IP'))
				->configureNullable()
				->configureSize(45)
			,
			(new StringField('CHANNEL'))
				->configureRequired()
				->configureSize(50)
			,
			(new StringField('CONTEXT'))
				->configureRequired()
				->configureSize(50)
			,
			(new StringField('CONTEXT_ENTITY_TYPE'))
				->configureNullable()
				->configureSize(50)
			,
			(new StringField('CONTEXT_ENTITY_ID'))
				->configureNullable()
				->configureSize(64)
			,
			(new StringField('CONTEXT_ENTITY_NAME'))
				->configureNullable()
				->configureSize(255)
			,
			(new StringField('TARGET_TYPE'))
				->configureRequired()
				->configureSize(50)
			,
			(new StringField('TARGET_ID'))
				->configureRequired()
				->configureSize(64)
			,
			(new StringField('TARGET_NAME'))
				->configureRequired()
				->configureSize(255)
			,
			(new StringField('TARGET_NAME_SEARCH'))
				->configureRequired()
				->configureSize(255)
			,
			(new TextField('TARGET_PATH'))
				->configureNullable()
			,
			(new StringField('TARGET_PARENT_ID'))
				->configureNullable()
				->configureSize(64)
			,
			(new StringField('SUBJECT_TYPE'))
				->configureNullable()
				->configureSize(50)
			,
			(new StringField('SUBJECT_ID'))
				->configureNullable()
				->configureSize(64)
			,
			(new StringField('SUBJECT_NAME'))
				->configureNullable()
				->configureSize(255)
			,
			(new TextField('DETAILS'))
				->configureNullable()
			,
		];
	}
}
