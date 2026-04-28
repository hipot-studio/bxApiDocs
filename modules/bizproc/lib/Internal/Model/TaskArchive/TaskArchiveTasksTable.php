<?php
namespace Bitrix\Bizproc\Internal\Model\TaskArchive;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Query\Join;

/**
 * Class TaskArchiveTasksTable
 *
 * Fields:
 * <ul>
 * <li> ID int mandatory
 * <li> WORKFLOW_ID string(32) mandatory
 * <li> TASK_ID int mandatory
 * </ul>
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_TaskArchiveTasks_Query query()
 * @method static EO_TaskArchiveTasks_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_TaskArchiveTasks_Result getById($id)
 * @method static EO_TaskArchiveTasks_Result getList(array $parameters = [])
 * @method static EO_TaskArchiveTasks_Entity getEntity()
 * @method static \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks createObject($setDefaultValues = true)
 * @method static \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks_Collection createCollection()
 * @method static \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks wakeUpObject($row)
 * @method static \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchiveTasks_Collection wakeUpCollection($rows)
 */

class TaskArchiveTasksTable extends DataManager
{
	use DeleteByFilterTrait;
	/**
	 * Returns DB table name for entity.
	 *
	 * @return string
	 */
	public static function getTableName()
	{
		return 'b_bp_task_archive_tasks';
	}

	/**
	 * Returns entity map definition.
	 *
	 * @return array
	 */
	public static function getMap()
	{
		return [
			(new IntegerField('ID',
				[]
			))
				->configurePrimary(true)
				->configureAutocomplete(true)
			,
			(new IntegerField('ARCHIVE_ID',
				[]
			))
				->configureRequired(true)
			,
			(new IntegerField('TASK_ID',
				[]
			))
				->configureRequired(true)
			,
			(new DatetimeField('COMPLETED_AT',
				[]
			))
				->configureRequired(true)
			,
			new Reference(
				'ARCHIVE',
				TaskArchiveTable::class,
				Join::on('this.ARCHIVE_ID', 'ref.ID')
			),
		];
	}
}
