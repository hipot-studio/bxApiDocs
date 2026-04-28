<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Model\Trigger;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\ArrayField;
use Bitrix\Main\ORM;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\ORM\Query\Query;

/**
 * Class TriggerScheduleTable
 *
 * Fields:
 * <ul>
 * <li> ID int mandatory
 * <li> TEMPLATE_ID int mandatory
 * <li> TRIGGER_NAME string(128) mandatory
 * <li> TRIGGER_TYPE string(128) mandatory
 * <li> SCHEDULE_TYPE string(16) mandatory
 * <li> SCHEDULE_DATA array mandatory
 * <li> NEXT_RUN_AT datetime optional
 * <li> LAST_RUN_AT datetime optional
 * <li> ACTIVE bool optional default 'Y'
 * <li> CREATED_AT datetime optional default current datetime
 * <li> UPDATED_AT datetime optional default current datetime
 * </ul>
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_TriggerSchedule_Query query()
 * @method static EO_TriggerSchedule_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_TriggerSchedule_Result getById($id)
 * @method static EO_TriggerSchedule_Result getList(array $parameters = [])
 * @method static EO_TriggerSchedule_Entity getEntity()
 * @method static \Bitrix\Bizproc\Internal\Model\Trigger\EO_TriggerSchedule createObject($setDefaultValues = true)
 * @method static \Bitrix\Bizproc\Internal\Model\Trigger\EO_TriggerSchedule_Collection createCollection()
 * @method static \Bitrix\Bizproc\Internal\Model\Trigger\EO_TriggerSchedule wakeUpObject($row)
 * @method static \Bitrix\Bizproc\Internal\Model\Trigger\EO_TriggerSchedule_Collection wakeUpCollection($rows)
 */
class TriggerScheduleTable extends DataManager
{
	use ORM\Data\Internal\DeleteByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_bp_workflow_trigger_schedule';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),
			(new IntegerField('TEMPLATE_ID'))
				->configureRequired(),
			(new StringField('TRIGGER_NAME'))
				->configureRequired()
				->addValidator(new ORM\Fields\Validators\LengthValidator(1, 128)),
			(new StringField('SCHEDULE_TYPE'))
				->configureRequired()
				->addValidator(new ORM\Fields\Validators\LengthValidator(1, 16)),
			(new ArrayField('SCHEDULE_DATA'))
				->configureRequired(),
			(new DatetimeField('NEXT_RUN_AT'))
				->configureNullable(),
			(new DatetimeField('LAST_RUN_AT'))
				->configureNullable(),
			(new DatetimeField('CREATED_AT'))
				->configureDefaultValue(static fn() => new DateTime()),
			(new DatetimeField('UPDATED_AT'))
				->configureDefaultValue(static fn() => new DateTime()),
		];
	}

	/**
	 * @param array $filter
	 * @param array $fields
	 *
	 * @return int
	 * @throws ArgumentException
	 * @throws SqlQueryException
	 * @throws SystemException
	 */
	public static function updateByFilter(array $filter, array $fields): int
	{
		$entity = static::getEntity();
		$sqlHelper = $entity->getConnection()->getSqlHelper();
		$update = $sqlHelper->prepareUpdate(static::getTableName(), $fields);
		$where = Query::buildFilterSql($entity, $filter);

		if ($where !== '' && $update[0] !== '')
		{
			$sql = "UPDATE " . static::getTableName() . " SET {$update[0]} WHERE {$where}";
			$entity->getConnection()->queryExecute($sql);
			return $entity->getConnection()->getAffectedRowsCount();
		}

		return 0;
	}
}
