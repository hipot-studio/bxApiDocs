<?php
namespace Bitrix\Tasks\Internals;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;
use Bitrix\Tasks\Internals\Task\SystemLogObject;

/**
 * Class SystemLogTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_SystemLog_Query query()
 * @method static EO_SystemLog_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_SystemLog_Result getById($id)
 * @method static EO_SystemLog_Result getList(array $parameters = [])
 * @method static EO_SystemLog_Entity getEntity()
 * @method static \Bitrix\Tasks\Internals\Task\SystemLogObject createObject($setDefaultValues = true)
 * @method static \Bitrix\Tasks\Internals\EO_SystemLog_Collection createCollection()
 * @method static \Bitrix\Tasks\Internals\Task\SystemLogObject wakeUpObject($row)
 * @method static \Bitrix\Tasks\Internals\EO_SystemLog_Collection wakeUpCollection($rows)
 */
class SystemLogTable extends DataManager
{
	use DeleteByFilterTrait;

	public const ENTITY_TYPE_TEMPLATE = 1;
	public const TYPE_MESSAGE = 1;
	public const TYPE_ERROR = 3;

	public static function getTableName(): string
	{
		return 'b_tasks_syslog';
	}

	public static function getClass(): string
	{
		return static::class;
	}

	public static function getObjectClass(): string
	{
		return SystemLogObject::class;
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),

			(new IntegerField('TYPE')),

			(new DatetimeField('CREATED_DATE')),

			(new StringField('MESSAGE'))
				->addValidator(new LengthValidator(null, 255)),

			(new IntegerField('ENTITY_ID')),

			(new IntegerField('ENTITY_TYPE')),

			(new IntegerField('PARAM_A')),

			(new TextField('ERROR')),
		];
	}
}
