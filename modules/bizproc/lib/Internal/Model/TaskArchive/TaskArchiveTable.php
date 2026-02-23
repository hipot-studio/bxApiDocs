<?php
namespace Bitrix\Bizproc\Internal\Model\TaskArchive;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;
use Bitrix\Main\ORM\Query\Join;

/**
 * Class TaskArchiveTable
 *
 * Fields:TaskArchiveTable
 * <ul>
 * <li> ID int mandatory
 * <li> WORKFLOW_ID string(32) mandatory
 * <li> TASKS_DATA text mandatory
 * </ul>
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_TaskArchive_Query query()
 * @method static EO_TaskArchive_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_TaskArchive_Result getById($id)
 * @method static EO_TaskArchive_Result getList(array $parameters = [])
 * @method static EO_TaskArchive_Entity getEntity()
 * @method static \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchive createObject($setDefaultValues = true)
 * @method static \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchive_Collection createCollection()
 * @method static \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchive wakeUpObject($row)
 * @method static \Bitrix\Bizproc\Internal\Model\TaskArchive\EO_TaskArchive_Collection wakeUpCollection($rows)
 */

class TaskArchiveTable extends DataManager
{
	/**
	 * Returns DB table name for entity.
	 *
	 * @return string
	 */
	public static function getTableName()
	{
		return 'b_bp_task_archive';
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
			(new StringField('WORKFLOW_ID',
				[
					'validation' => function()
					{
						return[
							new LengthValidator(null, 32),
						];
					},
				]
			))
				->configureRequired(true)
			,
			(new TextField('TASKS_DATA',
				[]
			))
				->configureRequired(true)
			,
			new Reference(
				'TASKS',
				TaskArchiveTasksTable::class,
				Join::on('this.ID', 'ref.ARCHIVE_ID')
			),
		];
	}
}
