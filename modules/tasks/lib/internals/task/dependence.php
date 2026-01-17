<?php

namespace Bitrix\Tasks\Internals\Task;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\IntegerField;

/**
 * Class DependenceTable
 *
 * Fields:
 * <ul>
 * <li> TASK_ID int mandatory
 * <li> PARENT_TASK_ID int mandatory
 * <li> DIRECT int optional
 * </ul>
 *
 * @package Bitrix\Tasks
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_Dependence_Query query()
 * @method static EO_Dependence_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_Dependence_Result getById($id)
 * @method static EO_Dependence_Result getList(array $parameters = [])
 * @method static EO_Dependence_Entity getEntity()
 * @method static \Bitrix\Tasks\Internals\Task\EO_Dependence createObject($setDefaultValues = true)
 * @method static \Bitrix\Tasks\Internals\Task\EO_Dependence_Collection createCollection()
 * @method static \Bitrix\Tasks\Internals\Task\EO_Dependence wakeUpObject($row)
 * @method static \Bitrix\Tasks\Internals\Task\EO_Dependence_Collection wakeUpCollection($rows)
 */

class DependenceTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_tasks_task_dep';
	}

	public static function getClass(): string
	{
		return static::class;
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('TASK_ID'))
				->configurePrimary(),

			(new IntegerField('PARENT_TASK_ID'))
				->configurePrimary(),

			(new IntegerField('DIRECT'))
				->configureNullable()
				->configureDefaultValue(0),
		];
	}
}
